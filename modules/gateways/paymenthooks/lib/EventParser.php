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
 *     subscription (id|null), mode, metadata (string values),
 *     subscriptions (one per billing group of a subscription checkout; see
 *     sessionSubscriptions)
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
            'subscriptions'  => self::sessionSubscriptions($o),
            'invoiceId'      => self::invoiceId($o),
            'paid'           => ($o['payment_status'] ?? '') === 'paid',
        ];
    }

    /**
     * Every subscription a checkout opened (one per billing group), from the
     * session's `subscriptions` list. Each entry is an id, or an object with
     * id, interval, interval_count, starts_at (null = started now), the next
     * charge date (next_charge_at, else current_period_end; unix seconds or
     * ISO) and items [{description, unit_amount}]. camelCase is read as well
     * (the REST session uses it). Missing details stay empty; the matcher
     * then binds only what it can prove.
     *
     * @return array<int, array{id:string, interval:string, intervalCount:int, startsAtKnown:bool, startsAt:?string,
     *                          nextCharge:string, items:array}>
     */
    public static function sessionSubscriptions(array $o): array
    {
        $list = $o['subscriptions'] ?? null;
        if (is_array($list) && isset($list['data']) && is_array($list['data'])) {
            $list = $list['data'];
        }
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $sub) {
            if (is_string($sub)) {
                $sub = ['id' => $sub];
            }
            if (!is_array($sub)) {
                continue;
            }
            $plan = is_array($sub['plan'] ?? null) ? $sub['plan'] : [];
            $hasStart = array_key_exists('starts_at', $sub) || array_key_exists('startsAt', $sub);
            $start = $sub['starts_at'] ?? ($sub['startsAt'] ?? null);
            $items = [];
            $rawItems = $sub['items'] ?? [];
            if (is_array($rawItems) && isset($rawItems['data']) && is_array($rawItems['data'])) {
                $rawItems = $rawItems['data'];
            }
            foreach (is_array($rawItems) ? $rawItems : [] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $price = is_array($it['price'] ?? null) ? $it['price'] : [];
                $items[] = [
                    'description' => self::str($it['description'] ?? ($price['nickname'] ?? null)),
                    'unitPrice'   => self::int($it['unit_amount'] ?? ($it['unitPrice'] ?? ($price['unit_amount'] ?? null))),
                ];
            }
            $out[] = [
                'id'            => self::str($sub['id'] ?? null),
                'interval'      => self::str($sub['interval'] ?? ($plan['interval'] ?? null)),
                'intervalCount' => max(1, self::int($sub['interval_count'] ?? ($sub['intervalCount'] ?? ($plan['interval_count'] ?? 1)))),
                'startsAtKnown' => $hasStart,
                'startsAt'      => $start === null ? null : self::date($start),
                'nextCharge'    => self::date($sub['next_charge_at'] ?? ($sub['nextChargeAt'] ?? ($sub['next_charge_date'] ?? ($sub['nextChargeDate']
                    ?? ($sub['current_period_end'] ?? ($sub['currentPeriodEnd'] ?? null)))))),
                'items'         => $items,
            ];
        }
        return $out;
    }

    /** Unix seconds or an ISO date/time as Y-m-d (UTC), '' if neither. */
    private static function date($v): string
    {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (int) $v > 0 ? gmdate('Y-m-d', (int) $v) : '';
        }
        // The calendar date as sent (startsAt goes out as a plain Y-m-d).
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : '';
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
