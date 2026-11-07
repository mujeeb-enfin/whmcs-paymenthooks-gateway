<?php
/**
 * WHMCS billing cycles as PaymentHooks recurring cadences, plus the legacy
 * service lookup for subscriptions bound by module versions before the
 * mod_paymenthooks_subscriptions table.
 *
 * Subscriptions no longer use PaymentHooks Products/Prices: each subscribed
 * line carries its own `recurring` cadence on the checkout session
 * (CheckoutBuilder), because a WHMCS service's price is per client (promos,
 * config options, currency). mod_paymenthooks_price_map is kept for installs
 * that have rows in it but is no longer written.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class PriceResolver
{
    /**
     * Id of the first recurring (non one-time/free) hosting service on the
     * invoice, or 0. Used only for checkouts opened by earlier module versions,
     * which subscribed exactly this service.
     */
    public static function firstRecurringServiceId(int $invoiceId): int
    {
        $relIds = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->orderBy('id', 'asc')
            ->pluck('relid')
            ->all();
        if (!$relIds) {
            return 0;
        }

        // Invoice-item order, as the earlier versions priced it.
        foreach ($relIds as $relId) {
            $row = Capsule::table('tblhosting')->where('id', $relId)->first();
            if (!$row) {
                continue;
            }
            $cycle = strtolower((string) $row->billingcycle);
            if ($cycle !== '' && $cycle !== 'one time' && $cycle !== 'free') {
                return (int) $row->id;
            }
        }
        return 0;
    }

    /**
     * Maps a WHMCS billing cycle to a PH price interval (day/week/month/year);
     * multi-period cycles add intervalCount (see cycleToIntervalCount).
     */
    public static function cycleToInterval(string $cycle): ?string
    {
        switch (strtolower($cycle)) {
            case 'weekly':
                return 'week';
            case 'monthly':
                return 'month';
            case 'quarterly':
            case 'semi-annually':
            case 'semiannually':
                return 'month';
            case 'annually':
            case 'biennially':
            case 'triennially':
                return 'year';
            default:
                return null;
        }
    }

    /**
     * intervalCount for cycles that are a multiple of the base unit, so PH bills
     * on the correct cadence (e.g. Quarterly = every 3 months).
     */
    public static function cycleToIntervalCount(string $cycle): int
    {
        switch (strtolower($cycle)) {
            case 'quarterly':
                return 3;
            case 'semi-annually':
            case 'semiannually':
                return 6;
            case 'biennially':
                return 2;
            case 'triennially':
                return 3;
            default:
                return 1;
        }
    }
}
