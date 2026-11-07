<?php
/**
 * Renders the invoice payment block from templates/pay_button.tpl. Pure PHP
 * (no WHMCS calls) so the output is unit-tested in tests/run.php.
 *
 * "Pay Now" is always shown and pays the balance once. "Subscribe & Auto-pay"
 * is shown next to it only when the admin enabled subscriptions and the
 * invoice has an item that can renew automatically (RecurringPlanner offer);
 * a summary under the buttons says what will auto-renew, at what price and
 * how often, and what is charged once. Both buttons submit the same form; the
 * pressed button's name/value (mode) tells the callback which checkout to open.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class PayButton
{
    /**
     * @param array{actionUrl:string, invoiceId:int, payText:string, subscribeText?:string, logoUrl?:string,
     *              heading?:string, blurb?:string, currency?:string, plan?:array|null} $view
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
        $offer = is_array($plan) && !empty($plan['offer']);

        $subscribe = '';
        $summary = '';
        if ($offer) {
            $text = trim((string) ($view['subscribeText'] ?? '')) ?: 'Subscribe & Auto-pay';
            $subscribe = '<button type="submit" name="mode" value="subscription" class="btn btn-success"'
                . ' id="paymenthooks_pay_subscribe" aria-describedby="paymenthooks_pay_subscribe_summary"'
                . ' style="margin-left:6px">' . $e($text) . '</button>';
            $summary = self::summary($plan, (string) ($view['currency'] ?? ''), $e);
        }

        return strtr($template, [
            '{logo_html}'       => $logo === '' ? '' : '<div class="paymenthooks-logo" id="paymenthooks_pay_logo" style="margin-bottom:10px">'
                . '<img src="' . $e($logo) . '" alt="PaymentHooks" id="paymenthooks_pay_logo_img" style="max-height:40px"></div>',
            '{heading_html}'    => $heading === '' ? '' : '<div class="paymenthooks-heading" id="paymenthooks_pay_heading" style="font-weight:600;margin-bottom:4px">'
                . $e($heading) . '</div>',
            '{blurb_html}'      => $blurb === '' ? '' : '<div class="paymenthooks-blurb" id="paymenthooks_pay_blurb" style="color:#666;margin-bottom:10px">'
                . nl2br($e($blurb)) . '</div>',
            '{action_url}'      => $e((string) $view['actionUrl']),
            '{invoice_id}'      => (string) (int) $view['invoiceId'],
            '{button_text}'     => $e((string) $view['payText']),
            '{subscribe_html}'  => $subscribe,
            '{summary_html}'    => $summary,
        ]);
    }

    private static function summary(array $plan, string $currency, callable $e): string
    {
        $cadence = RecurringPlanner::cadenceLabel((string) $plan['interval'], (int) $plan['intervalCount']);
        $html = '<div class="paymenthooks-subscribe-summary" id="paymenthooks_pay_subscribe_summary" style="margin-top:10px;font-size:90%;color:#555">'
            . '<div id="paymenthooks_pay_subscribe_renews">With Subscribe &amp; Auto-pay, these renew automatically ' . $e($cadence)
            . ' for ' . $e(self::money((int) $plan['renewalMinor'], $currency)) . ' until you cancel:</div>'
            . '<ul id="paymenthooks_pay_subscribe_items" style="margin:4px 0 0 18px;padding:0">';
        foreach ($plan['subscribed'] as $i => $item) {
            $html .= '<li id="paymenthooks_pay_subscribe_item_' . $i . '">' . $e((string) $item['description']) . ' ('
                . $e(self::money((int) $item['renewalMinor'], $currency)) . ')</li>';
        }
        $html .= '</ul>';
        $once = array_values(array_filter($plan['oneTime'], static function ($r) {
            return (int) $r['amountMinor'] > 0;
        }));
        if ($once) {
            $html .= '<div id="paymenthooks_pay_subscribe_once" style="margin-top:6px">Charged once with the first payment:</div>'
                . '<ul id="paymenthooks_pay_subscribe_once_items" style="margin:4px 0 0 18px;padding:0">';
            foreach ($once as $i => $row) {
                $note = (string) ($row['note'] ?? '');
                $html .= '<li id="paymenthooks_pay_subscribe_once_item_' . $i . '">' . $e((string) $row['description'])
                    . ($note !== '' ? ' - ' . $e($note) : '') . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '<div id="paymenthooks_pay_subscribe_note" style="margin-top:6px">Pay Now pays this invoice once; '
            . 'later invoices are then paid by you.</div></div>';
    }

    /** "499.00 INR" from minor units. */
    public static function money(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);
        $major = InvoiceMapper::toMajorUnits($minor, $currency);
        $digits = in_array($currency, InvoiceMapper::ZERO_DECIMAL, true) ? 0 : 2;
        return number_format($major, $digits, '.', ',') . ($currency !== '' ? ' ' . $currency : '');
    }
}
