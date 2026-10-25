<?php
/**
 * Builds the POST /checkout-sessions body for a WHMCS invoice. Pure PHP (no
 * WHMCS calls) so the request shape is unit-tested in tests/run.php.
 *
 * Contract (PaymentHooks /api/v1/checkout-sessions):
 *   lineItems[]   required: { description, quantity >= 1, unitPrice >= 0 } in minor units
 *   currency      ISO code, upper case; amounts stay in this currency
 *   mode          "payment" | "subscription"
 *   metadata      the subscription price goes in metadata.priceId (the only
 *                 place the server reads it)
 *   convenienceFee integer minor units, added to the total by the server
 *   taxAmount     always 0: the WHMCS balance already includes WHMCS tax, and
 *                 without it PaymentHooks auto-tax would charge tax again
 *
 * No webhookUrl is sent: events reach WHMCS through the registered webhook
 * endpoint only, signed with its secret. Sending webhookUrl as well would
 * deliver every event twice, signed with a different secret.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class CheckoutBuilder
{
    /**
     * @param array{lineItems: array, subtotal_minor: int} $mapped from InvoiceMapper
     * @return array request body
     */
    public static function build(
        Config $config,
        int $invoiceId,
        string $currency,
        array $mapped,
        string $mode,
        string $systemUrl,
        string $customerId,
        string $customerEmail,
        string $priceId
    ): array {
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invoice ' . $invoiceId . ' has no valid currency code.');
        }
        if ($mode === 'subscription' && $priceId === '') {
            throw new \InvalidArgumentException('A subscription checkout needs a PaymentHooks price id.');
        }

        $systemUrl = rtrim($systemUrl, '/');
        $metadata = [
            'whmcs_invoice_id' => (string) $invoiceId,
            'whmcs_system_url' => $systemUrl,
        ];
        if ($mode === 'subscription') {
            $metadata['priceId'] = $priceId;
        }

        $body = [
            'mode'        => $mode,
            'currency'    => $currency,
            'lineItems'   => $mapped['lineItems'],
            'successUrl'  => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true',
            'cancelUrl'   => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true',
            'metadata'    => $metadata,
            'taxAmount'   => 0,
        ];

        $description = trim(strtr($config->descriptionTemplate, ['{invoiceid}' => (string) $invoiceId]));
        if ($description !== '') {
            $body['metadata']['description'] = $description;
        }
        if ($customerId !== '') {
            $body['customerId'] = $customerId;
        }
        if ($customerEmail !== '') {
            $body['customerEmail'] = $customerEmail;
        }

        $fee = self::convenienceFeeMinor($config, (int) $mapped['subtotal_minor']);
        if ($fee > 0) {
            $body['convenienceFee'] = $fee;
        }
        return $body;
    }

    /**
     * Idempotency key for one checkout attempt. Keys are scoped per
     * PaymentHooks merchant, so the WHMCS install is part of the key: two
     * installs (or a reinstall, see Schema::installId) on one merchant never
     * share a session.
     */
    public static function idempotencyKey(string $installId, int $invoiceId, int $subtotalMinor, string $mode, int $attemptSeq): string
    {
        return 'whmcs-' . $installId . '-inv-' . $invoiceId . '-' . $subtotalMinor . '-' . $mode . '-' . $attemptSeq;
    }

    public static function convenienceFeeMinor(Config $config, int $subtotalMinor): int
    {
        if ($config->convenienceFeePercent === null || $config->convenienceFeePercent <= 0) {
            return 0;
        }
        return (int) round($subtotalMinor * $config->convenienceFeePercent / 100);
    }
}
