<?php
/**
 * Applies verified PaymentHooks webhook events to WHMCS.
 *
 * Handled events (subscribe the endpoint to these):
 *   checkout.session.completed, checkout.session.payment_failed,
 *   payment.refunded, subscription.renewed, subscription.renewal_failed,
 *   subscription.cancelled, subscription.deleted, invoice.paid
 *
 * dispatch() returns true when the event is fully handled (respond 200) and
 * false when it must be redelivered (respond 500). An event that can never be
 * applied (not ours, malformed) is acknowledged. Every outcome is recorded on
 * the GatewayLog, which the callback writes to the WHMCS Gateway Log.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class WebhookRouter
{
    const GATEWAY = 'paymenthooks';

    /** @var Logger */
    private $logger;
    /** @var GatewayLog */
    private $log;
    /** @var SubscriptionSync */
    private $subSync;

    public function __construct(Logger $logger, GatewayLog $log)
    {
        $this->logger  = $logger;
        $this->log     = $log;
        $this->subSync = new SubscriptionSync($logger, $log);
    }

    public function dispatch(array $event): bool
    {
        $type = (string) ($event['type'] ?? '');
        switch ($type) {
            case 'checkout.session.completed':
                return $this->onCheckoutCompleted($event);
            case 'checkout.session.payment_failed':
                return $this->onCheckoutFailed($event);
            case 'payment.refunded':
                return $this->onRefunded($event);
            case 'subscription.renewed':
                return $this->onRenewed($event);
            case 'invoice.paid':
                return $this->onInvoicePaid($event);
            case 'subscription.renewal_failed':
                $sub = EventParser::subscription($event);
                $this->subSync->handleRenewalFailed($sub['subscriptionId'], $sub['failureReason']);
                return true;
            case 'subscription.cancelled':
            case 'subscription.deleted':
                $sub = EventParser::subscription($event);
                $this->subSync->handleCancelled($sub['subscriptionId']);
                return true;
            default:
                $this->log->set(GatewayLog::IGNORED, 'No handler for ' . $type . '; acknowledged. '
                    . 'Unsubscribe the endpoint from it in PaymentHooks if it is not needed.');
                return true;
        }
    }

    private function onCheckoutCompleted(array $event): bool
    {
        $s = EventParser::checkoutCompleted($event);

        if ($s['invoiceId'] <= 0) {
            $this->log->set(GatewayLog::IGNORED, 'Checkout was not created by this WHMCS (no whmcs_invoice_id); acknowledged.', $s);
            return true;
        }
        if (!$s['paid'] || $s['paymentId'] === '' || $s['amountMinor'] <= 0 || $s['currency'] === '') {
            return $this->attention('webhook-completed-malformed', $s, 'Completed checkout without payment id, amount or currency; apply it manually.');
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $s['invoiceId'])->first();
        if (!$invoice) {
            return $this->attention('webhook-completed-no-invoice', $s, 'Invoice ' . $s['invoiceId'] . ' does not exist in WHMCS; payment ' . $s['paymentId'] . ' not applied.');
        }
        $invoiceCurrency = self::invoiceCurrency((int) $invoice->userid);
        if ($invoiceCurrency !== $s['currency']) {
            return $this->attention('webhook-completed-currency', $s + ['invoiceCurrency' => $invoiceCurrency],
                'Payment ' . $s['paymentId'] . ' was in ' . $s['currency'] . ' but invoice ' . $s['invoiceId'] . ' is in ' . $invoiceCurrency . '; not applied, record it manually.');
        }

        $feeMinor = $this->sessionFeeMinor($s['sessionId']);
        if ($feeMinor < 0 || $feeMinor >= $s['amountMinor']) {
            $feeMinor = 0;
        }
        $applied = $s + ['convenienceFee' => InvoiceMapper::toMajorUnits($feeMinor, $s['currency'])];

        if (InvoiceMapper::transactionExists($s['paymentId'], self::GATEWAY)) {
            $this->log->set(GatewayLog::DUPLICATE, 'Payment ' . $s['paymentId'] . ' is already recorded on invoice ' . $s['invoiceId'] . '.', $applied);
        } else {
            // The convenience fee was paid by the client on top of the balance.
            // Credit the invoice with the balance part only; WHMCS's fee argument
            // means a gateway cost to the merchant, so it is not used.
            $amount = InvoiceMapper::toMajorUnits($s['amountMinor'] - $feeMinor, $s['currency']);
            addInvoicePayment($s['invoiceId'], $s['paymentId'], $amount, 0, self::GATEWAY);
            $this->log->set(GatewayLog::SUCCESSFUL, 'Applied ' . $amount . ' ' . $s['currency'] . ' to invoice ' . $s['invoiceId'] . '.', $applied);
        }

        $update = ['payment_id' => $s['paymentId'], 'status' => 'completed', 'updated_at' => time()];
        if ($s['subscriptionId'] !== '') {
            $update['subscription_id'] = $s['subscriptionId'];
        }
        Capsule::table('mod_paymenthooks_sessions')
            ->where('invoice_id', $s['invoiceId'])
            ->where('session_id', $s['sessionId'])
            ->update($update);

        if ($s['subscriptionId'] !== '' || $s['subscriptions']) {
            $this->bindSubscriptions($s, $applied);
        }
        return true;
    }

    /**
     * Binds each subscription the checkout opened to the items of its billing
     * group (the plan stored with the session when it was created). One
     * group: the session's `subscription`. Several groups: matched through
     * the session's `subscriptions` list (SubscriptionMatcher); a group that
     * cannot be matched is left unbound and flagged. Sessions opened by
     * earlier module versions store only `subscribed` (one subscription), or
     * no plan at all (the first service).
     */
    private function bindSubscriptions(array $s, array $applied): void
    {
        $invoiceId = $s['invoiceId'];
        $currency = $s['currency'];
        $planJson = (string) Capsule::table('mod_paymenthooks_sessions')->where('session_id', $s['sessionId'])->value('plan_json');
        $plan = $planJson !== '' ? json_decode($planJson, true) : null;
        $groups = is_array($plan) && is_array($plan['groups'] ?? null) ? $plan['groups'] : [];

        if (count($groups) > 1 || ($groups && $s['subscriptions'] && count($s['subscriptions']) > 1)) {
            $result = SubscriptionMatcher::match($groups, $s['subscriptions']);
            $bound = [];
            foreach ($result['matched'] as $g => $subscriptionId) {
                SubscriptionMap::bind($subscriptionId, $invoiceId, $currency, $groups[$g]['items']);
                $bound[] = $subscriptionId . ' (' . SubscriptionSync::describeGroup($groups[$g]) . ')';
            }
            if ($result['unmatched'] || $result['unused']) {
                $missing = array_map(static function ($g) use ($groups) {
                    return SubscriptionSync::describeGroup($groups[$g]);
                }, $result['unmatched']);
                $note = 'Payment applied to invoice ' . $invoiceId . '. Bound ' . ($bound ? implode('; ', $bound) : 'no subscription') . '. '
                    . ($missing ? 'Could not tell which subscription renews ' . implode('; ', $missing) . '; ' : '')
                    . ($result['unused'] ? 'subscriptions not bound: ' . implode(', ', $result['unused']) . '; ' : '')
                    . 'their renewals will not be recorded until bound. Check the subscriptions in PaymentHooks.';
                $this->logger->error('webhook-subscriptions-unmatched', $applied + ['groups' => count($groups), 'subscriptions' => count($s['subscriptions'])], $note);
                $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $applied);
                return;
            }
            $this->log->set($this->log->status(), $this->log->note() . ' Auto-pay: ' . implode('; ', $bound) . '.', $applied);
            return;
        }

        $subscriptionId = $s['subscriptionId'] !== '' ? $s['subscriptionId'] : (string) ($s['subscriptions'][0]['id'] ?? '');
        $items = $groups ? $groups[0]['items'] : (is_array($plan) && !empty($plan['subscribed']) ? $plan['subscribed'] : []);
        if ($items) {
            SubscriptionMap::bind($subscriptionId, $invoiceId, $currency, $items);
            return;
        }
        // Sessions opened by module versions before 3.1.0: the first service.
        $serviceId = PriceResolver::firstRecurringServiceId($invoiceId);
        if ($serviceId <= 0) {
            $this->logger->error('webhook-subscription-unbound', ['invoiceId' => $invoiceId, 'subscriptionId' => $subscriptionId],
                'No recurring item recorded for invoice ' . $invoiceId . ' to bind subscription ' . $subscriptionId . ' to; renewals will not be recorded.');
            return;
        }
        $row = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $cycle = $row ? (string) $row->billingcycle : '';
        SubscriptionMap::bind($subscriptionId, $invoiceId, $currency, [[
            'relType'       => 'hosting',
            'relId'         => $serviceId,
            'renewalMinor'  => $row ? InvoiceMapper::toMinorUnits((float) $row->amount, $currency) : 0,
            'interval'      => (string) PriceResolver::cycleToInterval($cycle),
            'intervalCount' => PriceResolver::cycleToIntervalCount($cycle),
        ]]);
    }

    /**
     * A paid PaymentHooks invoice of a subscription bound here is a renewal.
     * The first invoice is the checkout, already recorded from
     * checkout.session.completed with the same payment id (Duplicate here).
     */
    private function onInvoicePaid(array $event): bool
    {
        $inv = EventParser::invoicePaid($event);
        if ($inv['subscriptionId'] === '') {
            $this->log->set(GatewayLog::IGNORED, 'Invoice ' . $inv['invoiceId'] . ' is not a subscription invoice; acknowledged.', $inv);
            return true;
        }
        if ($inv['paymentId'] === '') {
            $this->log->set(GatewayLog::IGNORED, 'Invoice ' . $inv['invoiceId'] . ' was paid without a charge (credit); nothing to record.', $inv);
            return true;
        }
        return $this->subSync->handleRenewalPaid($inv['subscriptionId'], $inv['paymentId'], $inv['amountMinor'], $inv['currency'], true);
    }

    private function onCheckoutFailed(array $event): bool
    {
        $o = EventParser::object($event);
        $invoiceId = EventParser::invoiceId($o);
        $data = ['sessionId' => (string) ($o['id'] ?? ''), 'invoiceId' => $invoiceId];
        if ($invoiceId <= 0) {
            $this->log->set(GatewayLog::IGNORED, 'Failed checkout was not created by this WHMCS; acknowledged.', $data);
            return true;
        }
        $this->log->set(GatewayLog::FAILURE, 'Payment attempt failed for invoice ' . $invoiceId . '; the invoice stays unpaid.', $data);
        return true;
    }

    /**
     * A refund made at PaymentHooks (dashboard or API) is recorded on the
     * WHMCS invoice; refunds issued from WHMCS are already known (RefundSync).
     */
    private function onRefunded(array $event): bool
    {
        $r = EventParser::paymentRefunded($event);
        if ($r['paymentId'] === '' || $r['amountRefundedMinor'] <= 0 || $r['currency'] === '') {
            $this->log->set(GatewayLog::IGNORED, 'Refund event without payment id, amount or currency; acknowledged.', $r);
            return true;
        }
        return (new RefundSync($this->logger, $this->log))->apply($r);
    }

    private function onRenewed(array $event): bool
    {
        $sub = EventParser::subscription($event);
        if ($sub['subscriptionId'] === '') {
            return $this->attention('webhook-renewal-malformed', $sub, 'Renewal without a subscription id; acknowledged.');
        }
        if ($sub['paymentId'] === '') {
            $this->log->set(GatewayLog::IGNORED, 'Renewal fully covered by credit; nothing was charged.', $sub);
            return true;
        }
        return $this->subSync->handleRenewalPaid($sub['subscriptionId'], $sub['paymentId'], $sub['amountMinor'], $sub['currency']);
    }

    /** Acknowledges an event that cannot be applied automatically, loudly. */
    private function attention(string $action, array $data, string $note): bool
    {
        $this->logger->error($action, $data, $note);
        $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        return true;
    }

    private function sessionFeeMinor(string $sessionId): int
    {
        if ($sessionId === '') {
            return 0;
        }
        return (int) Capsule::table('mod_paymenthooks_sessions')->where('session_id', $sessionId)->value('fee_minor');
    }

    /** ISO code of the client's currency; '' if unknown. */
    public static function invoiceCurrency(int $clientId): string
    {
        $code = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $clientId)
            ->value('tblcurrencies.code');
        return $code ? strtoupper((string) $code) : '';
    }
}
