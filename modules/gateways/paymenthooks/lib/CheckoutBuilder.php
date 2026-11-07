<?php
/**
 * Builds the POST /checkout-sessions body for a WHMCS invoice. Pure PHP (no
 * WHMCS calls) so the request shape is unit-tested in tests/run.php.
 *
 * Contract (PaymentHooks /api/v1/checkout-sessions):
 *   lineItems[]       required: { description, quantity >= 1, unitPrice >= 0 } in
 *                     minor units of itemLocalCurrency
 *   itemLocalCurrency required ISO code, upper case; amounts stay in this currency
 *   mode              "payment" | "subscription"
 *   lineItems[].recurring { interval, intervalCount, startsAt? }
 *                     subscription mode only: the line renews at its unitPrice
 *                     on that cadence; lines without it are charged once on the
 *                     first payment (setup fees, a discounted first period).
 *                     Without startsAt the line is also charged now (its first
 *                     period); with startsAt (ISO date) it is first charged on
 *                     that date. PaymentHooks groups recurring lines by
 *                     (interval, intervalCount, startsAt): one subscription per
 *                     group, one payment now, one mandate. The session returns
 *                     them as `subscriptions: [...]` next to `subscription`.
 *   itemConvenienceFeeLocalCurrencyAmount
 *                     integer minor units, added to the total by the server
 *   itemTaxLocalCurrencyAmount + taxBehavior "inclusive"
 *                     the WHMCS tax already inside the balance (0 when WHMCS
 *                     charged none). Declaring it inclusive means PaymentHooks
 *                     records and reports that tax but never adds tax again,
 *                     and the customer pays exactly the WHMCS balance.
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
     * @param array{lineItems: array, subtotal_minor: int, tax_minor?: int, tax_rate?: float} $mapped from InvoiceMapper
     * @param array|null $plan RecurringPlanner::plan() result; required in subscription mode
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
        ?array $plan = null
    ): array {
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invoice ' . $invoiceId . ' has no valid currency code.');
        }
        $lineItems = $mapped['lineItems'];
        if ($mode === 'subscription') {
            if (!is_array($plan) || empty($plan['offer'])) {
                throw new \InvalidArgumentException('Invoice ' . $invoiceId . ' cannot be subscribed: '
                    . (is_array($plan) && $plan['reason'] !== '' ? $plan['reason'] : 'no subscription plan.'));
            }
            $lineItems = $plan['lineItems'];
        }

        $systemUrl = rtrim($systemUrl, '/');
        $metadata = [
            'whmcs_invoice_id' => (string) $invoiceId,
            'whmcs_system_url' => $systemUrl,
        ];
        if ($mode === 'subscription') {
            $metadata['whmcs_subscribed_items'] = (string) count($plan['subscribed']);
            $metadata['whmcs_subscriptions'] = (string) count($plan['groups']);
        }

        $body = [
            'mode'                       => $mode,
            'itemLocalCurrency'          => $currency,
            'lineItems'                  => $lineItems,
            'successUrl'                 => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true',
            'cancelUrl'                  => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true',
            'metadata'                   => $metadata,
            // WHMCS already charged this tax; it is inside the line items, never added on top.
            'itemTaxLocalCurrencyAmount' => (int) ($mapped['tax_minor'] ?? 0),
            'taxBehavior'                => 'inclusive',
        ];
        if (($mapped['tax_rate'] ?? 0) > 0) {
            $body['taxRate'] = (float) $mapped['tax_rate'];
        }
        if ($config->taxName !== '' && ($mapped['tax_minor'] ?? 0) > 0) {
            $body['taxName'] = $config->taxName;
        }

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
            $body['itemConvenienceFeeLocalCurrencyAmount'] = $fee;
        }
        return $body;
    }

    /** True when the created session kept every `recurring` (see recurringProblem). */
    public static function recurringAccepted(array $sentLineItems, array $session): bool
    {
        return self::recurringProblem($sentLineItems, $session) === '';
    }

    /**
     * Why the created session cannot be used for Subscribe & Auto-pay, or ''.
     * The session echoes its line items: an API without inline recurring
     * line items drops `recurring`, one without start dates drops `startsAt`
     * (it would charge a discounted item's renewal price now), and one that
     * cannot open several subscriptions has no `subscriptions` list. Each of
     * those sessions would charge something other than what the client was
     * shown, so the caller refuses it instead of redirecting the client.
     */
    public static function recurringProblem(array $sentLineItems, array $session): string
    {
        if (($session['mode'] ?? '') !== 'subscription') {
            return 'the session is not in subscription mode';
        }
        $echoed = $session['lineItems'] ?? null;
        if (!is_array($echoed) || count($echoed) !== count($sentLineItems)) {
            return 'the session did not return the line items sent';
        }
        $cadences = [];
        foreach ($sentLineItems as $i => $sent) {
            if (!isset($sent['recurring'])) {
                continue;
            }
            $r = $sent['recurring'];
            $got = $echoed[$i]['recurring'] ?? null;
            if (!is_array($got)
                || ($got['interval'] ?? '') !== $r['interval']
                || (int) ($got['intervalCount'] ?? 1) !== (int) $r['intervalCount']) {
                return 'lineItems[' . $i . '].recurring was not kept (inline recurring line items unsupported)';
            }
            $startsAt = (string) ($r['startsAt'] ?? '');
            if ($startsAt !== '' && substr((string) ($got['startsAt'] ?? ''), 0, 10) !== $startsAt) {
                return 'lineItems[' . $i . '].recurring.startsAt ' . $startsAt . ' was not kept (recurring start dates unsupported)';
            }
            $cadences[$r['interval'] . ':' . $r['intervalCount'] . ':' . $startsAt] = true;
        }
        if (count($cadences) > 1 && !is_array($session['subscriptions'] ?? null)) {
            return 'the session has ' . count($cadences) . ' billing groups but returned no subscriptions list '
                . '(several subscriptions in one checkout unsupported)';
        }
        return '';
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

    /**
     * POST /customers body for a WHMCS client. Everything WHMCS knows about
     * the client is sent, so the hosted checkout prefills it and does not ask
     * the client again (name, phone; email is also sent per session).
     *
     * @param array{email?: string, firstname?: string, lastname?: string, companyname?: string, phonenumber?: string, country?: string} $client tblclients row
     */
    public static function customerBody(array $client): array
    {
        $email = trim((string) ($client['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('The WHMCS client has no email address.');
        }
        $name = trim(trim((string) ($client['firstname'] ?? '')) . ' ' . trim((string) ($client['lastname'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($client['companyname'] ?? ''));
        }
        $body = ['name' => $name !== '' ? $name : $email, 'email' => $email];
        $phone = trim((string) ($client['phonenumber'] ?? ''));
        if ($phone !== '' && strlen($phone) <= 50) {
            $body['phone'] = $phone;
        }
        $country = strtoupper(trim((string) ($client['country'] ?? '')));
        if ($country !== '' && strlen($country) <= 5) {
            $body['country'] = $country;
        }
        return $body;
    }

    public static function convenienceFeeMinor(Config $config, int $subtotalMinor): int
    {
        if ($config->convenienceFeePercent === null || $config->convenienceFeePercent <= 0) {
            return 0;
        }
        return (int) round($subtotalMinor * $config->convenienceFeePercent / 100);
    }
}
