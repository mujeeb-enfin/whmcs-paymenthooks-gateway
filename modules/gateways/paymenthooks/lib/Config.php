<?php

/**
 * Resolves PaymentHooks connection settings from WHMCS gateway params.
 * Selects test vs live keys by Mode, and builds the API base URL from the
 * optional custom domain (falling back to PaymentHooks' own URL).
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Config
{
    /** Default PaymentHooks host when no custom domain is configured. */
    const DEFAULT_HOST = 'paymenthooks.com';

    /** @var string */
    public $mode;
    /** @var string resolved API base, e.g. https://paymenthooks.com/api/v1 */
    public $baseUrl;
    /** @var string */
    public $secretKey;
    /** @var string */
    public $publishableKey;
    /** @var string */
    public $webhookSecret;
    /** @var float|null */
    public $convenienceFeePercent;
    /** @var string */
    public $descriptionTemplate;
    /** @var bool */
    public $subscriptionsEnabled;
    /** @var string one of: auto, subscription, payment */
    public $paymentModePolicy;
    /** @var bool */
    public $logging;

    public static function fromParams(array $params): self
    {
        $c = new self();
        $c->mode = ($params['mode'] ?? 'test') === 'live' ? 'live' : 'test';

        $host = self::normaliseHost($params['customDomain'] ?? '');
        $c->baseUrl = 'https://' . $host . '/api/v1';

        if ($c->mode === 'live') {
            $c->secretKey      = trim($params['liveSecretKey'] ?? '');
            $c->publishableKey = trim($params['livePublishableKey'] ?? '');
        } else {
            $c->secretKey      = trim($params['testSecretKey'] ?? '');
            $c->publishableKey = trim($params['testPublishableKey'] ?? '');
        }

        $c->webhookSecret = trim($params['webhookSecret'] ?? '');

        $fee = trim((string) ($params['convenienceFeePercent'] ?? ''));
        $c->convenienceFeePercent = ($fee !== '' && is_numeric($fee)) ? (float) $fee : null;

        $c->descriptionTemplate  = $params['descriptionTemplate'] ?? 'Invoice {invoiceid}';
        $c->subscriptionsEnabled = !empty($params['enableSubscriptions']);

        $policy = $params['paymentModePolicy'] ?? 'auto';
        $c->paymentModePolicy = in_array($policy, ['auto', 'subscription', 'payment'], true) ? $policy : 'auto';

        $c->logging = !empty($params['logging']);

        return $c;
    }

    /**
     * Resolves the PH checkout session `mode` for an invoice given the admin
     * policy and whether the invoice contains a recurring item.
     *
     *   payment      -> always "payment" (one-time), even for recurring products
     *   subscription -> always "subscription" (force a mandate on every invoice)
     *   auto         -> "subscription" when recurring and subscriptions enabled, else "payment"
     *
     * "subscription" is only ever returned when subscriptions are enabled; if the
     * policy forces subscription but the feature is off, we fall back to one-time
     * so checkout never dead-ends.
     */
    public function resolveCheckoutMode(bool $invoiceHasRecurringItem): string
    {
        if ($this->paymentModePolicy === 'payment') {
            return 'payment';
        }

        if ($this->paymentModePolicy === 'subscription') {
            return $this->subscriptionsEnabled ? 'subscription' : 'payment';
        }

        // auto
        if ($this->subscriptionsEnabled && $invoiceHasRecurringItem) {
            return 'subscription';
        }
        return 'payment';
    }

    /**
     * Strips scheme, path, whitespace and trailing slashes from a user-entered
     * domain. Returns the default host when empty. Always HTTPS at the call site.
     */
    public static function normaliseHost(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return self::DEFAULT_HOST;
        }

        // Drop scheme if present so parse_url treats the rest as the host.
        $input = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $input);
        // Cut anything from the first slash onward (path/query).
        $input = explode('/', $input)[0];
        // Drop any port and surrounding whitespace.
        $input = trim($input);
        $input = explode(':', $input)[0];

        return $input !== '' ? strtolower($input) : self::DEFAULT_HOST;
    }

    /** Masks a secret for display/logging: sk_live_ab...yz */
    public static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 8) . '...' . substr($key, -2);
    }
}
