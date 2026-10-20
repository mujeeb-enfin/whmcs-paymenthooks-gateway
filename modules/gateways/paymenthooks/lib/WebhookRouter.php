<?php
/**
 * Dispatches a verified PaymentHooks webhook event to the right handler and
 * applies the result to WHMCS invoices.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class WebhookRouter
{
    /** @var Logger */
    private $logger;
    /** @var SubscriptionSync */
    private $subSync;

    public function __construct(Logger $logger)
    {
        $this->logger  = $logger;
        $this->subSync = new SubscriptionSync($logger);
    }

    /**
     * @param array $event the decoded PH event envelope
     * @return bool true if handled (respond 200), false to signal a retry
     */
    public function dispatch(array $event): bool
    {
        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? ($event['data'] ?? []);

        switch ($type) {
            case 'checkout.session.completed':
                return $this->onCheckoutCompleted($data);

            case 'checkout.session.payment_failed':
                return $this->onCheckoutFailed($data);

            case 'payment.refunded':
            case 'charge.refunded':
                return $this->onRefunded($data);

            case 'subscription.renewed':
            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                return $this->onRenewalPaid($data);

            case 'subscription.renewal_failed':
            case 'invoice.payment_failed':
                return $this->onRenewalFailed($data);

            case 'subscription.cancelled':
            case 'subscription.canceled':
            case 'subscription.deleted':
                return $this->onSubscriptionCancelled($data);

            default:
                // Unhandled event types are acknowledged so PH stops retrying.
                $this->logger->call('webhook-ignored', ['type' => $type], 'No handler; acknowledged');
                return true;
        }
    }

    private function onCheckoutCompleted(array $data): bool
    {
        $invoiceId = $this->resolveInvoiceId($data);
        $paymentId = $data['paymentId'] ?? ($data['payment_id'] ?? ($data['id'] ?? ''));
        $totalMinor = (int) ($data['amount'] ?? ($data['amountTotal'] ?? 0));
        $currency  = strtoupper($data['currency'] ?? '');

        // A completed checkout with no usable payment id cannot be reconciled.
        // Return false so PH retries rather than silently dropping the payment.
        if ($paymentId === '') {
            $this->logger->error('webhook-completed-no-payid', $data, 'No payment id on completed session; requesting retry');
            return false;
        }
        if ($invoiceId <= 0) {
            $this->logger->error('webhook-completed-no-invoice', $data, 'Cannot map session to a WHMCS invoice; requesting retry');
            return false;
        }

        if (function_exists('checkCbInvoiceID')) {
            checkCbInvoiceID($invoiceId, 'paymenthooks');
        }
        // Already recorded? (Direct lookup — checkCbTransID die()s, so we can't
        // branch on it.) A duplicate payment is a success, not a retry.
        if (InvoiceMapper::transactionExists($paymentId)) {
            return true;
        }

        // PH may have collected a convenience fee on top of the invoice subtotal.
        // The amount applied to the invoice must equal its balance (subtotal), so
        // split the fee out and record it in WHMCS's fee column, not the amount.
        $feeMinor = $this->resolveFeeMinor($data);
        if ($feeMinor < 0 || $feeMinor > $totalMinor) {
            $feeMinor = 0; // never trust a fee that exceeds the total
        }
        $appliedMinor = $totalMinor - $feeMinor;

        $amount = InvoiceMapper::toMajorUnits($appliedMinor, $currency);
        $fee    = InvoiceMapper::toMajorUnits($feeMinor, $currency);
        if (function_exists('addInvoicePayment')) {
            addInvoicePayment($invoiceId, $paymentId, $amount, $fee, 'paymenthooks');
        }

        // Stamp only the session that produced this payment; capture the
        // subscription id if the event carries one (recurring setup).
        $subscriptionId = self::extractSubscriptionId($data);
        $update = ['payment_id' => $paymentId, 'status' => 'completed', 'updated_at' => time()];
        if ($subscriptionId !== '') {
            $update['subscription_id'] = $subscriptionId;
        }

        $sessionId = $data['sessionId'] ?? ($data['id'] ?? '');
        $q = Capsule::table('mod_paymenthooks_sessions')->where('invoice_id', $invoiceId);
        if ($sessionId !== '') {
            $q->where('session_id', $sessionId);
        }
        $q->update($update);

        // Record the PH subscription id on the WHMCS service(s) so WHMCS's
        // "Cancel" action (paymenthooks_cancelSubscription) can reach PH, and so
        // renewal webhooks can map back to a service.
        if ($subscriptionId !== '') {
            $this->bindSubscriptionToServices($invoiceId, $subscriptionId);
        }

        return true;
    }

    /**
     * Writes the PH subscription id onto the ONE recurring service the
     * subscription was created for (PriceResolver bills the first recurring
     * service and sends a single priceId). Binding every service on the invoice
     * would falsely mark co-invoiced one-time/other services as subscribed and
     * misroute later renewal webhooks. WHMCS passes this value to
     * paymenthooks_cancelSubscription($params['subscriptionID']).
     */
    private function bindSubscriptionToServices(int $invoiceId, string $subscriptionId): void
    {
        $serviceId = PriceResolver::firstRecurringServiceId($invoiceId);
        if ($serviceId <= 0) {
            return;
        }
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['subscriptionid' => $subscriptionId]);
    }

    private function onCheckoutFailed(array $data): bool
    {
        $invoiceId = $this->resolveInvoiceId($data);
        if ($invoiceId > 0 && function_exists('logActivity')) {
            logActivity('PaymentHooks: payment failed for invoice ' . $invoiceId);
        }
        $this->logger->call('webhook-payment-failed', $data, 'Logged');
        return true;
    }

    private function onRefunded(array $data): bool
    {
        $paymentId = $data['paymentId'] ?? ($data['id'] ?? '');
        if (function_exists('logTransaction')) {
            logTransaction('paymenthooks', $data, 'Refund (' . $paymentId . ')');
        }
        return true;
    }

    private function onRenewalPaid(array $data): bool
    {
        $subscriptionId = self::extractSubscriptionId($data);
        $paymentId = $data['paymentId'] ?? ($data['payment_id'] ?? ($data['id'] ?? ''));
        $totalMinor = (int) ($data['amount'] ?? ($data['amountTotal'] ?? ($data['amountPaid'] ?? 0)));
        $currency = strtoupper($data['currency'] ?? '');

        if ($subscriptionId === '' || $paymentId === '') {
            // Not enough to reconcile; acknowledge to avoid infinite retries.
            $this->logger->error('webhook-renewal-bad', $data, 'Missing subscription or payment id');
            return true;
        }

        // Split any convenience fee out of the charged total, same as one-time:
        // the invoice payment must equal the invoice balance (net), with the fee
        // recorded separately rather than inflating the amount.
        $feeMinor = $this->resolveFeeMinor($data);
        if ($feeMinor < 0 || $feeMinor > $totalMinor) {
            $feeMinor = 0;
        }
        $netMinor = $totalMinor - $feeMinor;

        $this->subSync->handleRenewalPaid($subscriptionId, $paymentId, $netMinor, $currency, $feeMinor);
        return true;
    }

    private function onRenewalFailed(array $data): bool
    {
        $subscriptionId = self::extractSubscriptionId($data);
        $retryUrl = $data['retryUrl'] ?? ($data['hostedUrl'] ?? null);
        if ($subscriptionId !== '') {
            $this->subSync->handleRenewalFailed($subscriptionId, $retryUrl);
        }
        return true;
    }

    private function onSubscriptionCancelled(array $data): bool
    {
        // For subscription.* events the object's own id IS the subscription id.
        $subscriptionId = self::scalarId($data['id'] ?? null);
        if ($subscriptionId === '') {
            $subscriptionId = self::extractSubscriptionId($data);
        }
        if ($subscriptionId !== '') {
            $this->subSync->handleCancelled($subscriptionId);
        }
        return true;
    }

    /**
     * Reads the PH subscription id from an event payload, tolerating the field
     * spellings PH may use and ignoring non-scalar values (e.g. if PH nests the
     * subscription as an object rather than an id string).
     *
     * Public static so it can be unit-tested without WHMCS.
     */
    public static function extractSubscriptionId(array $data): string
    {
        foreach (['subscriptionId', 'subscription_id', 'subscription'] as $k) {
            if (isset($data[$k])) {
                $id = self::scalarId($data[$k]);
                if ($id !== '') {
                    return $id;
                }
                // Nested object form: {"subscription": {"id": "sub_..."}}
                if (is_array($data[$k]) && isset($data[$k]['id'])) {
                    $nested = self::scalarId($data[$k]['id']);
                    if ($nested !== '') {
                        return $nested;
                    }
                }
            }
        }
        return '';
    }

    /** Returns a trimmed string only for scalar input; '' for arrays/objects/null. */
    public static function scalarId($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /**
     * Extracts the convenience fee (minor units) from a completed-session event,
     * tolerating the field spellings PH may use. 0 if none present.
     */
    private function resolveFeeMinor(array $data): int
    {
        foreach (['convenienceFee', 'convenience_fee', 'feeAmount', 'fee'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                return (int) $data[$k];
            }
        }
        return 0;
    }

    /**
     * Pulls the WHMCS invoice id from the event: metadata first (we set it at
     * session create), then our local sessions table keyed by session id.
     */
    private function resolveInvoiceId(array $data): int
    {
        $meta = $data['metadata'] ?? [];
        if (isset($meta['whmcs_invoice_id']) && (int) $meta['whmcs_invoice_id'] > 0) {
            return (int) $meta['whmcs_invoice_id'];
        }

        $sessionId = $data['sessionId'] ?? ($data['id'] ?? '');
        if ($sessionId !== '') {
            $row = Capsule::table('mod_paymenthooks_sessions')
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->first();
            if ($row) {
                return (int) $row->invoice_id;
            }
        }

        return 0;
    }
}
