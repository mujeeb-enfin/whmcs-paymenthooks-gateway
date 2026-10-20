<?php
/**
 * Converts WHMCS invoice data into PaymentHooks checkout-session fields, and
 * handles the minor-unit (paise/cents) conversion both ways.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class InvoiceMapper
{
    /** Currencies with no minor unit (amount is already the integer). */
    const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XAF', 'XOF', 'BIF', 'GNF', 'PYG', 'RWF', 'UGX', 'VUV', 'XPF', 'DJF', 'KMF'];

    /** Major units (e.g. 499.00) -> minor units (49900). */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /** Minor units (49900) -> major units (499.00). */
    public static function toMajorUnits(int $minor, string $currency): float
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return (float) $minor;
        }
        return $minor / 100;
    }

    /**
     * True if a gateway transaction with this id is already recorded in WHMCS.
     *
     * We do NOT use WHMCS's checkCbTransID() for this: that function die()s on a
     * duplicate and returns no usable value otherwise, so it can't gate an
     * if-statement. A direct tblaccounts lookup is the supported way to check
     * "already applied?" without aborting the request.
     */
    public static function transactionExists(string $transId, string $gateway = 'paymenthooks'): bool
    {
        if ($transId === '') {
            return false;
        }
        return Capsule::table('tblaccounts')
            ->where('transid', $transId)
            ->where('gateway', $gateway)
            ->exists();
    }

    /**
     * Builds lineItems[] from the invoice rows. Each item: description, quantity,
     * unitPrice (minor units). WHMCS invoice items don't carry quantity, so each
     * row is quantity 1 at its full amount.
     *
     * @return array{lineItems: array, subtotal_minor: int}
     */
    public static function buildLineItems(int $invoiceId, string $currency): array
    {
        $rows = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->get();

        $items = [];
        $subtotal = 0;
        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $minor  = self::toMinorUnits($amount, $currency);
            $subtotal += $minor;
            $items[] = [
                'description' => (string) $row->description,
                'quantity'    => 1,
                'unitPrice'   => $minor,
            ];
        }

        // Fallback: if no itemised rows, use the invoice total as a single line.
        if (!$items) {
            $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            $total = $inv ? (float) $inv->total : 0.0;
            $minor = self::toMinorUnits($total, $currency);
            $subtotal = $minor;
            $items[] = [
                'description' => 'Invoice ' . $invoiceId,
                'quantity'    => 1,
                'unitPrice'   => $minor,
            ];
        }

        return ['lineItems' => $items, 'subtotal_minor' => $subtotal];
    }
}
