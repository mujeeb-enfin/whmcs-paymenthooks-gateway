<?php
/**
 * Converts WHMCS invoice data into PaymentHooks checkout-session fields and
 * handles minor-unit (paise/cents) conversion both ways.
 *
 * PaymentHooks keeps checkout, payment and webhook amounts in the smallest
 * unit of the invoice's own currency, so no exchange-rate maths happens here.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class InvoiceMapper
{
    /** Currencies with no minor unit. Same list as PaymentHooks (services/currency/constants.ts). */
    const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Major units (e.g. 499.00) -> minor units (49900). */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /** Minor units (49900) -> major units (499.00). */
    public static function toMajorUnits(int $minor, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            return (float) $minor;
        }
        return $minor / 100;
    }

    /**
     * True if a gateway transaction with this id is already recorded in WHMCS.
     * checkCbTransID() die()s on a duplicate, so it cannot gate a branch.
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
     * Line items for the invoice balance. WHMCS invoice rows carry no quantity,
     * so each row is quantity 1. Rows are only used when they add up to the
     * invoice total; otherwise (tax, credit, partial payments) a single line
     * for the balance due is sent so the customer pays exactly what is owed.
     *
     * @return array{lineItems: array, subtotal_minor: int}
     * @throws \RuntimeException when the invoice has nothing to pay
     */
    public static function buildLineItems(int $invoiceId, string $currency): array
    {
        $balanceMinor = self::balanceMinor($invoiceId, $currency);

        $rows = [];
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get() as $row) {
            $rows[] = ['description' => (string) $row->description, 'amount' => (float) $row->amount];
        }
        return self::lineItemsForBalance($invoiceId, $rows, $balanceMinor, $currency);
    }

    /**
     * WHMCS's own balance due (total less payments and applied credit), so the
     * module never re-derives how WHMCS stores credit.
     */
    public static function balanceMinor(int $invoiceId, string $currency): int
    {
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' could not be loaded: ' . ($result['message'] ?? 'unknown error'));
        }
        return self::toMinorUnits((float) $result['balance'], $currency);
    }

    /**
     * Pure part of buildLineItems (unit-tested).
     *
     * @param array<int, array{description:string, amount:float}> $rows
     * @return array{lineItems: array, subtotal_minor: int}
     */
    public static function lineItemsForBalance(int $invoiceId, array $rows, int $balanceMinor, string $currency): array
    {
        if ($balanceMinor <= 0) {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' has no balance due.');
        }

        $items = [];
        $sum = 0;
        foreach ($rows as $row) {
            $minor = self::toMinorUnits($row['amount'], $currency);
            $description = trim($row['description']);
            if ($minor < 0 || $description === '') {
                $items = [];
                break;
            }
            if ($minor === 0) {
                continue;
            }
            $sum += $minor;
            $items[] = ['description' => self::clip($description), 'quantity' => 1, 'unitPrice' => $minor];
        }

        if (!$items || $sum !== $balanceMinor) {
            $items = [['description' => 'Invoice #' . $invoiceId, 'quantity' => 1, 'unitPrice' => $balanceMinor]];
        }

        return ['lineItems' => $items, 'subtotal_minor' => $balanceMinor];
    }

    /** WHMCS item descriptions can be multi-line; checkout shows one line. */
    private static function clip(string $description): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $description));
        return function_exists('mb_substr') ? mb_substr($line, 0, 255) : substr($line, 0, 255);
    }
}
