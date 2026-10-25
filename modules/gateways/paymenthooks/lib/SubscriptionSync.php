<?php
/**
 * Reconciles PaymentHooks subscription events into WHMCS invoices.
 *
 * PaymentHooks owns the billing clock: it charges each cycle and sends
 * subscription.renewed with the charge (latest_payment). WHMCS still raises the
 * renewal invoice from its own cron; this class applies the PaymentHooks
 * charge to that invoice.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class SubscriptionSync
{
    const GATEWAY = 'paymenthooks';

    /** @var Logger */
    private $logger;
    /** @var GatewayLog */
    private $log;

    public function __construct(Logger $logger, GatewayLog $log)
    {
        $this->logger = $logger;
        $this->log    = $log;
    }

    /**
     * Applies a renewal charge. Returns false (redeliver) when the WHMCS renewal
     * invoice does not exist yet. PaymentHooks redelivers after 1 min, 5 min,
     * 30 min and 2 h; WHMCS must raise renewal invoices ahead of the due date
     * (Automation Settings > Invoice Generation) so one exists by then.
     */
    public function handleRenewalPaid(string $subscriptionId, string $paymentId, int $amountMinor, string $currency): bool
    {
        $data = compact('subscriptionId', 'paymentId', 'amountMinor', 'currency');
        if (InvoiceMapper::transactionExists($paymentId, self::GATEWAY)) {
            $this->log->set(GatewayLog::DUPLICATE, 'Renewal payment ' . $paymentId . ' is already recorded.', $data);
            return true;
        }
        if ($amountMinor <= 0 || $currency === '') {
            return $this->attention('sub-renewal-malformed', $data, 'Renewal charge ' . $paymentId . ' has no amount or currency; apply it manually.');
        }

        $serviceIds = Capsule::table('tblhosting')->where('subscriptionid', $subscriptionId)->pluck('id')->all();
        if (!$serviceIds) {
            return $this->attention('sub-renewal-unknown', $data, 'No WHMCS service is bound to subscription ' . $subscriptionId . '; apply payment ' . $paymentId . ' manually.');
        }

        $invoice = $this->oldestUnpaidInvoice($serviceIds, $currency);
        if ($invoice === null) {
            // PaymentHooks redelivers 4 more times over ~2.5 h; each miss is
            // logged so a charge is never lost silently.
            $note = 'Renewal charged but no unpaid ' . $currency . ' invoice exists yet for service(s) ' . implode(',', $serviceIds)
                . '. Requesting redelivery; if it keeps failing, add payment ' . $paymentId . ' to the renewal invoice manually.';
            $this->logger->error('sub-renewal-no-invoice', $data, $note);
            $this->log->set(GatewayLog::RETRY, $note, $data);
            return false;
        }

        // Record what PaymentHooks actually charged. If it differs from the
        // balance (tax, credit, combined invoice), WHMCS shows the invoice
        // part-paid or in credit, and the mismatch is logged for the admin.
        $invoiceId = (int) $invoice['id'];
        $amount = InvoiceMapper::toMajorUnits($amountMinor, $currency);
        $data += ['invoiceId' => $invoiceId, 'balanceMinor' => $invoice['balanceMinor']];
        addInvoicePayment($invoiceId, $paymentId, $amount, 0, self::GATEWAY);
        if ($invoice['balanceMinor'] !== $amountMinor) {
            $note = 'Renewal of ' . $amount . ' ' . $currency . ' recorded on invoice ' . $invoiceId
                . ', but the invoice balance was ' . InvoiceMapper::toMajorUnits($invoice['balanceMinor'], $currency) . '; review the invoice.';
            $this->logger->error('sub-renewal-amount', $data, $note);
            $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        } else {
            $this->log->set(GatewayLog::RENEWAL, 'Applied ' . $amount . ' ' . $currency . ' to invoice ' . $invoiceId . '.', $data);
        }
        return true;
    }

    public function handleRenewalFailed(string $subscriptionId): void
    {
        $this->log->set(GatewayLog::FAILURE, 'Renewal charge failed for subscription ' . $subscriptionId
            . '. PaymentHooks retries it on its dunning schedule.', ['subscriptionId' => $subscriptionId]);
    }

    /** Subscription cancelled at PaymentHooks: stop treating the service as subscribed. */
    public function handleCancelled(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            $this->log->set(GatewayLog::NEEDS_ATTENTION, 'Cancellation event without a subscription id; acknowledged.');
            return;
        }
        Capsule::table('mod_paymenthooks_sessions')
            ->where('subscription_id', $subscriptionId)
            ->update(['status' => 'cancelled', 'updated_at' => time()]);
        Capsule::table('tblhosting')
            ->where('subscriptionid', $subscriptionId)
            ->update(['subscriptionid' => '']);
        $this->log->set(GatewayLog::CANCELLED, 'Subscription ' . $subscriptionId . ' was cancelled at PaymentHooks and unbound from its WHMCS service.',
            ['subscriptionId' => $subscriptionId]);
    }

    private function attention(string $action, array $data, string $note): bool
    {
        $this->logger->error($action, $data, $note);
        $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        return true;
    }

    /**
     * The oldest unpaid invoice carrying one of these services, in the charge's
     * currency, with its balance in minor units.
     *
     * @return array{id:int, balanceMinor:int}|null
     */
    private function oldestUnpaidInvoice(array $serviceIds, string $currency): ?array
    {
        $invoices = Capsule::table('tblinvoices')
            ->where('status', 'Unpaid')
            ->whereIn('id', function ($q) use ($serviceIds) {
                $q->select('invoiceid')
                    ->from('tblinvoiceitems')
                    ->whereIn('relid', $serviceIds)
                    ->where('type', 'Hosting');
            })
            ->orderBy('id', 'asc')
            ->get();

        foreach ($invoices as $inv) {
            if (WebhookRouter::invoiceCurrency((int) $inv->userid) !== $currency) {
                continue;
            }
            return ['id' => (int) $inv->id, 'balanceMinor' => InvoiceMapper::balanceMinor((int) $inv->id, $currency)];
        }
        return null;
    }
}
