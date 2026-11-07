<?php
/**
 * Applies PaymentHooks subscription events to WHMCS. PaymentHooks owns the
 * billing: it charges every renewal through the connected gateway and sends
 * the result. WHMCS never charges; it only raises and tracks invoices, and
 * the module records PaymentHooks' charges on them.
 *
 *   subscription.renewed / invoice.paid  record the charge on the renewal
 *                                        invoice(s) of the subscribed items
 *   subscription.renewal_failed          note on the invoice; WHMCS's own
 *                                        overdue reminders and suspension run
 *   subscription.cancelled / .deleted    unbind the items (they no longer
 *                                        auto-renew through PaymentHooks)
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

    /** Active items of the subscription, including services bound by earlier module versions. */
    private function items(string $subscriptionId): array
    {
        $items = SubscriptionMap::items($subscriptionId);
        return $items ?: SubscriptionMap::legacyItems($subscriptionId);
    }

    /**
     * Records a renewal charge. Returns false (PaymentHooks redelivers) only
     * when an item is due but WHMCS has no invoice for it even after
     * GenInvoices; every other outcome is final and logged.
     *
     * @param bool $unboundIsIgnored invoice.paid for a subscription this WHMCS did not bind (its first invoice is
     *                               handled by checkout.session.completed)
     */
    public function handleRenewalPaid(string $subscriptionId, string $paymentId, int $amountMinor, string $currency, bool $unboundIsIgnored = false): bool
    {
        $data = compact('subscriptionId', 'paymentId', 'amountMinor', 'currency');
        if (InvoiceMapper::transactionExists($paymentId, self::GATEWAY)) {
            $this->log->set(GatewayLog::DUPLICATE, 'Renewal payment ' . $paymentId . ' is already recorded.', $data);
            return true;
        }
        if ($amountMinor <= 0 || $currency === '') {
            return $this->attention('sub-renewal-malformed', $data, 'Renewal charge ' . $paymentId . ' has no amount or currency; apply it manually.');
        }

        $items = $this->items($subscriptionId);
        if (!$items) {
            if ($unboundIsIgnored) {
                $this->log->set(GatewayLog::IGNORED, 'Invoice paid for subscription ' . $subscriptionId . ', which no WHMCS item renews through; '
                    . 'first payments are recorded from checkout.session.completed.', $data);
                return true;
            }
            return $this->attention('sub-renewal-unknown', $data, 'No WHMCS service, domain or addon is bound to subscription ' . $subscriptionId
                . '; apply payment ' . $paymentId . ' manually.');
        }
        $data['items'] = self::describe($items);

        $found = (new RenewalInvoice($this->logger))->locate($items, $currency, true);
        if ($found['missing']) {
            $note = 'Renewal charged, but WHMCS has no unpaid ' . $currency . ' renewal invoice for ' . self::describe($found['missing'])
                . ($found['generated'] ? ' even after generating invoices' : '') . '. Requesting redelivery; if it keeps failing, add payment '
                . $paymentId . ' to the renewal invoice manually.';
            $this->logger->error('sub-renewal-no-invoice', $data, $note);
            $this->log->set(GatewayLog::RETRY, $note, $data);
            return false;
        }
        if (!$found['invoices']) {
            return $this->attention('sub-renewal-already-paid', $data, 'Renewal ' . $paymentId . ' of ' . InvoiceMapper::toMajorUnits($amountMinor, $currency)
                . ' ' . $currency . ' was charged, but WHMCS shows ' . self::describe($items) . ' paid and not yet due. Not applied: '
                . 'add it as credit or refund it from PaymentHooks.');
        }

        $split = self::split($amountMinor, $found['invoices']);
        $applied = [];
        foreach ($split as $invoiceId => $partMinor) {
            if ($partMinor <= 0) {
                continue;
            }
            addInvoicePayment($invoiceId, $paymentId, InvoiceMapper::toMajorUnits($partMinor, $currency), 0, self::GATEWAY);
            $applied[] = InvoiceMapper::toMajorUnits($partMinor, $currency) . ' ' . $currency . ' to invoice ' . $invoiceId;
        }
        $balance = array_sum(array_column($found['invoices'], 'balanceMinor'));
        $data += ['invoiceIds' => implode(',', array_keys($split)), 'balanceMinor' => $balance, 'generatedInvoice' => $found['generated']];
        $alsoPaid = $found['alreadyPaid'] ? ' ' . self::describe($found['alreadyPaid']) . ' had no invoice due.' : '';
        if ($balance !== $amountMinor) {
            $note = 'Renewal recorded (' . implode('; ', $applied) . '), but the invoice balance was '
                . InvoiceMapper::toMajorUnits($balance, $currency) . ' ' . $currency . '; review the invoice.' . $alsoPaid;
            $this->logger->error('sub-renewal-amount', $data, $note);
            $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        } else {
            $this->log->set(GatewayLog::RENEWAL, 'Applied ' . implode('; ', $applied) . '.' . $alsoPaid, $data);
        }
        return true;
    }

    /**
     * Pure (unit-tested): the charge spread over the renewal invoices, oldest
     * first, each up to its balance; anything left over goes to the last one
     * (WHMCS keeps the overpayment as credit).
     *
     * @param array<int, array{id:int, balanceMinor:int}> $invoices
     * @return array<int, int> invoice id => minor units
     */
    public static function split(int $amountMinor, array $invoices): array
    {
        $out = [];
        $left = $amountMinor;
        $last = count($invoices) - 1;
        foreach (array_values($invoices) as $i => $inv) {
            $part = $i === $last ? $left : min($left, max(0, (int) $inv['balanceMinor']));
            $out[(int) $inv['id']] = $part;
            $left -= $part;
        }
        return $out;
    }

    /**
     * The renewal charge failed at PaymentHooks (it retries on its own dunning
     * schedule). Nothing is charged from WHMCS: the renewal invoice stays
     * Unpaid with a note, so WHMCS's own overdue reminders, late fees and
     * suspension apply as configured.
     */
    public function handleRenewalFailed(string $subscriptionId, string $reason): void
    {
        $data = ['subscriptionId' => $subscriptionId, 'reason' => $reason];
        $items = $subscriptionId !== '' ? $this->items($subscriptionId) : [];
        if (!$items) {
            $this->log->set(GatewayLog::FAILURE, 'Renewal charge failed for subscription ' . $subscriptionId
                . ', which no WHMCS item renews through. PaymentHooks retries it on its dunning schedule.', $data);
            return;
        }
        $data['items'] = self::describe($items);
        $invoices = [];
        foreach ($items as $it) {
            foreach (Capsule::table('tblinvoiceitems')->where('relid', (int) $it->rel_id)
                ->whereIn('type', InvoicePlan::itemTypes((string) $it->rel_type))->pluck('invoiceid')->all() as $id) {
                $inv = Capsule::table('tblinvoices')->where('id', (int) $id)->where('status', 'Unpaid')->first();
                if ($inv) {
                    $invoices[(int) $inv->id] = $inv;
                }
            }
        }
        ksort($invoices);
        $line = '[' . date('Y-m-d H:i') . '] PaymentHooks auto-pay failed for subscription ' . $subscriptionId
            . ($reason !== '' ? ': ' . $reason : '') . '. PaymentHooks retries automatically; the client can also pay this invoice now.';
        foreach ($invoices as $inv) {
            $notes = trim((string) ($inv->notes ?? ''));
            $result = localAPI('UpdateInvoice', ['invoiceid' => (int) $inv->id, 'notes' => $notes === '' ? $line : $notes . "\n" . $line]);
            if (($result['result'] ?? '') !== 'success') {
                throw new \RuntimeException('Could not add the failed-renewal note to invoice ' . $inv->id . ': ' . ($result['message'] ?? 'unknown error'));
            }
        }
        $note = 'Renewal charge failed for ' . self::describe($items) . ($reason !== '' ? ' (' . $reason . ')' : '') . '. '
            . ($invoices ? 'Noted on invoice ' . implode(', ', array_keys($invoices)) . '; ' : 'No renewal invoice yet; ')
            . 'WHMCS overdue handling applies. PaymentHooks retries on its dunning schedule.';
        $this->logger->error('sub-renewal-failed', $data, $note);
        $this->log->set(GatewayLog::FAILURE, $note, $data + ['invoiceIds' => implode(',', array_keys($invoices))]);
    }

    /** Subscription ended at PaymentHooks (cancelled, deleted, mandate revoked): the items no longer auto-renew. */
    public function handleCancelled(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            $this->log->set(GatewayLog::NEEDS_ATTENTION, 'Cancellation event without a subscription id; acknowledged.');
            return;
        }
        $items = SubscriptionMap::unbind($subscriptionId);
        Capsule::table('mod_paymenthooks_sessions')
            ->where('subscription_id', $subscriptionId)
            ->update(['status' => 'cancelled', 'updated_at' => time()]);
        $what = $items ? self::describe($items) : 'its WHMCS service';
        $this->log->set(GatewayLog::CANCELLED, 'Subscription ' . $subscriptionId . ' ended at PaymentHooks; ' . $what
            . ' no longer auto-renews. WHMCS raises the next renewal invoice for the client to pay.', ['subscriptionId' => $subscriptionId]);
    }

    /** "every year from 2027-11-07: domain 12" (a planned billing group) */
    public static function describeGroup(array $group): string
    {
        $items = array_map(static function ($it) {
            return (object) ['rel_type' => $it['relType'], 'rel_id' => $it['relId']];
        }, $group['items']);
        return RecurringPlanner::cadenceLabel((string) $group['interval'], (int) $group['intervalCount'])
            . (!empty($group['startsAt']) ? ' from ' . $group['startsAt'] : '') . ': ' . self::describe($items);
    }

    /** "hosting 77, domain 12" */
    public static function describe(array $items): string
    {
        return implode(', ', array_map(static function ($it) {
            return $it->rel_type . ' ' . $it->rel_id;
        }, $items));
    }

    private function attention(string $action, array $data, string $note): bool
    {
        $this->logger->error($action, $data, $note);
        $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        return true;
    }
}
