<?php
/**
 * Reads the fields this module needs from PaymentHooks webhook events.
 *
 * Every event is an envelope { id, type, livemode, data: { object } }. The
 * object shapes (field names verbatim from the PaymentHooks payload builders):
 *
 *   checkout.session.completed      object = checkout session
 *     id, status ("complete"), payment_status ("paid"), payment_intent (payment id),
 *     amount_total (smallest unit of currency), currency (lowercase),
 *     amount_usd_cents (the same total in USD cents), usd_to_local_currency_rate,
 *     subscription (id|null), mode, metadata (string values)
 *   checkout.session.payment_failed object = checkout session (payment_status "unpaid")
 *   payment.refunded                object = payment intent
 *     id (payment id), amount, currency, amount_usd_cents,
 *     charges.data[0].amount_refunded, metadata
 *
 * Webhooks keep Stripe's shapes: amount / amount_total / amount_refunded are
 * the local amount in `currency`, which is what WHMCS records. The USD cents
 * are informational (Gateway Log) only.
 *   subscription.renewed            object = subscription
 *     id, latest_payment { id, amount, currency } (absent when credit covered it)
 *   subscription.renewal_failed     object = subscription (failure_reason when known)
 *   subscription.cancelled          object = subscription (status "cancelled")
 *   subscription.deleted            object = subscription
 *   invoice.paid                    object = invoice
 *     id, subscription (id|null), payment_intent (payment id), amount_paid,
 *     currency (lowercase), billing_reason
 *
 * Pure PHP (no WHMCS calls) so it is unit-tested in tests/run.php.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class EventParser
{
    /** @return array the event's data.object, or [] */
    public static function object(array $event): array
    {
        $obj = $event['data']['object'] ?? null;
        return is_array($obj) ? $obj : [];
    }

    /**
     * Completed checkout session.
     *
     * @return array{sessionId:string,paymentId:string,amountMinor:int,amountUsdCents:int,currency:string,subscriptionId:string,invoiceId:int,paid:bool}
     */
    public static function checkoutCompleted(array $event): array
    {
        $o = self::object($event);
        return [
            'sessionId'      => self::str($o['id'] ?? null),
            'paymentId'      => self::str($o['payment_intent'] ?? null),
            'amountMinor'    => self::int($o['amount_total'] ?? null),
            'amountUsdCents' => self::int($o['amount_usd_cents'] ?? null),
            'currency'       => strtoupper(self::str($o['currency'] ?? null)),
            'subscriptionId' => self::str($o['subscription'] ?? null),
            'invoiceId'      => self::invoiceId($o),
            'paid'           => ($o['payment_status'] ?? '') === 'paid',
        ];
    }

    /**
     * Refunded payment.
     *
     * @return array{paymentId:string,amountRefundedMinor:int,amountUsdCents:int,currency:string,invoiceId:int}
     */
    public static function paymentRefunded(array $event): array
    {
        $o = self::object($event);
        $charge = $o['charges']['data'][0] ?? [];
        return [
            'paymentId'           => self::str($o['id'] ?? null),
            'amountRefundedMinor' => self::int(is_array($charge) ? ($charge['amount_refunded'] ?? null) : null),
            'amountUsdCents'      => self::int($o['amount_usd_cents'] ?? null),
            'currency'            => strtoupper(self::str($o['currency'] ?? null)),
            'invoiceId'           => self::invoiceId($o),
        ];
    }

    /**
     * Subscription event. `paymentId`/`amountMinor` are set only on a charged
     * renewal; a renewal fully covered by credit has no payment.
     *
     * @return array{subscriptionId:string,status:string,paymentId:string,amountMinor:int,currency:string,failureReason:string}
     */
    public static function subscription(array $event): array
    {
        $o = self::object($event);
        $pay = is_array($o['latest_payment'] ?? null) ? $o['latest_payment'] : [];
        $error = is_array($o['last_payment_error'] ?? null) ? $o['last_payment_error'] : [];
        $reason = self::str($o['failure_reason'] ?? ($error['message'] ?? null));
        return [
            'subscriptionId' => self::str($o['id'] ?? null),
            'status'         => self::str($o['status'] ?? null),
            'paymentId'      => self::str($pay['id'] ?? null),
            'amountMinor'    => self::int($pay['amount'] ?? null),
            'currency'       => strtoupper(self::str($pay['currency'] ?? ($o['currency'] ?? null))),
            'failureReason'  => function_exists('mb_substr') ? mb_substr($reason, 0, 300) : substr($reason, 0, 300),
        ];
    }

    /**
     * Paid PaymentHooks invoice (invoice.paid). A renewal arrives as
     * subscription.renewed and as invoice.paid for its invoice; both carry the
     * same payment id, so whichever comes second is a Duplicate.
     *
     * @return array{invoiceId:string,subscriptionId:string,paymentId:string,amountMinor:int,currency:string,billingReason:string}
     */
    public static function invoicePaid(array $event): array
    {
        $o = self::object($event);
        return [
            'invoiceId'      => self::str($o['id'] ?? null),
            'subscriptionId' => self::str($o['subscription'] ?? null),
            'paymentId'      => self::str($o['payment_intent'] ?? null),
            'amountMinor'    => self::int($o['amount_paid'] ?? null),
            'currency'       => strtoupper(self::str($o['currency'] ?? null)),
            'billingReason'  => self::str($o['billing_reason'] ?? null),
        ];
    }

    /** WHMCS invoice id this module put in metadata at checkout, or 0. */
    public static function invoiceId(array $object): int
    {
        $meta = $object['metadata'] ?? [];
        if (!is_array($meta)) {
            return 0;
        }
        $id = $meta['whmcs_invoice_id'] ?? '';
        return (is_scalar($id) && ctype_digit((string) $id)) ? (int) $id : 0;
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** Whole minor units; JSON may encode them as 5000 or 5000.0. */
    private static function int($v): int
    {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (int) $v;
        }
        if (is_float($v) && $v >= 0 && floor($v) === $v) {
            return (int) $v;
        }
        return 0;
    }
}
