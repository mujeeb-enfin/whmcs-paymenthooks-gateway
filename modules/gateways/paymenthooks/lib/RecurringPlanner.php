<?php
/**
 * Decides what "Subscribe & Auto-pay" does for one invoice. Pure PHP (no
 * WHMCS calls) so every rule is unit-tested in tests/run.php; InvoicePlan
 * loads the rows from WHMCS.
 *
 * Input rows are the invoice lines, amounts in minor units INCLUDING the
 * WHMCS tax on that line (InvoicePlan allocates the invoice tax exactly):
 *
 *   type        WHMCS tblinvoiceitems.type (Hosting, Domain, DomainRegister,
 *               DomainTransfer, Addon, Setup, ...)
 *   relType     hosting | domain | addon | '' (what the line renews)
 *   relId       tblhosting / tbldomains / tblhostingaddons id, or 0
 *   description invoice line text
 *   amountMinor what this invoice charges for the line (this period; a promo
 *               line of the same item is already netted in by InvoicePlan)
 *   recurring   null, or {interval, intervalCount, renewalMinor}: what the
 *               item renews at and how often (renewalMinor incl. tax)
 *   subscribed  the item already renews through a PaymentHooks subscription
 *   periodStart the item's WHMCS next due date (Y-m-d) before this invoice is
 *               paid, i.e. the first day of the period this invoice pays
 *   lineDueDate tblinvoiceitems.duedate of the line (Y-m-d), '' if unknown
 *
 * Rules (README "Recurring billing"):
 *  1. Every eligible recurring item on the invoice is subscribed in ONE
 *     checkout. PaymentHooks groups the recurring lines by (interval,
 *     intervalCount, startsAt): one subscription per group, one payment now,
 *     one mandate. Monthly hosting and a yearly domain are two subscriptions.
 *  2. Full price (this period = renewal): the line is sent at its renewal
 *     price with `recurring` and no startsAt; the subscription's first charge
 *     (today) pays this period. A first period dearer than renewal adds the
 *     difference as a one-time line.
 *  3. Discounted first period (promotion, first-year domain discount): this
 *     period's actual amount is a one-time line, and the item is a recurring
 *     line at its renewal price with `recurring.startsAt` = the day this
 *     period ends = periodStart + one cycle, which is the next due date WHMCS
 *     sets when the invoice is paid. The first renewal is charged then.
 *  4. Setup fees, one-off items and items already subscribed are one-time.
 *  5. The lines charged now must add up to the balance exactly, or Subscribe
 *     is not offered (the client still has Pay Now) and the reason says why.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class RecurringPlanner
{
    /** Why Subscribe is not offered, as shown to the client (plain language). */
    const REASONS = [
        'no_balance'         => 'This invoice has nothing left to pay.',
        'nothing_recurring'  => 'Nothing on this invoice renews, so there is nothing to put on auto-pay.',
        'already_subscribed' => 'The items on this invoice already renew automatically.',
        'free_renewal'       => 'The items on this invoice renew free of charge, so auto-pay is not needed.',
        'cycle_unsupported'  => 'The billing cycle of the items on this invoice cannot renew automatically.',
        'no_due_date'        => 'Auto-pay can start from the next renewal invoice of these items.',
        'due_date_changed'   => 'The due date of an item changed after this invoice was raised; auto-pay can start from its next renewal invoice.',
        'period_ended'       => 'The period this invoice pays for has already ended; auto-pay can start from the next renewal invoice.',
        'mixed'              => 'None of the items on this invoice can renew automatically.',
        'negative_line'      => 'This invoice has a discount or credit line that is not tied to an item, so auto-pay cannot be set up on it. Use Pay Now.',
        'part_paid'          => 'Part of this invoice has already been paid, so auto-pay cannot be set up on it. Pay the rest with Pay Now; auto-pay can start from the next renewal invoice.',
        'credit_applied'     => 'Account credit was applied to this invoice, so auto-pay cannot be set up on it. Pay the rest with Pay Now; auto-pay can start from the next renewal invoice.',
        'lines_mismatch'     => 'The invoice lines do not add up to the amount due, so auto-pay cannot be set up on it. Use Pay Now.',
    ];

    /** Why one line does not auto-renew, shown next to it in the "charged once" list. */
    const NOTES = [
        'already_subscribed' => 'already renews automatically',
        'free_renewal'       => 'renews free of charge',
        'cycle_unsupported'  => 'its billing cycle cannot renew automatically',
        'no_due_date'        => 'WHMCS has no due date for it; auto-pay can start from its next renewal invoice',
        'due_date_changed'   => 'its due date changed after this invoice was raised; auto-pay can start from its next renewal invoice',
        'period_ended'       => 'its period has already ended; auto-pay can start from its next renewal invoice',
    ];

    /**
     * @param array<int, array> $rows see class doc
     * @param array{today?:string, creditMinor?:int, paidMinor?:int} $context today (Y-m-d), credit applied
     *        to and payments already made on the invoice, in minor units
     * @return array{offer:bool, reasonCode:string, reason:string, detail:string, nowMinor:int,
     *               lineItems:array, groups:array, subscribed:array, oneTime:array}
     */
    public static function plan(array $rows, int $balanceMinor, array $context = []): array
    {
        $today = (string) ($context['today'] ?? date('Y-m-d'));
        $plan = [
            'offer' => false, 'reasonCode' => '', 'reason' => '', 'detail' => '', 'nowMinor' => 0,
            'lineItems' => [], 'groups' => [], 'subscribed' => [], 'oneTime' => [],
        ];
        if ($balanceMinor <= 0) {
            return self::refuse($plan, 'no_balance', '', $rows, []);
        }

        // Which rows may be subscribed (and from when), and why not otherwise.
        $notes = [];
        $codes = [];
        $eligible = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row['recurring'] ?? null) || !in_array($row['relType'] ?? '', ['hosting', 'domain', 'addon'], true)) {
                continue; // setup fees, one-off items: charged once
            }
            [$code, $startsAt] = self::eligibility($row, $today);
            if ($code !== '') {
                $codes[$i] = $code;
                $notes[$i] = self::NOTES[$code];
                continue;
            }
            [$interval, $count] = self::canonicalCadence((string) $row['recurring']['interval'], (int) $row['recurring']['intervalCount']);
            $eligible[$i] = [$interval, $count, $startsAt];
        }
        if (!$eligible) {
            $set = array_values(array_unique($codes));
            $code = !$set ? 'nothing_recurring' : (count($set) === 1 ? $set[0] : 'mixed');
            return self::refuse($plan, $code, '', $rows, $notes);
        }

        // Line items in invoice order; groups in order of their first item.
        $groups = [];
        $sum = 0;
        foreach ($rows as $i => $row) {
            $amount = (int) $row['amountMinor'];
            $description = self::clip((string) $row['description']);
            if (isset($eligible[$i])) {
                [$interval, $count, $startsAt] = $eligible[$i];
                $renewal = (int) $row['recurring']['renewalMinor'];
                $recurring = ['interval' => $interval, 'intervalCount' => $count] + ($startsAt !== null ? ['startsAt' => $startsAt] : []);
                $plan['lineItems'][] = ['description' => $description, 'quantity' => 1, 'unitPrice' => $renewal, 'recurring' => $recurring];
                if ($startsAt === null) {
                    // Charged now as the subscription's first charge (this period).
                    $sum += $renewal;
                    if ($amount > $renewal) {
                        $plan['lineItems'][] = ['description' => self::clip('First period: ' . $description), 'quantity' => 1, 'unitPrice' => $amount - $renewal];
                        $sum += $amount - $renewal;
                    }
                } elseif ($amount > 0) {
                    // This period charged once now; the recurring line starts at startsAt.
                    $plan['lineItems'][] = ['description' => self::clip('This period: ' . $description), 'quantity' => 1, 'unitPrice' => $amount];
                    $sum += $amount;
                    $notes[$i] = ($amount < $renewal ? 'this period at its discounted price' : 'this period')
                        . '; auto-pay renews it from ' . self::formatDate($startsAt);
                }
                $key = $interval . ':' . $count . ':' . ($startsAt ?? 'now');
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'interval' => $interval, 'intervalCount' => $count, 'startsAt' => $startsAt,
                        'firstRenewal' => $startsAt ?? self::addCycle($today, $interval, $count),
                        'renewalMinor' => 0, 'items' => [],
                    ];
                }
                $item = [
                    'relType' => (string) $row['relType'], 'relId' => (int) $row['relId'], 'description' => $description,
                    'renewalMinor' => $renewal, 'interval' => $interval, 'intervalCount' => $count, 'startsAt' => $startsAt,
                ];
                $groups[$key]['items'][] = $item;
                $groups[$key]['renewalMinor'] += $renewal;
                $plan['subscribed'][] = $item;
                continue;
            }
            if ($amount < 0) {
                return self::refuse($plan, 'negative_line', 'Line "' . $description . '" is ' . $amount . '.', $rows, $notes);
            }
            if ($amount > 0) {
                $plan['lineItems'][] = ['description' => $description, 'quantity' => 1, 'unitPrice' => $amount];
                $sum += $amount;
            }
        }

        if ($sum !== $balanceMinor) {
            $code = (int) ($context['creditMinor'] ?? 0) > 0 ? 'credit_applied' : ((int) ($context['paidMinor'] ?? 0) > 0 ? 'part_paid' : 'lines_mismatch');
            return self::refuse($plan, $code, 'Lines charged now total ' . $sum . ', the balance due is ' . $balanceMinor . '.', $rows, $notes);
        }
        $plan['offer'] = true;
        $plan['nowMinor'] = $sum;
        $plan['groups'] = array_values($groups);
        $plan['oneTime'] = self::oneTimeList($rows, $notes, $eligible);
        return $plan;
    }

    /**
     * ['', null] when the row joins a subscription starting now, ['', 'Y-m-d']
     * when it starts at the end of a discounted period, [code, null] when it
     * cannot be subscribed on this invoice.
     */
    private static function eligibility(array $row, string $today): array
    {
        $r = $row['recurring'];
        if (!empty($row['subscribed'])) {
            return ['already_subscribed', null];
        }
        if (!in_array($r['interval'] ?? '', ['day', 'week', 'month', 'year'], true) || (int) ($r['intervalCount'] ?? 0) < 1) {
            return ['cycle_unsupported', null];
        }
        if ((int) $r['renewalMinor'] <= 0) {
            return ['free_renewal', null];
        }
        $discounted = (int) $row['amountMinor'] < (int) $r['renewalMinor'];
        [$start, $startsAt] = self::period($row);
        if (!$discounted) {
            // Full price: the subscription's first charge (today) pays this period, as
            // in 3.1.0. Exception: a period that starts in the future (a renewal
            // invoice paid before its due date) is charged once now and the
            // subscription starts when that period ends. Started today, every
            // renewal would fall that many days before WHMCS's next due date and,
            // more than 7 days early, find no renewal invoice (Needs Attention).
            return ['', ($startsAt !== null && $start > $today) ? $startsAt : null];
        }
        if ($start === '') {
            return ['no_due_date', null];
        }
        if ($startsAt === null) {
            return ['due_date_changed', null];
        }
        if ($startsAt <= $today) {
            return ['period_ended', null];
        }
        return ['', $startsAt];
    }

    /**
     * [first day of the period this line pays, the day it ends] (Y-m-d), from
     * the invoice line's due date and the item's next due date:
     *   line due = next due          WHMCS has not moved the item on yet: the
     *                                period runs from it for one cycle;
     *   next due = line due + cycle  WHMCS already moved it on (it does so when
     *                                the invoice is paid; some installs set it
     *                                at order time): the period ends there;
     *   no line due date             the item's next due date starts the period.
     * Any other combination (a due date edited by hand) gives [start, null]:
     * the end is unknown and is not guessed. ['', null] without dates.
     */
    private static function period(array $row): array
    {
        $r = $row['recurring'];
        $next = (string) ($row['periodStart'] ?? '');
        $lineDue = (string) ($row['lineDueDate'] ?? '');
        $start = self::isDate($lineDue) ? $lineDue : $next;
        if (!self::isDate($start)) {
            return ['', null];
        }
        $end = self::addCycle($start, (string) $r['interval'], (int) $r['intervalCount']);
        if (!self::isDate($next) || $next === $start || $next === $end) {
            return [$start, $end];
        }
        return [$start, null];
    }

    /** Same cadence, one spelling: 12 months is a year. */
    public static function canonicalCadence(string $interval, int $count): array
    {
        if ($interval === 'month' && $count % 12 === 0) {
            return ['year', intdiv($count, 12)];
        }
        return [$interval, max(1, $count)];
    }

    /** "every month", "every 3 months", "every year", "every 2 years". */
    public static function cadenceLabel(string $interval, int $count): string
    {
        return $count > 1 ? 'every ' . $count . ' ' . $interval . 's' : 'every ' . $interval;
    }

    /**
     * Y-m-d plus count intervals. A month-end day that the target month lacks
     * becomes its last day (31 Jan + 1 month = 28/29 Feb), never the 3rd of
     * the month after.
     */
    public static function addCycle(string $ymd, string $interval, int $count): string
    {
        if (!self::isDate($ymd)) {
            throw new \InvalidArgumentException('Not a date: "' . $ymd . '".');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        if ($interval === 'day' || $interval === 'week') {
            $days = $interval === 'week' ? 7 * $count : $count;
            return gmdate('Y-m-d', gmmktime(0, 0, 0, $m, $d + $days, $y));
        }
        $months = ($interval === 'year' ? 12 : 1) * $count;
        $index = $m - 1 + $months;
        $ny = $y + intdiv($index, 12);
        $nm = $index % 12 + 1;
        $last = (int) gmdate('t', gmmktime(0, 0, 0, $nm, 1, $ny));
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $last));
    }

    /** "7 Nov 2027" */
    public static function formatDate(string $ymd): string
    {
        return self::isDate($ymd) ? gmdate('j M Y', (int) strtotime($ymd . ' 00:00:00 UTC')) : $ymd;
    }

    public static function isDate(string $s): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Lines charged once on this checkout, each with why it does not auto-renew ('' for plain one-off items). */
    private static function oneTimeList(array $rows, array $notes, array $eligible): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $amount = (int) $row['amountMinor'];
            // Subscribed rows appear here only for a discounted period charged once now.
            if ($amount === 0 || (isset($eligible[$i]) && $eligible[$i][2] === null)) {
                continue;
            }
            $out[] = ['description' => self::clip((string) $row['description']), 'amountMinor' => $amount, 'note' => $notes[$i] ?? ''];
        }
        return $out;
    }

    private static function refuse(array $plan, string $code, string $detail, array $rows, array $notes): array
    {
        $plan['offer'] = false;
        $plan['reasonCode'] = $code;
        $plan['reason'] = self::REASONS[$code];
        $plan['detail'] = $detail;
        $plan['lineItems'] = [];
        $plan['groups'] = [];
        $plan['subscribed'] = [];
        $plan['nowMinor'] = 0;
        $plan['oneTime'] = self::oneTimeList($rows, $notes, []);
        return $plan;
    }

    private static function clip(string $description): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $description));
        return function_exists('mb_substr') ? mb_substr($line, 0, 255) : substr($line, 0, 255);
    }
}
