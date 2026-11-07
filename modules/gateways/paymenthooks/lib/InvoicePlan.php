<?php
/**
 * Loads an invoice's lines from WHMCS and hands them to RecurringPlanner.
 *
 * What each line renews at, by type:
 *   Hosting                     tblhosting.amount every billingcycle
 *   Domain / DomainRegister /   tbldomains.recurringamount every
 *   DomainTransfer              registrationperiod years
 *   Addon                       tblhostingaddons.recurring every billingcycle
 *   anything else (Setup, Item, Promo..., Invoice, LateFee)  one-time
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
            ];
        }
        $taxMinor = InvoiceMapper::toMinorUnits((float) ($invoice->tax ?? 0), $currency)
            + InvoiceMapper::toMinorUnits((float) ($invoice->tax2 ?? 0), $currency);
        $totalMinor = InvoiceMapper::toMinorUnits((float) $invoice->total, $currency);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $line + self::recurringFor($line['type'], $line['relId'], $currency);
        }
        $rows = self::allocateTax($rows, $taxMinor, $totalMinor);
        return RecurringPlanner::plan($rows, InvoiceMapper::balanceMinor($invoiceId, $currency));
    }

    /**
     * relType, relId, recurring and subscribed for one line.
     *
     * @return array{relType:string, relId:int, recurring:?array, subscribed:bool}
     */
    private static function recurringFor(string $type, int $relId, string $currency): array
    {
        $none = ['relType' => '', 'relId' => $relId, 'recurring' => null, 'subscribed' => false];
        if ($relId <= 0) {
            return $none;
        }
        if ($type === 'Hosting') {
            $row = Capsule::table('tblhosting')->where('id', $relId)->first();
            return $row ? self::cycleRow('hosting', $relId, (string) $row->billingcycle, (float) $row->amount, $currency) : $none;
        }
        if ($type === 'Addon') {
            $row = Capsule::table('tblhostingaddons')->where('id', $relId)->first();
            return $row ? self::cycleRow('addon', $relId, (string) $row->billingcycle, (float) $row->recurring, $currency) : $none;
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
            return ['relType' => 'domain', 'relId' => $relId, 'recurring' => $recurring, 'subscribed' => SubscriptionMap::isSubscribed('domain', $relId)];
        }
        return $none;
    }

    private static function cycleRow(string $relType, int $relId, string $cycle, float $amount, string $currency): array
    {
        $interval = PriceResolver::cycleToInterval($cycle);
        $recurring = $interval === null ? null : [
            'interval'      => $interval,
            'intervalCount' => PriceResolver::cycleToIntervalCount($cycle),
            'renewalMinor'  => InvoiceMapper::toMinorUnits($amount, $currency),
        ];
        return ['relType' => $relType, 'relId' => $relId, 'recurring' => $recurring, 'subscribed' => SubscriptionMap::isSubscribed($relType, $relId)];
    }

    /**
     * Pure (unit-tested): spreads exclusive WHMCS tax over the taxed lines so
     * the lines add up to the invoice total, and scales each subscribed
     * renewal price by its line's tax share. Lines already adding up to the
     * total (inclusive tax, or none) are left as they are.
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
