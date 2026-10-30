<?php
/**
 * Converts a WHMCS refund (minor units of the invoice currency) into the body
 * of POST /payments/:id/refund.
 *
 * PaymentHooks takes the refund as `transactionAmountUsdCents` and refunds the gateway
 * in the payment's transactionLocalCurrency, scaled pro rata from the exact
 * local charge. WHMCS refunds in the invoice currency, so the amount is mapped
 * through the payment's own transaction fields (read from GET /payments/:id):
 *
 *   transactionLocalCurrency        ISO code the payment was charged in
 *   transactionLocalCurrencyAmount  what was charged, smallest unit
 *   transactionAmountUsdCents       the same charge in USD cents
 *   transactionRefundedAmountUsdCents  refunded so far, USD cents (or null)
 *
 * Pure PHP (no WHMCS calls) so it is unit-tested in tests/run.php.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class RefundMapper
{
    /**
     * USD cents to request for a refund of $refundMinor (smallest unit of
     * $currency) on $payment.
     *
     * @param array $payment the `data` of GET /payments/:id
     * @throws \RuntimeException when the payment cannot be refunded in this currency or amount
     */
    public static function usdCents(array $payment, int $refundMinor, string $currency): int
    {
        $currency = strtoupper(trim($currency));
        $charged = strtoupper(trim((string) ($payment['transactionLocalCurrency'] ?? '')));
        $local = self::int($payment['transactionLocalCurrencyAmount'] ?? null);
        $usd = self::int($payment['transactionAmountUsdCents'] ?? null);
        $refundedUsd = self::int($payment['transactionRefundedAmountUsdCents'] ?? 0);

        if ($refundMinor <= 0) {
            throw new \RuntimeException('Refund amount must be positive.');
        }
        if ($charged === '' || $local <= 0 || $usd <= 0) {
            throw new \RuntimeException('PaymentHooks payment has no transaction amounts; refund it from the PaymentHooks dashboard.');
        }
        if ($charged !== $currency) {
            throw new \RuntimeException('Payment was charged in ' . $charged . ' but the refund is in ' . $currency . '; refund it from the PaymentHooks dashboard.');
        }
        $remainingUsd = $usd - $refundedUsd;
        if ($remainingUsd <= 0) {
            throw new \RuntimeException('Payment is already fully refunded at PaymentHooks.');
        }
        if ($refundMinor > $local) {
            throw new \RuntimeException('Refund of ' . $refundMinor . ' exceeds the charge of ' . $local . ' ' . $charged . '.');
        }
        if ($refundMinor === $local && $refundedUsd === 0) {
            return $usd;
        }
        // Same pro-rata mapping PaymentHooks uses back to the gateway, so a
        // partial refund returns the local amount WHMCS asked for.
        $cents = $charged === 'USD' ? $refundMinor : (int) round($refundMinor * $usd / $local);
        if ($cents <= 0) {
            throw new \RuntimeException('Refund of ' . $refundMinor . ' ' . $charged . ' is less than one US cent.');
        }
        // One cent over is rounding on the last partial refund; more is a real over-refund.
        if ($cents > $remainingUsd + 1) {
            throw new \RuntimeException('Refund exceeds what is left to refund at PaymentHooks (' . $remainingUsd . ' USD cents).');
        }
        return min($cents, $remainingUsd);
    }

    /** POST /payments/:id/refund body. */
    public static function body(int $amountUsdCents, string $reason): array
    {
        return ['transactionAmountUsdCents' => $amountUsdCents, 'reason' => $reason];
    }

    private static function int($v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            return (int) $v;
        }
        return 0;
    }
}
