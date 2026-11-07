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
     * Line items for the invoice balance (see oneTimeLines), plus the WHMCS
     * tax inside that balance. `fallbackReason` is '' when the real invoice
     * lines are sent, else why a single "Invoice #N" line is sent instead
     * (the caller logs it).
     *
     * @return array{lineItems: array, subtotal_minor: int, fallbackReason: string, tax_minor: int, tax_rate: float}
     * @throws \RuntimeException when the invoice has nothing to pay
     */
    public static function buildLineItems(int $invoiceId, string $currency): array
    {
        $invoice = self::getInvoice($invoiceId);
        $balanceMinor = self::toMinorUnits((float) $invoice['balance'], $currency);

        $lines = [];
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get() as $row) {
            $lines[] = [
                'type'        => (string) $row->type,
                'relId'       => (int) $row->relid,
                'description' => (string) $row->description,
                'amountMinor' => self::toMinorUnits((float) $row->amount, $currency),
                'taxed'       => !empty($row->taxed),
            ];
        }
        $totals = [
            'totalMinor'   => self::toMinorUnits((float) ($invoice['total'] ?? 0), $currency),
            'taxMinor'     => self::toMinorUnits((float) ($invoice['tax'] ?? 0), $currency) + self::toMinorUnits((float) ($invoice['tax2'] ?? 0), $currency),
            'creditMinor'  => self::toMinorUnits((float) ($invoice['credit'] ?? 0), $currency),
            'balanceMinor' => $balanceMinor,
        ];
        return self::oneTimeLines($invoiceId, $lines, $totals) + self::taxInBalance($invoice, $balanceMinor, $currency);
    }

    /**
     * The tax WHMCS already charged that is inside the balance being paid.
     * WHMCS adds its tax (tax + tax2) on top of the subtotal (or includes it,
     * with "inclusive tax" on); either way the invoice total contains it. A
     * partly paid invoice pays a share of it, in proportion to the balance.
     * The checkout sends it as inclusive tax, so PaymentHooks records and
     * reports it and never adds tax again. Applied credit counts like a
     * payment: WHMCS takes it off `total`, but the tax was charged on the
     * whole invoice, so the share is taken of total + credit (the same base
     * oneTimeLines scales the lines by).
     *
     * @param array<string, mixed> $invoice GetInvoice result
     * @return array{tax_minor: int, tax_rate: float}
     */
    public static function taxInBalance(array $invoice, int $balanceMinor, string $currency): array
    {
        $totalMinor = self::toMinorUnits((float) ($invoice['total'] ?? 0), $currency) + self::toMinorUnits((float) ($invoice['credit'] ?? 0), $currency);
        $taxMinor = self::toMinorUnits((float) ($invoice['tax'] ?? 0), $currency) + self::toMinorUnits((float) ($invoice['tax2'] ?? 0), $currency);
        if ($taxMinor <= 0 || $totalMinor <= 0 || $balanceMinor <= 0) {
            return ['tax_minor' => 0, 'tax_rate' => 0.0];
        }
        $share = $balanceMinor >= $totalMinor ? $taxMinor : (int) round($taxMinor * $balanceMinor / $totalMinor);
        // The combined rate WHMCS applied (taxrate + taxrate2 when compounded on top), for receipts and reports.
        $rate = (float) ($invoice['taxrate'] ?? 0) + (float) ($invoice['taxrate2'] ?? 0);
        return ['tax_minor' => min($share, $balanceMinor - 1), 'tax_rate' => $rate];
    }

    /**
     * WHMCS's own balance due (total less payments and applied credit), so the
     * module never re-derives how WHMCS stores credit.
     */
    public static function balanceMinor(int $invoiceId, string $currency): int
    {
        return self::toMinorUnits((float) self::getInvoice($invoiceId)['balance'], $currency);
    }

    /**
     * The balance with what reduced it: account credit applied and payments
     * already made, all in minor units. GetInvoice `credit` is the credit
     * applied (WHMCS takes it off `total`); payments are `total - balance`.
     *
     * @return array{balanceMinor:int, creditMinor:int, paidMinor:int}
     */
    public static function balanceDetail(int $invoiceId, string $currency): array
    {
        $inv = self::getInvoice($invoiceId);
        $balance = self::toMinorUnits((float) $inv['balance'], $currency);
        return [
            'balanceMinor' => $balance,
            'creditMinor'  => self::toMinorUnits((float) ($inv['credit'] ?? 0), $currency),
            'paidMinor'    => max(0, self::toMinorUnits((float) ($inv['total'] ?? 0), $currency) - $balance),
        ];
    }

    /** @return array<string, mixed> WHMCS GetInvoice result */
    private static function getInvoice(int $invoiceId): array
    {
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' could not be loaded: ' . ($result['message'] ?? 'unknown error'));
        }
        return $result;
    }

    /**
     * Pure part of buildLineItems (unit-tested): the invoice's own lines for
     * a one-time checkout, made to add up exactly to the balance due. WHMCS
     * rows carry no quantity, so each is quantity 1. Built the same way as
     * the Subscribe & Auto-pay lines (InvoicePlan::forInvoice):
     *
     *   promotions   PromoHosting / PromoDomain are folded into the item they
     *                discount (InvoicePlan::netPromotions). PaymentHooks
     *                accepts no negative unitPrice and no request discount,
     *                so any other negative line (a promo without its item, a
     *                manual credit line) cannot be itemised exactly.
     *   tax          exclusive WHMCS tax (tax + tax2) is spread over the taxed
     *                lines (InvoicePlan::allocateTax), so each line is its
     *                gross price. The tax amount is still sent separately as
     *                inclusive tax (taxInBalance), so PaymentHooks shows the
     *                split and never adds it again. Inclusive tax: the lines
     *                already contain it.
     *   part paid /  the gross lines add up to total + credit; when payments
     *   credit       or applied credit leave a smaller balance, each line is
     *                scaled to its share of the balance (remainder on the last
     *                line), descriptions unchanged, the same proportion
     *                taxInBalance uses for the tax. paidMinor / creditMinor say
     *                what reduced it (sent as session metadata).
     *
     * When the lines cannot be made to add up exactly, one "Invoice #N" line
     * for the balance is sent, so the customer still pays exactly what is
     * owed, and fallbackReason says why (the caller logs it).
     *
     * @param array<int, array{type?:string, relId?:int, description:string, amountMinor:int, taxed?:bool}> $lines tblinvoiceitems rows
     * @param array{totalMinor:int, taxMinor:int, creditMinor:int, balanceMinor:int} $totals GetInvoice total, tax + tax2, credit, balance
     * @return array{lineItems: array, subtotal_minor: int, fallbackReason: string, paidMinor: int, creditMinor: int}
     */
    public static function oneTimeLines(int $invoiceId, array $lines, array $totals): array
    {
        $balance = (int) $totals['balanceMinor'];
        if ($balance <= 0) {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' has no balance due.');
        }
        $credit = max(0, (int) $totals['creditMinor']);
        $gross = (int) $totals['totalMinor'] + $credit;
        $paid = $gross - $credit - $balance;
        $single = function (string $reason) use ($invoiceId, $balance, $paid, $credit): array {
            return ['lineItems' => [['description' => 'Invoice #' . $invoiceId, 'quantity' => 1, 'unitPrice' => $balance]],
                'subtotal_minor' => $balance, 'fallbackReason' => $reason, 'paidMinor' => max(0, $paid), 'creditMinor' => $credit];
        };

        $rows = [];
        foreach (InvoicePlan::netPromotions(array_map(function (array $l): array {
            return $l + ['type' => '', 'relId' => 0, 'taxed' => false];
        }, $lines)) as $row) {
            $description = self::clip((string) $row['description']);
            if ((int) $row['amountMinor'] < 0) {
                return $single('line "' . $description . '" is negative (' . $row['amountMinor'] . ') and is not a promotion of an item on the invoice; '
                    . 'PaymentHooks line items cannot be negative');
            }
            if ((int) $row['amountMinor'] === 0) {
                continue;
            }
            if ($description === '') {
                return $single('a line of ' . $row['amountMinor'] . ' has no description');
            }
            $rows[] = ['description' => $description, 'amountMinor' => (int) $row['amountMinor'], 'taxed' => !empty($row['taxed'])];
        }
        if (!$rows) {
            return $single('the invoice has no line with an amount');
        }

        $rows = InvoicePlan::allocateTax($rows, (int) $totals['taxMinor'], $gross);
        $sum = array_sum(array_column($rows, 'amountMinor'));
        if ($sum !== $gross) {
            return $single('the lines total ' . $sum . ' with tax, the invoice total (before credit) is ' . $gross);
        }
        if ($paid < 0) {
            return $single('the balance ' . $balance . ' is more than the invoice total less credit (' . ($gross - $credit) . ')');
        }

        $items = [];
        $left = $balance;
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $amount = $balance === $gross ? $row['amountMinor']
                : ($i === $last ? $left : intdiv($row['amountMinor'] * $balance, $gross));
            $left -= $amount;
            if ($amount > 0) {
                $items[] = ['description' => $row['description'], 'quantity' => 1, 'unitPrice' => $amount];
            }
        }
        return ['lineItems' => $items, 'subtotal_minor' => $balance, 'fallbackReason' => '', 'paidMinor' => $paid, 'creditMinor' => $credit];
    }

    /** WHMCS item descriptions can be multi-line; checkout shows one line. */
    private static function clip(string $description): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $description));
        return function_exists('mb_substr') ? mb_substr($line, 0, 255) : substr($line, 0, 255);
    }
}
