<?php
/**
 * Applies verified PaymentHooks webhook events to WHMCS.
 *
 * Handled events (subscribe the endpoint to these):
 *   checkout.session.completed, checkout.session.payment_failed,
 *   payment.refunded, subscription.renewed, subscription.renewal_failed,
 *   subscription.cancelled
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
            case 'subscription.renewal_failed':
                $sub = EventParser::subscription($event);
                $this->subSync->handleRenewalFailed($sub['subscriptionId']);
                return true;
            case 'subscription.cancelled':
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

        if ($s['subscriptionId'] !== '') {
            $this->bindSubscriptionToService($s['invoiceId'], $s['subscriptionId']);
        }
        return true;
    }

    /**
     * Stores the PH subscription id on the one recurring service it was created
     * for (PriceResolver priced the first recurring service). WHMCS passes it
     * back to paymenthooks_cancelSubscription() as $params['subscriptionID'].
     */
    private function bindSubscriptionToService(int $invoiceId, string $subscriptionId): void
    {
        $serviceId = PriceResolver::firstRecurringServiceId($invoiceId);
        if ($serviceId <= 0) {
            $this->logger->error('webhook-subscription-unbound', ['invoiceId' => $invoiceId, 'subscriptionId' => $subscriptionId],
                'No recurring service on invoice ' . $invoiceId . ' to bind subscription ' . $subscriptionId . ' to; renewals will not be recorded.');
            return;
        }
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['subscriptionid' => $subscriptionId]);
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
     * Refunds issued from WHMCS are recorded by WHMCS when paymenthooks_refund()
     * returns. A refund made in the PaymentHooks dashboard is logged here for
     * the admin to reflect in WHMCS.
     */
    private function onRefunded(array $event): bool
    {
        $r = EventParser::paymentRefunded($event);
        if ($r['paymentId'] === '' || !InvoiceMapper::transactionExists($r['paymentId'], self::GATEWAY)) {
            $this->log->set(GatewayLog::IGNORED, 'Refunded payment is not recorded in this WHMCS; acknowledged.', $r);
            return true;
        }
        $this->log->set(GatewayLog::REFUND, 'PaymentHooks reports ' . InvoiceMapper::toMajorUnits($r['amountRefundedMinor'], $r['currency'])
            . ' ' . $r['currency'] . ' refunded on payment ' . $r['paymentId'] . '. Refunds made in WHMCS are already recorded; '
            . 'one made in the PaymentHooks dashboard must be added to the invoice manually.', $r);
        return true;
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
