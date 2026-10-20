<?php
/**
 * Reconciles PaymentHooks subscription events into WHMCS invoices.
 *
 * PH owns the billing clock: it charges on its own schedule and emits renewal
 * events. WHMCS cannot charge a saved token on demand (PH has no off-session
 * charge API), so for subscribed services we let PH drive billing and mark the
 * matching WHMCS invoice paid when the renewal webhook arrives.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class SubscriptionSync
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * A subscription was charged successfully (subscription.renewed / invoice.paid).
     * Find the open WHMCS invoice for the service bound to this subscription and
     * mark it paid. $amountMinor/$currency describe the charge.
     */
    public function handleRenewalPaid(string $subscriptionId, string $paymentId, int $amountMinor, string $currency, int $feeMinor = 0): void
    {
        $gateway = 'paymenthooks';

        $invoiceId = $this->findOpenInvoiceForSubscription($subscriptionId, $amountMinor, $currency);
        if ($invoiceId === null) {
            // No open invoice yet — log and let WHMCS cron raise the renewal invoice;
            // a later duplicate webhook delivery (PH retries) will reconcile it.
            $this->logger->error('sub-renewal-no-invoice', ['subscriptionId' => $subscriptionId, 'paymentId' => $paymentId], 'No open WHMCS invoice found for subscription');
            return;
        }

        // Already recorded? Direct lookup — checkCbTransID die()s on a duplicate
        // and can't be branched on.
        if (InvoiceMapper::transactionExists($paymentId, $gateway)) {
            return;
        }

        $amount = InvoiceMapper::toMajorUnits($amountMinor, $currency);
        $fee    = InvoiceMapper::toMajorUnits($feeMinor, $currency);

        if (function_exists('addInvoicePayment')) {
            addInvoicePayment($invoiceId, $paymentId, $amount, $fee, $gateway);
        }
        if (function_exists('logTransaction')) {
            logTransaction($gateway, ['subscriptionId' => $subscriptionId, 'paymentId' => $paymentId, 'amount' => $amount], 'Subscription Renewal Paid');
        }
    }

    /** A renewal attempt failed (subscription.renewal_failed / invoice.payment_failed). */
    public function handleRenewalFailed(string $subscriptionId, ?string $retryUrl = null): void
    {
        $invoiceId = $this->findOpenInvoiceForSubscription($subscriptionId);
        if ($invoiceId !== null && function_exists('logActivity')) {
            logActivity('PaymentHooks: subscription ' . $subscriptionId . ' renewal failed for invoice ' . $invoiceId
                . ($retryUrl ? ' (retry: ' . $retryUrl . ')' : ''));
        }
        $this->logger->error('sub-renewal-failed', ['subscriptionId' => $subscriptionId, 'retryUrl' => $retryUrl], 'Renewal failed');
    }

    /** Subscription cancelled/deleted at PH. */
    public function handleCancelled(string $subscriptionId): void
    {
        Capsule::table('mod_paymenthooks_sessions')
            ->where('subscription_id', $subscriptionId)
            ->update(['status' => 'cancelled', 'updated_at' => time()]);

        if (function_exists('logActivity')) {
            logActivity('PaymentHooks: subscription ' . $subscriptionId . ' cancelled.');
        }
    }

    /**
     * Resolves the WHMCS invoice to pay for a given PH subscription.
     *
     * Primary strategy: the PH subscription id is stored on tblhosting.subscriptionid
     * (written when the setup payment completed). Find those services, then their
     * most recent Unpaid invoice.
     *
     * Fallback: the sessions table maps subscription_id -> the original invoice.
     *
     * When several unpaid invoices exist for the service, an invoice whose total
     * equals the charged amount is preferred, so a renewal is never applied to an
     * unrelated invoice of a different amount.
     */
    private function findOpenInvoiceForSubscription(string $subscriptionId, int $amountMinor = 0, string $currency = ''): ?int
    {
        // 1) Services directly bound to this PH subscription.
        $serviceIds = Capsule::table('tblhosting')
            ->where('subscriptionid', $subscriptionId)
            ->pluck('id')
            ->all();

        // 2) Fallback via the sessions table -> original invoice -> its services.
        if (!$serviceIds) {
            $map = Capsule::table('mod_paymenthooks_sessions')
                ->where('subscription_id', $subscriptionId)
                ->orderBy('id', 'desc')
                ->first();
            if (!$map) {
                return null;
            }
            $serviceIds = Capsule::table('tblhosting')
                ->whereIn('id', function ($q) use ($map) {
                    $q->select('relid')
                        ->from('tblinvoiceitems')
                        ->where('invoiceid', $map->invoice_id)
                        ->where('type', 'Hosting');
                })
                ->pluck('id')
                ->all();

            if (!$serviceIds) {
                // Last resort: the original invoice itself if still unpaid.
                $inv = Capsule::table('tblinvoices')
                    ->where('id', $map->invoice_id)
                    ->where('status', 'Unpaid')
                    ->first();
                return $inv ? (int) $inv->id : null;
            }
        }

        // All unpaid invoices carrying any of those services, newest first.
        $invoices = Capsule::table('tblinvoices')
            ->where('status', 'Unpaid')
            ->whereIn('id', function ($q) use ($serviceIds) {
                $q->select('invoiceid')
                    ->from('tblinvoiceitems')
                    ->whereIn('relid', $serviceIds)
                    ->where('type', 'Hosting');
            })
            ->orderBy('id', 'desc')
            ->get();

        if ($invoices->isEmpty()) {
            return null;
        }

        // Prefer an invoice whose total matches the charged amount.
        if ($amountMinor > 0 && $currency !== '') {
            foreach ($invoices as $inv) {
                $totalMinor = InvoiceMapper::toMinorUnits((float) $inv->total, $currency);
                if ($totalMinor === $amountMinor) {
                    return (int) $inv->id;
                }
            }
        }

        // Otherwise fall back to the most recent unpaid invoice.
        return (int) $invoices->first()->id;
    }
}
