<?php
/**
 * One Billing > Gateway Log entry per webhook delivery, whatever the outcome:
 * applied, failed, ignored, duplicate, rejected signature, or error. Handlers
 * record the outcome with set(); the callback writes it once at the end.
 *
 * WHMCS always keeps the Gateway Log (logTransaction), unlike the Module Log.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class GatewayLog
{
    const SUCCESSFUL      = 'Successful';
    const FAILURE         = 'Failure';
    const REFUND          = 'Refund Notification';
    const RENEWAL         = 'Subscription Renewal Paid';
    const CANCELLED       = 'Subscription Cancelled';
    const IGNORED         = 'Ignored';
    const DUPLICATE       = 'Duplicate';
    const BAD_SIGNATURE   = 'Invalid Signature';
    const BAD_PAYLOAD     = 'Invalid Payload';
    const NEEDS_ATTENTION = 'Needs Attention';
    const RETRY           = 'Retry Requested';
    const ERROR           = 'Error';

    /** @var string */
    private $status = self::IGNORED;
    /** @var array */
    private $data = [];
    /** @var string */
    private $note = 'No handler for this event type; acknowledged.';

    public function set(string $status, string $note, array $data = []): void
    {
        $this->status = $status;
        $this->note   = $note;
        $this->data   = $data;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** Writes the entry. $base identifies the delivery (event id, type, ...). */
    public function write(string $gateway, array $base, array $secrets = []): void
    {
        $entry = $base + ['result' => $this->note] + $this->data;
        foreach ($entry as $k => $v) {
            if (is_string($v)) {
                $entry[$k] = Logger::redact($v, $secrets);
            }
        }
        logTransaction($gateway, $entry, $this->status);
    }
}
