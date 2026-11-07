<?php

/**
 * Resolves PaymentHooks connection settings from WHMCS gateway params.
 *
 * PaymentHooks issues one secret key per business (phk_live_...). Whether that
 * key takes real or simulated payments is the business's Platform Mode in the
 * PaymentHooks dashboard, not the key, so there is no test/live key pair here.
 *
 * API calls always go to paymenthooks.com: a custom domain serves only the
 * customer-facing checkout pages, never the merchant API.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Config
{
    const API_BASE = 'https://paymenthooks.com/api/v1';
    const SECRET_PREFIX = 'phk_live_';
    const PUBLISHABLE_PREFIX = 'phk_pub_';
    const WEBHOOK_SECRET_PREFIX = 'whsec_';

    /** @var string */
    public $baseUrl = self::API_BASE;
    /** @var string */
    public $secretKey = '';
    /** @var string */
    public $publishableKey = '';
    /** @var string */
    public $webhookSecret = '';
    /** @var string custom checkout host, or '' for paymenthooks.com */
    public $checkoutHost = '';
    /** @var float|null */
    public $convenienceFeePercent;
    /** @var string */
    public $descriptionTemplate = 'Invoice {invoiceid}';
    /** @var string name of the invoice tax (GST, VAT), '' for "Tax" */
    public $taxName = '';
    /** @var bool shows the "Subscribe & Auto-pay" button next to "Pay Now" */
    public $subscriptionsEnabled = false;
    /** @var bool */
    public $logging = false;

    public static function fromParams(array $params): self
    {
        $c = new self();
        $c->secretKey      = trim((string) ($params['secretKey'] ?? ''));
        $c->publishableKey = trim((string) ($params['publishableKey'] ?? ''));
        $c->webhookSecret  = trim((string) ($params['webhookSecret'] ?? ''));
        $c->checkoutHost   = self::normaliseHost((string) ($params['customDomain'] ?? ''));

        $fee = trim((string) ($params['convenienceFeePercent'] ?? ''));
        $c->convenienceFeePercent = ($fee !== '' && is_numeric($fee)) ? (float) $fee : null;

        $template = trim((string) ($params['descriptionTemplate'] ?? ''));
        $c->descriptionTemplate  = $template !== '' ? $template : 'Invoice {invoiceid}';
        $c->taxName = mb_substr(trim((string) ($params['taxName'] ?? '')), 0, 60);
        $c->subscriptionsEnabled = self::migratedSubscriptionsEnabled(
            isset($params['paymentModePolicy']) ? (string) $params['paymentModePolicy'] : null,
            !empty($params['enableSubscriptions']) && $params['enableSubscriptions'] !== 'off'
        );

        $c->logging = !empty($params['logging']) && $params['logging'] !== 'off';

        return $c;
    }

    /**
     * Problems that make the module unusable, as admin-readable sentences.
     * Callers fail hard on any of these rather than calling the API.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->secretKey === '') {
            $errors[] = 'Secret Key is not set.';
        } elseif (strpos($this->secretKey, self::SECRET_PREFIX) !== 0) {
            $errors[] = 'Secret Key must start with ' . self::SECRET_PREFIX . ' (create one under Developers > API Keys).';
        }
        if ($this->publishableKey !== '' && strpos($this->publishableKey, self::PUBLISHABLE_PREFIX) !== 0) {
            $errors[] = 'Publishable Key must start with ' . self::PUBLISHABLE_PREFIX . '.';
        }
        if ($this->webhookSecret === '') {
            $errors[] = 'Webhook Signing Secret is not set.';
        } elseif (strpos($this->webhookSecret, self::WEBHOOK_SECRET_PREFIX) !== 0) {
            $errors[] = 'Webhook Signing Secret must start with ' . self::WEBHOOK_SECRET_PREFIX . '.';
        }
        if ($this->convenienceFeePercent !== null && ($this->convenienceFeePercent < 0 || $this->convenienceFeePercent > 100)) {
            $errors[] = 'Convenience Fee % must be between 0 and 100.';
        }
        return $errors;
    }

    /**
     * The checkout mode for the button the client pressed. The client chooses:
     * "Pay Now" is always one-time; "Subscribe & Auto-pay" starts a
     * PaymentHooks subscription and is only offered when the admin enabled it.
     * Anything other than an explicit "subscription" is a one-time payment
     * (older templates post no mode at all).
     *
     * @throws ConfigException when a subscription is requested but the admin turned it off
     */
    public function resolveCheckoutMode(string $requested): string
    {
        if ($requested !== 'subscription') {
            return 'payment';
        }
        if (!$this->subscriptionsEnabled) {
            throw new ConfigException('Subscribe & Auto-pay was requested but Enable Subscriptions is off in the module settings.');
        }
        return 'subscription';
    }

    /**
     * Settings migration from the retired "Payment Mode" dropdown. It forced a
     * mode per invoice; now the client chooses and the admin only enables the
     * Subscribe button. "One-time only" meant "never subscribe", so it turns
     * the button off; "Auto" and "Subscription only" keep the admin's
     * Enable Subscriptions choice. Schema::ensure() writes the result back
     * once and deletes the old setting.
     */
    public static function migratedSubscriptionsEnabled(?string $legacyPolicy, bool $enabled): bool
    {
        if ($legacyPolicy === 'payment') {
            return false;
        }
        return $enabled;
    }

    /**
     * Points a hosted checkout URL at the merchant's custom checkout domain.
     * The checkout page is served on custom domains; only the host changes.
     */
    public function checkoutUrl(string $url): string
    {
        if ($this->checkoutHost === '') {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return 'https://' . $this->checkoutHost . $parts['path'] . $query;
    }

    /**
     * Strips scheme, path, port and whitespace from a user-entered domain.
     * Returns '' when empty or when it is PaymentHooks' own host.
     */
    public static function normaliseHost(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        $input = (string) preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $input);
        $input = explode('/', $input)[0];
        $input = strtolower(trim(explode(':', $input)[0]));
        if ($input === 'paymenthooks.com' || $input === 'www.paymenthooks.com') {
            return '';
        }
        return $input;
    }

    /** Masks a secret for display/logging: phk_live...9f */
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

class ConfigException extends \Exception
{
}
