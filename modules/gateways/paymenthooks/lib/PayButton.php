<?php

/**
 * Renders the invoice payment block from templates/pay_button.tpl. Pure PHP
 * (no WHMCS calls) so the output is unit-tested in tests/run.php.
 *
 * "Pay Now" is always shown and pays the balance once. With Enable
 * Subscriptions on:
 *  - when the invoice can be subscribed (RecurringPlanner offer),
 *    "Subscribe & Auto-pay" is shown next to it, with a summary of what is
 *    paid now and, per subscription, what renews, at what price, how often
 *    and from when;
 *  - otherwise a short note under Pay Now says in plain language why auto-pay
 *    is not offered (paymenthooks_pay_subscribe_unavailable), or that it is
 *    unavailable right now when the plan could not be read.
 * With Enable Subscriptions off there is no Subscribe button and no note.
 * Both buttons submit the same form; the pressed button's name/value (mode)
 * tells the callback which checkout to open.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class PayButton
{
    /** Shown when the plan threw; the error itself is in the Activity Log (subscribe-plan). */
    const PLAN_ERROR_NOTE = 'Auto-pay is unavailable for this invoice right now. You can pay it with Pay Now.';

    /** Symbols placed before the amount; other currencies show their code after it. */
    const SYMBOLS = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$', 'SGD' => 'S$'];

    /**
     * @param array{actionUrl:string, invoiceId:int, payText:string, subscribeText?:string, logoUrl?:string,
     *              heading?:string, blurb?:string, currency?:string, plan?:array|null, planError?:bool} $view
     */
    public static function render(string $template, array $view): string
    {
        $e = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        $logo = trim((string) ($view['logoUrl'] ?? ''));
        $heading = trim((string) ($view['heading'] ?? ''));
        $blurb = trim((string) ($view['blurb'] ?? ''));
        $plan = $view['plan'] ?? null;
        $currency = (string) ($view['currency'] ?? '');

        $subscribe = '';
        $summary = '';
        if (is_array($plan) && !empty($plan['offer'])) {
            $text = trim((string) ($view['subscribeText'] ?? '')) ?: 'Subscribe & Auto-pay';
            $subscribe = '<button type="submit" name="mode" value="subscription" class="btn btn-success"'
                . ' id="paymenthooks_pay_subscribe" aria-describedby="paymenthooks_pay_subscribe_summary"'
                . ' style="margin:5px !important">' . $e($text) . '</button>';
            $summary = self::summary($plan, $currency, $e);
        } elseif (!empty($view['planError'])) {
            $summary = self::unavailable(self::PLAN_ERROR_NOTE, $e);
        } elseif (is_array($plan) && (string) ($plan['reason'] ?? '') !== '') {
            $summary = self::unavailable((string) $plan['reason'], $e);
        }

        return strtr($template, [
            '{logo_html}'       => $logo === '' ? '' : '<div class="paymenthooks-logo" id="paymenthooks_pay_logo" style="margin-bottom:10px">'
                . '<img src="' . $e($logo) . '" alt="PaymentHooks" id="paymenthooks_pay_logo_img" style="max-height:40px"></div>',
            '{heading_html}'    => $heading === '' ? '' : '<div class="paymenthooks-heading" id="paymenthooks_pay_heading" style="font-weight:600;margin-bottom:4px">'
                . $e($heading) . '</div>',
            '{blurb_html}'      => $blurb === '' ? '' : '<div class="paymenthooks-blurb" id="paymenthooks_pay_blurb">'
                . nl2br($e($blurb)) . '</div>',
            '{action_url}'      => $e((string) $view['actionUrl']),
            '{invoice_id}'      => (string) (int) $view['invoiceId'],
            '{button_text}'     => $e((string) $view['payText']),
            '{subscribe_html}'  => $subscribe,
            '{summary_html}'    => $summary,
        ]);
    }

    private static function unavailable(string $reason, callable $e): string
    {
        return '<div class="paymenthooks-subscribe-unavailable" id="paymenthooks_pay_subscribe_unavailable" role="note"'
            . ' style="margin-top:8px;font-size:90%;color:#666">' . $e($reason) . '</div>';
    }

    /**
     * "1,350.25 now", then per subscription "then 999.00 every year from
     * 7 Nov 2027" with its items, then what is charged once.
     */
    private static function summary(array $plan, string $currency, callable $e): string
    {
        $html = '<div class="paymenthooks-subscribe-summary" id="paymenthooks_pay_subscribe_summary">'
            . '<div id="paymenthooks_pay_subscribe_now">With Subscribe &amp; Auto-pay you pay <strong id="paymenthooks_pay_subscribe_now_amount">'
            . $e(self::money((int) $plan['nowMinor'], $currency)) . '</strong> now, then until you cancel:</div>';
        foreach ($plan['groups'] as $g => $group) {
            $cadence = RecurringPlanner::cadenceLabel((string) $group['interval'], (int) $group['intervalCount']);
            $html .= '<div id="paymenthooks_pay_subscribe_group_' . $g . '" style="margin-top:6px">'
                . $e(self::money((int) $group['renewalMinor'], $currency) . ' ' . $cadence . ' from '
                    . RecurringPlanner::formatDate((string) $group['firstRenewal'])) . ':</div>'
                . '<ul id="paymenthooks_pay_subscribe_group_' . $g . '_items" style="margin:4px 0 0 18px;padding:0">';
            foreach ($group['items'] as $i => $item) {
                $html .= '<li id="paymenthooks_pay_subscribe_group_' . $g . '_item_' . $i . '">' . $e((string) $item['description']) . ' ('
                    . $e(self::money((int) $item['renewalMinor'], $currency)) . ')</li>';
            }
            $html .= '</ul>';
        }
        $once = array_values(array_filter($plan['oneTime'], static function ($r) {
            return (int) $r['amountMinor'] > 0;
        }));
        if ($once) {
            $html .= '<div id="paymenthooks_pay_subscribe_once" style="margin-top:6px">Included in the payment now, charged once:</div>'
                . '<ul id="paymenthooks_pay_subscribe_once_items" style="margin:4px 0 0 18px;padding:0">';
            foreach ($once as $i => $row) {
                $note = (string) ($row['note'] ?? '');
                $html .= '<li id="paymenthooks_pay_subscribe_once_item_' . $i . '">' . $e((string) $row['description'])
                    . ' (' . $e(self::money((int) $row['amountMinor'], $currency)) . ')' . ($note !== '' ? ' - ' . $e($note) : '') . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '<div id="paymenthooks_pay_subscribe_note" style="margin-top:6px">Pay Now pays this invoice once; '
            . 'later invoices are then paid by you.</div></div>';
    }

    /** "₹1,350.25", "$12.00", "1,500 JPY"-style for currencies without a symbol here. */
    public static function money(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);
        $major = InvoiceMapper::toMajorUnits($minor, $currency);
        $digits = in_array($currency, InvoiceMapper::ZERO_DECIMAL, true) ? 0 : 2;
        $number = number_format($major, $digits, '.', ',');
        if (isset(self::SYMBOLS[$currency])) {
            return self::SYMBOLS[$currency] . $number;
        }
        return $number . ($currency !== '' ? ' ' . $currency : '');
    }
}
