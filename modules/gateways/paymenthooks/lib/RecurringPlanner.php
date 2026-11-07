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
 *               DomainTransfer, Addon, Setup, PromoHosting, ...)
 *   relType     hosting | domain | addon | '' (what the line renews)
 *   relId       tblhosting / tbldomains / tblhostingaddons id, or 0
 *   description invoice line text
 *   amountMinor what this invoice charges for the line (first period)
 *   recurring   null, or {interval, intervalCount, renewalMinor}: what the
 *               item renews at and how often (renewalMinor incl. tax)
 *   subscribed  the item already renews through a PaymentHooks subscription
 *
 * Rules (README "Recurring billing"):
 *  1. One subscription has one cadence (Stripe rule). Recurring items are
 *     grouped by cadence; the group holding the first hosting service is
 *     subscribed (no hosting: the first recurring item's group). Items of
 *     other cadences are charged once on the same checkout and renew from
 *     their WHMCS renewal invoice, where the client can subscribe them.
 *  2. A subscribed line is sent at its renewal price with `recurring`. When
 *     the first period costs more (setup fee in the line, registration above
 *     renewal) the difference is a one-time line on the first invoice.
 *  3. When the first period costs LESS than renewal (promotion, first-year
 *     domain discount), the item cannot be subscribed on this invoice: it is
 *     charged once and can be subscribed from its full-price renewal invoice.
 *  4. Setup fees, one-off items and items already subscribed are one-time.
 *  5. The lines must add up to the balance exactly, or Subscribe is not
 *     offered (the client still has Pay Now).
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class RecurringPlanner
{
    /**
     * @param array<int, array> $rows see class doc
     * @return array{offer:bool, reason:string, interval:string, intervalCount:int, renewalMinor:int,
     *               lineItems:array, subscribed:array, oneTime:array}
     */
    public static function plan(array $rows, int $balanceMinor): array
    {
        $plan = [
            'offer' => false, 'reason' => '', 'interval' => '', 'intervalCount' => 0, 'renewalMinor' => 0,
            'lineItems' => [], 'subscribed' => [], 'oneTime' => [],
        ];
        if ($balanceMinor <= 0) {
            $plan['reason'] = 'The invoice has no balance due.';
            return $plan;
        }

        // Which rows may be subscribed, and why not otherwise.
        $notes = [];
        $groups = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row['recurring'] ?? null) || !in_array($row['relType'] ?? '', ['hosting', 'domain', 'addon'], true)) {
                continue; // setup fees, one-off items: charged once
            }
            $note = self::ineligibility($row);
            if ($note !== '') {
                $notes[$i] = $note;
                continue;
            }
            [$interval, $count] = self::canonicalCadence($row['recurring']['interval'], (int) $row['recurring']['intervalCount']);
            $groups[$interval . ':' . $count][] = $i;
        }
        if (!$groups) {
            $plan['reason'] = 'Nothing on this invoice can renew automatically.';
            $plan['oneTime'] = self::oneTimeList($rows, $notes);
            return $plan;
        }

        // Anchor: the cadence of the first hosting service, else of the first recurring item.
        $anchor = '';
        foreach ($groups as $key => $idx) {
            foreach ($idx as $i) {
                if ($rows[$i]['relType'] === 'hosting') {
                    $anchor = $key;
                    break 2;
                }
            }
        }
        if ($anchor === '') {
            $anchor = (string) array_key_first($groups);
        }
        [$interval, $count] = explode(':', $anchor);
        $count = (int) $count;
        foreach ($groups as $key => $idx) {
            if ($key === $anchor) {
                continue;
            }
            foreach ($idx as $i) {
                [$ri, $rc] = self::canonicalCadence($rows[$i]['recurring']['interval'], (int) $rows[$i]['recurring']['intervalCount']);
                $notes[$i] = 'Renews ' . self::cadenceLabel($ri, $rc) . ', a different cycle from the subscription; '
                    . 'charged once now, subscribe it from its renewal invoice.';
            }
        }
        $subscribe = array_flip($groups[$anchor]);

        // Line items in invoice order.
        $sum = 0;
        foreach ($rows as $i => $row) {
            $amount = (int) $row['amountMinor'];
            if (isset($subscribe[$i])) {
                $renewal = (int) $row['recurring']['renewalMinor'];
                $plan['lineItems'][] = [
                    'description' => self::clip($row['description']),
                    'quantity'    => 1,
                    'unitPrice'   => $renewal,
                    'recurring'   => ['interval' => $interval, 'intervalCount' => $count],
                ];
                $plan['subscribed'][] = [
                    'relType' => $row['relType'], 'relId' => (int) $row['relId'], 'description' => self::clip($row['description']),
                    'renewalMinor' => $renewal, 'interval' => $interval, 'intervalCount' => $count,
                ];
                $plan['renewalMinor'] += $renewal;
                $sum += $renewal;
                if ($amount > $renewal) {
                    $plan['lineItems'][] = ['description' => self::clip('First period: ' . $row['description']), 'quantity' => 1, 'unitPrice' => $amount - $renewal];
                    $sum += $amount - $renewal;
                }
                continue;
            }
            if ($amount < 0) {
                $plan['reason'] = 'The invoice has a credit or discount line that cannot be itemised; use Pay Now.';
                return self::withoutOffer($plan, $rows, $notes);
            }
            if ($amount > 0) {
                $plan['lineItems'][] = ['description' => self::clip($row['description']), 'quantity' => 1, 'unitPrice' => $amount];
                $sum += $amount;
            }
        }
        $plan['oneTime'] = self::oneTimeList(array_diff_key($rows, $subscribe), $notes);

        if ($sum !== $balanceMinor) {
            $plan['reason'] = 'The invoice lines (' . $sum . ') do not add up to the balance due (' . $balanceMinor
                . '), e.g. after a part payment or applied credit; use Pay Now.';
            return self::withoutOffer($plan, $rows, $notes);
        }
        $plan['offer'] = true;
        $plan['interval'] = $interval;
        $plan['intervalCount'] = $count;
        return $plan;
    }

    /** Why a recurring row cannot join the subscription, or '' when it can. */
    private static function ineligibility(array $row): string
    {
        $r = $row['recurring'];
        if (!empty($row['subscribed'])) {
            return 'Already renews automatically through your subscription.';
        }
        if (!in_array($r['interval'] ?? '', ['day', 'week', 'month', 'year'], true) || (int) ($r['intervalCount'] ?? 0) < 1) {
            return 'Its billing cycle cannot renew automatically.';
        }
        if ((int) $r['renewalMinor'] <= 0) {
            return 'It renews free of charge.';
        }
        if ((int) $row['amountMinor'] < (int) $r['renewalMinor']) {
            return 'This period is discounted below the renewal price; charged once now, subscribe it from its renewal invoice.';
        }
        return '';
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

    /** Lines charged once on this checkout, each with why it does not auto-renew ('' for plain one-off items). */
    private static function oneTimeList(array $rows, array $notes): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            if ((int) $row['amountMinor'] === 0) {
                continue;
            }
            $out[] = ['description' => self::clip($row['description']), 'amountMinor' => (int) $row['amountMinor'], 'note' => $notes[$i] ?? ''];
        }
        return $out;
    }

    private static function withoutOffer(array $plan, array $rows, array $notes): array
    {
        $plan['lineItems'] = [];
        $plan['subscribed'] = [];
        $plan['renewalMinor'] = 0;
        $plan['oneTime'] = self::oneTimeList($rows, $notes);
        return $plan;
    }

    private static function clip(string $description): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $description));
        return function_exists('mb_substr') ? mb_substr($line, 0, 255) : substr($line, 0, 255);
    }
}
