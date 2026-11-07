<?php
/**
 * Records in WHMCS a refund made at PaymentHooks (dashboard or API), from
 * payment.refunded. The event carries the payment's cumulative refunded
 * amount (charges.data[0].amount_refunded); the module books only the part
 * WHMCS does not know yet:
 *
 *   known = max(refunds the module recorded or issued for this payment
 *               (mod_paymenthooks_refunds), refunds WHMCS holds against the
 *               payment's transaction (tblaccounts.amountout by refundid))
 *   new   = cumulative - known
 *
 * Refunds issued from WHMCS are written to mod_paymenthooks_refunds before
 * PaymentHooks is called, so their own payment.refunded is never booked a
 * second time even if it arrives before WHMCS saves the refund. The refund is
 * booked with WHMCS's refundInvoicePayment() without sending it to the gateway
 * (PaymentHooks already refunded it): WHMCS links it to the payment and marks
 * the invoice Refunded when nothing is left.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class RefundSync
{
    const GATEWAY = 'paymenthooks';

    /** Pure (unit-tested): minor units still to book, given what PaymentHooks and WHMCS each know. */
    public static function newRefundMinor(int $cumulativeMinor, int $moduleKnownMinor, int $whmcsKnownMinor): int
    {
        return max(0, $cumulativeMinor - max($moduleKnownMinor, $whmcsKnownMinor));
    }

    /** Called by paymenthooks_refund() before the PaymentHooks call; returns the row id to drop if the call fails. */
    public static function reserveWhmcsRefund(string $paymentId, int $amountMinor): int
    {
        Capsule::table('mod_paymenthooks_refunds')->insert([
            'payment_id' => $paymentId, 'source' => 'whmcs', 'refund_id' => null,
            'amount_minor' => $amountMinor, 'created_at' => time(),
        ]);
        return (int) Capsule::table('mod_paymenthooks_refunds')->where('payment_id', $paymentId)->where('source', 'whmcs')
            ->orderBy('id', 'desc')->value('id');
    }

    public static function confirmWhmcsRefund(int $rowId, string $refundId): void
    {
        Capsule::table('mod_paymenthooks_refunds')->where('id', $rowId)->update(['refund_id' => $refundId]);
    }

    public static function releaseWhmcsRefund(int $rowId): void
    {
        Capsule::table('mod_paymenthooks_refunds')->where('id', $rowId)->delete();
    }

    /** @var Logger */
    private $logger;
    /** @var GatewayLog */
    private $log;

    public function __construct(Logger $logger, GatewayLog $log)
    {
        $this->logger = $logger;
        $this->log = $log;
    }

    /** @param array{paymentId:string, amountRefundedMinor:int, currency:string} $r EventParser::paymentRefunded() */
    public function apply(array $r): bool
    {
        $account = Capsule::table('tblaccounts')
            ->where('transid', $r['paymentId'])
            ->where('gateway', self::GATEWAY)
            ->where('amountin', '>', 0)
            ->first();
        if (!$account) {
            $this->log->set(GatewayLog::IGNORED, 'Refunded payment is not recorded in this WHMCS; acknowledged.', $r);
            return true;
        }
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $account->invoiceid)->first();
        $currency = $invoice ? WebhookRouter::invoiceCurrency((int) $invoice->userid) : '';
        if ($currency === '' || $currency !== $r['currency']) {
            return $this->attention($r, 'Refund on payment ' . $r['paymentId'] . ' is in ' . $r['currency'] . ' but the WHMCS invoice is in '
                . ($currency ?: 'an unknown currency') . '; record the refund manually.');
        }

        $moduleKnown = (int) Capsule::table('mod_paymenthooks_refunds')->where('payment_id', $r['paymentId'])->sum('amount_minor');
        $whmcsKnown = InvoiceMapper::toMinorUnits((float) Capsule::table('tblaccounts')->where('refundid', (int) $account->id)->sum('amountout'), $currency);
        $newMinor = self::newRefundMinor($r['amountRefundedMinor'], $moduleKnown, $whmcsKnown);
        $data = $r + ['invoiceId' => (int) $account->invoiceid, 'alreadyKnownMinor' => max($moduleKnown, $whmcsKnown)];
        if ($newMinor === 0) {
            $this->log->set(GatewayLog::REFUND, 'Refund on payment ' . $r['paymentId'] . ' is already recorded in WHMCS.', $data);
            return true;
        }

        // WHMCS can only refund what it credited (the convenience fee was not credited to the invoice).
        $refundableMinor = InvoiceMapper::toMinorUnits((float) $account->amountin, $currency) - $whmcsKnown;
        $bookMinor = min($newMinor, max(0, $refundableMinor));
        if ($bookMinor <= 0) {
            return $this->attention($data, 'PaymentHooks refunded ' . InvoiceMapper::toMajorUnits($newMinor, $currency) . ' ' . $currency
                . ' more on payment ' . $r['paymentId'] . ' than WHMCS recorded for it; nothing left to refund in WHMCS, review the invoice.');
        }

        $amount = InvoiceMapper::toMajorUnits($bookMinor, $currency);
        // Not sent to the gateway (PaymentHooks already refunded): WHMCS answers
        // "manual" when it recorded it, "success" on versions that report that.
        $result = refundInvoicePayment((int) $account->id, $amount, false, false, true, $r['paymentId'] . '-refund-' . $r['amountRefundedMinor']);
        if ($result !== 'manual' && $result !== 'success') {
            throw new \RuntimeException('WHMCS refused to record the refund of ' . $amount . ' ' . $currency . ' on payment '
                . $r['paymentId'] . ': ' . (is_scalar($result) ? (string) $result : json_encode($result)));
        }
        Capsule::table('mod_paymenthooks_refunds')->insert([
            'payment_id' => $r['paymentId'], 'source' => 'paymenthooks', 'refund_id' => null,
            'amount_minor' => $bookMinor, 'created_at' => time(),
        ]);
        if ($bookMinor < $newMinor) {
            return $this->attention($data, 'Recorded a refund of ' . $amount . ' ' . $currency . ' on invoice ' . $account->invoiceid
                . '; PaymentHooks refunded ' . InvoiceMapper::toMajorUnits($newMinor - $bookMinor, $currency) . ' more (convenience fee), review the invoice.');
        }
        $this->log->set(GatewayLog::REFUND, 'Recorded the PaymentHooks refund of ' . $amount . ' ' . $currency . ' on invoice ' . $account->invoiceid . '.', $data);
        return true;
    }

    private function attention(array $data, string $note): bool
    {
        $this->logger->error('webhook-refund', $data, $note);
        $this->log->set(GatewayLog::NEEDS_ATTENTION, $note, $data);
        return true;
    }
}
