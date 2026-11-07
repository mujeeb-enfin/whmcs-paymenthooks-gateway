<?php
/**
 * Loads an invoice's lines from WHMCS and hands them to RecurringPlanner.
 *
 * What each line renews at, and from when, by type:
 *   Hosting                     tblhosting.amount every billingcycle
 *   Domain / DomainRegister /   tbldomains.recurringamount every
 *   DomainTransfer              registrationperiod years
 *   Addon                       tblhostingaddons.recurring every billingcycle
 *   PromoHosting / PromoDomain  the promotion discount of the item with the
 *                               same relid: netted into that item's line, so
 *                               a promo period is a discounted period
 *   anything else (Setup, Item, Invoice, LateFee)  one-time
 *
 * periodStart is the item's own nextduedate (tblhosting / tbldomains /
 * tblhostingaddons). Until the invoice is paid, WHMCS keeps it on the first
 * day of the period the invoice pays (for a new order: the order date); on
 * payment WHMCS moves it on by one cycle. That moved-on date is when a
 * discounted item's first renewal is due, so it is the line's startsAt.
 * tblinvoiceitems.duedate is the same date when the invoice was raised and
 * is used to detect a due date changed by hand since.
 *
 * WHMCS tax: with exclusive tax the invoice tax is allocated exactly to the
 * taxed lines (largest remainder on the last), and a subscribed item's
 * renewal price carries the same share of tax as its line. With inclusive
 * tax the line and renewal prices already contain it.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class InvoicePlan
{
    const DOMAIN_TYPES = ['Domain', 'DomainRegister', 'DomainTransfer'];
    /** Promotion discount lines and the item type they discount. */
    const PROMO_TYPES = ['PromoHosting' => 'Hosting', 'PromoDomain' => 'Domain'];

    /** WHMCS invoice-item types that carry each subscribed item on a renewal invoice. */
    public static function itemTypes(string $relType): array
    {
        switch ($relType) {
            case 'hosting':
                return ['Hosting'];
            case 'addon':
                return ['Addon'];
            case 'domain':
                return self::DOMAIN_TYPES;
            default:
                return [];
        }
    }

    /** @return array RecurringPlanner::plan() result for the invoice balance */
    public static function forInvoice(int $invoiceId, string $currency): array
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' does not exist.');
        }
        $lines = [];
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get() as $item) {
            $lines[] = [
                'type'        => (string) $item->type,
                'relId'       => (int) $item->relid,
                'description' => (string) $item->description,
                'amountMinor' => InvoiceMapper::toMinorUnits((float) $item->amount, $currency),
                'taxed'       => !empty($item->taxed),
                'lineDueDate' => self::ymd((string) ($item->duedate ?? '')),
            ];
        }
        $taxMinor = InvoiceMapper::toMinorUnits((float) ($invoice->tax ?? 0), $currency)
            + InvoiceMapper::toMinorUnits((float) ($invoice->tax2 ?? 0), $currency);
        $totalMinor = InvoiceMapper::toMinorUnits((float) $invoice->total, $currency);

        $rows = [];
        foreach (self::netPromotions($lines) as $line) {
            $rows[] = $line + self::recurringFor($line['type'], $line['relId'], $currency);
        }
        $rows = self::allocateTax($rows, $taxMinor, $totalMinor);
        $balance = InvoiceMapper::balanceDetail($invoiceId, $currency);
        return RecurringPlanner::plan($rows, $balance['balanceMinor'], [
            'today' => date('Y-m-d'), 'creditMinor' => $balance['creditMinor'], 'paidMinor' => $balance['paidMinor'],
        ]);
    }

    /**
     * Pure (unit-tested): folds each promotion line into the line of the item
     * it discounts (same relid, matching type), so the item's line is what
     * this period really costs. A promo line without its item stays as it is
     * (and, being negative, stops the Subscribe offer with a reason, and
     * makes the one-time checkout send a single balance line).
     */
    public static function netPromotions(array $lines): array
    {
        $out = [];
        $index = [];
        foreach ($lines as $line) {
            $type = (string) $line['type'];
            $base = $type === 'Hosting' ? 'Hosting' : (in_array($type, self::DOMAIN_TYPES, true) ? 'Domain' : '');
            if ($base !== '' && (int) $line['relId'] > 0 && !isset($index[$base . ':' . $line['relId']])) {
                $index[$base . ':' . $line['relId']] = count($out);
            }
            $out[] = $line;
        }
        foreach ($out as $i => $line) {
            $target = self::PROMO_TYPES[(string) $line['type']] ?? '';
            $key = $target . ':' . (int) $line['relId'];
            if ($target === '' || !isset($index[$key])) {
                continue;
            }
            $out[$index[$key]]['amountMinor'] += (int) $line['amountMinor'];
            $out[$index[$key]]['description'] .= ' (' . trim((string) $line['description']) . ')';
            unset($out[$i]);
        }
        return array_values($out);
    }

    /**
     * relType, relId, recurring, subscribed and periodStart for one line.
     *
     * @return array{relType:string, relId:int, recurring:?array, subscribed:bool, periodStart:string}
     */
    private static function recurringFor(string $type, int $relId, string $currency): array
    {
        $none = ['relType' => '', 'relId' => $relId, 'recurring' => null, 'subscribed' => false, 'periodStart' => ''];
        if ($relId <= 0) {
            return $none;
        }
        if ($type === 'Hosting') {
            $row = Capsule::table('tblhosting')->where('id', $relId)->first();
            return $row ? self::cycleRow('hosting', $relId, (string) $row->billingcycle, (float) $row->amount, $currency, (string) ($row->nextduedate ?? '')) : $none;
        }
        if ($type === 'Addon') {
            $row = Capsule::table('tblhostingaddons')->where('id', $relId)->first();
            return $row ? self::cycleRow('addon', $relId, (string) $row->billingcycle, (float) $row->recurring, $currency, (string) ($row->nextduedate ?? '')) : $none;
        }
        if (in_array($type, self::DOMAIN_TYPES, true)) {
            $row = Capsule::table('tbldomains')->where('id', $relId)->first();
            if (!$row) {
                return $none;
            }
            $years = (int) $row->registrationperiod;
            $recurring = $years >= 1 && $years <= 10
                ? ['interval' => 'year', 'intervalCount' => $years, 'renewalMinor' => InvoiceMapper::toMinorUnits((float) $row->recurringamount, $currency)]
                : null;
            return ['relType' => 'domain', 'relId' => $relId, 'recurring' => $recurring, 'subscribed' => SubscriptionMap::isSubscribed('domain', $relId),
                'periodStart' => self::ymd((string) ($row->nextduedate ?? ''))];
        }
        return $none;
    }

    private static function cycleRow(string $relType, int $relId, string $cycle, float $amount, string $currency, string $nextDue): array
    {
        $interval = PriceResolver::cycleToInterval($cycle);
        $recurring = $interval === null ? null : [
            'interval'      => $interval,
            'intervalCount' => PriceResolver::cycleToIntervalCount($cycle),
            'renewalMinor'  => InvoiceMapper::toMinorUnits($amount, $currency),
        ];
        return ['relType' => $relType, 'relId' => $relId, 'recurring' => $recurring, 'subscribed' => SubscriptionMap::isSubscribed($relType, $relId),
            'periodStart' => self::ymd($nextDue)];
    }

    /** WHMCS date column to Y-m-d; '' for empty or 0000-00-00. */
    private static function ymd(string $date): string
    {
        $d = substr(trim($date), 0, 10);
        return RecurringPlanner::isDate($d) && strpos($d, '0000') !== 0 ? $d : '';
    }

    /**
     * Pure (unit-tested): spreads exclusive WHMCS tax over the taxed lines so
     * the lines add up to the invoice total, and scales each subscribed
     * renewal price by its line's tax share. Lines already adding up to the
     * total (inclusive tax, or none) are left as they are. Shared by the
     * Subscribe & Auto-pay plan and the one-time checkout lines
     * (InvoiceMapper::oneTimeLines), so both show the same gross lines.
     */
    public static function allocateTax(array $rows, int $taxMinor, int $totalMinor): array
    {
        $sum = 0;
        $taxedSum = 0;
        $last = -1;
        foreach ($rows as $i => $row) {
            $sum += $row['amountMinor'];
            if (!empty($row['taxed']) && $row['amountMinor'] > 0) {
                $taxedSum += $row['amountMinor'];
                $last = $i;
            }
        }
        if ($taxMinor <= 0 || $sum === $totalMinor || $sum + $taxMinor !== $totalMinor || $taxedSum <= 0) {
            return $rows;
        }
        $left = $taxMinor;
        foreach ($rows as $i => $row) {
            if (empty($row['taxed']) || $row['amountMinor'] <= 0) {
                continue;
            }
            $share = $i === $last ? $left : (int) floor($taxMinor * $row['amountMinor'] / $taxedSum);
            $left -= $share;
            if (is_array($row['recurring'] ?? null)) {
                $renewal = (int) $row['recurring']['renewalMinor'];
                $rows[$i]['recurring']['renewalMinor'] = $renewal === $row['amountMinor']
                    ? $renewal + $share
                    : $renewal + (int) round($renewal * $share / $row['amountMinor']);
            }
            $rows[$i]['amountMinor'] += $share;
        }
        return $rows;
    }
}
