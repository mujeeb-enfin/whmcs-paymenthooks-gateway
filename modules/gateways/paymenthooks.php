<?php
/**
 * PaymentHooks payment gateway module for WHMCS 8.x
 * --------------------------------------------------
 * Connects a WHMCS install to PaymentHooks (PH). PH performs the payment,
 * hosted checkout, gateway routing, subscriptions and refunds. This module
 * creates PH checkout sessions, redirects the client to PH's hosted page, and
 * reconciles WHMCS invoices from PH webhooks (see callback/paymenthooks.php).
 *
 * Gateway API 1.1. Compatible with PHP 7.4 - 8.x.
 *
 * @see https://paymenthooks.com/docs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/paymenthooks/lib/bootstrap.php';

use WHMCS\Database\Capsule;
use PaymentHooks\ApiException;
use PaymentHooks\Config;
use PaymentHooks\Client;
use PaymentHooks\Logger;
use PaymentHooks\Schema;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\RefundMapper;

/**
 * Module metadata and capability flags.
 */
function paymenthooks_MetaData()
{
    return [
        'DisplayName'                 => 'PaymentHooks',
        'APIVersion'                  => '1.1',
        // Cards are entered on the PH hosted page, never inside WHMCS.
        'DisableLocalCreditCardInput' => true,
        // PH has no off-session charge API, so WHMCS stores no tokens.
        'TokenisedStorage'            => false,
    ];
}

/**
 * Admin configuration screen. Field names become the keys in $params passed to
 * every other function.
 */
function paymenthooks_config()
{
    $webhookUrl = rtrim(\App::getSystemURL(), '/') . '/modules/gateways/callback/paymenthooks.php';

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'PaymentHooks',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key',
            'Type'         => 'password',
            'Size'         => '70',
            'Description'  => 'phk_live_... from Developers > API Keys in PaymentHooks. Scopes: payments:read, '
                . 'payments:write, customers:read, customers:write; for subscriptions also subscriptions:write, '
                . 'products:read and products:write. Real or simulated payments follow the Platform Mode in your '
                . 'PaymentHooks dashboard, not this key.',
        ],
        'publishableKey' => [
            'FriendlyName' => 'Publishable Key',
            'Type'         => 'password',
            'Size'         => '70',
            'Description'  => 'Optional. phk_pub_... Not used by the redirect checkout.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type'         => 'password',
            'Size'         => '70',
            'Description'  => 'whsec_... shown when you add the Webhook URL below as an endpoint under '
                . 'Developers > Webhooks in PaymentHooks.',
        ],
        'webhookUrlDisplay' => [
            'FriendlyName' => 'Webhook URL',
            'Type'         => 'text',
            'Size'         => '70',
            'Default'      => $webhookUrl,
            'Description'  => 'Read-only. Add this URL as a webhook endpoint in PaymentHooks, subscribed to '
                . 'checkout.session.completed, checkout.session.payment_failed, payment.refunded, '
                . 'subscription.renewed, subscription.renewal_failed and subscription.cancelled.',
        ],
        'customDomain' => [
            'FriendlyName' => 'Custom Checkout Domain',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => 'Optional, e.g. pay.example.com, if you verified a custom domain in PaymentHooks. '
                . 'Clients are sent to the checkout page on this domain; API calls always go to paymenthooks.com.',
        ],
        'convenienceFeePercent' => [
            'FriendlyName' => 'Convenience Fee %',
            'Type'         => 'text',
            'Size'         => '6',
            'Default'      => '',
            'Description'  => 'Optional. Percentage added on top of each payment (e.g. 2 for 2%). '
                . 'The invoice is credited with its balance; the surcharge is noted in the Gateway Log.',
        ],
        'descriptionTemplate' => [
            'FriendlyName' => 'Description Template',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Invoice {invoiceid}',
            'Description'  => 'Stored on the checkout session as metadata.description. {invoiceid} is replaced.',
        ],
        'checkoutHeading' => [
            'FriendlyName' => 'Checkout Heading',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Pay securely with PaymentHooks',
            'Description'  => 'Shown to the client above the Pay button on the invoice.',
        ],
        'checkoutBlurb' => [
            'FriendlyName' => 'Checkout Description',
            'Type'         => 'textarea',
            'Rows'         => '2',
            'Cols'         => '60',
            'Default'      => 'You will be redirected to our secure payment page to complete your payment.',
            'Description'  => 'Shown to the client under the heading. Leave blank to hide.',
        ],
        'logoUrl' => [
            'FriendlyName' => 'Logo URL',
            'Type'         => 'text',
            'Size'         => '70',
            'Default'      => '',
            'Description'  => 'Optional. Full URL of a logo to show above the Pay button. '
                . 'If blank, modules/gateways/paymenthooks/logo.png is used when present.',
        ],
        'enableSubscriptions' => [
            'FriendlyName' => 'Enable Subscriptions',
            'Type'         => 'yesno',
            'Description'  => 'Tick to let recurring products start a PaymentHooks subscription at checkout.',
        ],
        'paymentModePolicy' => [
            'FriendlyName' => 'Payment Mode',
            'Type'         => 'dropdown',
            'Options'      => [
                'auto'         => 'Auto — subscription for recurring products, one-time otherwise',
                'subscription' => 'Subscription only — every invoice must contain a recurring product',
                'payment'      => 'One-time only — never start a subscription',
            ],
            'Default'      => 'auto',
            'Description'  => '"Subscription only" requires Enable Subscriptions; an invoice without a recurring '
                . 'product then fails at checkout instead of being charged once.',
        ],
        'logging' => [
            'FriendlyName' => 'Debug Logging',
            'Type'         => 'yesno',
            'Description'  => 'Tick to log every API call to Utilities > Logs > Module Log. Errors are always logged.',
        ],
    ];
}

/**
 * Renders the "Pay Now" button on the invoice. The button posts to the
 * callback, which creates the PH checkout session on click, so viewing an
 * invoice never creates a session.
 */
function paymenthooks_link($params)
{
    Schema::ensure();

    $problems = Config::fromParams($params)->validate();
    if ($problems) {
        (new Logger('paymenthooks', true))->error('config', [], implode(' ', $problems));
        return '<div class="alert alert-danger" id="paymenthooks_pay_unavailable">'
            . 'Online payment is unavailable. Please contact us.</div>';
    }

    $systemUrl  = rtrim($params['systemurl'], '/');
    $actionUrl  = $systemUrl . '/modules/gateways/callback/paymenthooks.php';
    $invoiceId  = (int) $params['invoiceid'];
    $buttonText = htmlspecialchars($params['langpaynow'] ?? 'Pay Now', ENT_QUOTES);

    $logoUrl = trim($params['logoUrl'] ?? '');
    if ($logoUrl === '' && is_file(__DIR__ . '/paymenthooks/logo.png')) {
        $logoUrl = $systemUrl . '/modules/gateways/paymenthooks/logo.png';
    }
    $heading = trim($params['checkoutHeading'] ?? '');
    $blurb   = trim($params['checkoutBlurb'] ?? '');

    $logoHtml = $logoUrl !== ''
        ? '<div class="paymenthooks-logo" id="paymenthooks_pay_logo" style="margin-bottom:10px"><img src="'
            . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="PaymentHooks" style="max-height:40px"></div>'
        : '';
    $headingHtml = $heading !== ''
        ? '<div class="paymenthooks-heading" id="paymenthooks_pay_heading" style="font-weight:600;margin-bottom:4px">'
            . htmlspecialchars($heading, ENT_QUOTES) . '</div>'
        : '';
    $blurbHtml = $blurb !== ''
        ? '<div class="paymenthooks-blurb" id="paymenthooks_pay_blurb" style="color:#666;margin-bottom:10px">'
            . nl2br(htmlspecialchars($blurb, ENT_QUOTES)) . '</div>'
        : '';

    $tpl = file_get_contents(__DIR__ . '/paymenthooks/templates/pay_button.tpl');
    return strtr($tpl, [
        '{logo_html}'    => $logoHtml,
        '{heading_html}' => $headingHtml,
        '{blurb_html}'   => $blurbHtml,
        '{action_url}'   => htmlspecialchars($actionUrl, ENT_QUOTES),
        '{invoice_id}'   => $invoiceId,
        '{button_text}'  => $buttonText,
    ]);
}

/**
 * Refund handler, called when an admin refunds a transaction. WHMCS always
 * passes the amount (full or partial) in the invoice currency; PaymentHooks
 * requires it in USD cents (see RefundMapper).
 */
function paymenthooks_refund($params)
{
    Schema::ensure();

    $config = Config::fromParams($params);
    $logger = new Logger('paymenthooks', $config->logging);

    // The transaction id WHMCS stored at payment is the PH payment id.
    $paymentId = trim((string) ($params['transid'] ?? ''));
    $currency  = strtoupper(trim((string) ($params['currency'] ?? '')));
    $amount    = (float) ($params['amount'] ?? 0);
    if ($paymentId === '' || $currency === '' || $amount <= 0) {
        return [
            'status'  => 'declined',
            'rawdata' => 'Refund needs the PaymentHooks payment id, a currency and a positive amount.',
        ];
    }

    $amountMinor = InvoiceMapper::toMinorUnits($amount, $currency);
    // Payment + amount + refunds already recorded: a double click is one
    // refund, a second intended refund of the same amount is a new one.
    $refundsSoFar = (int) Capsule::table('tblaccounts')
        ->where('invoiceid', (int) ($params['invoiceid'] ?? 0))
        ->where('gateway', 'paymenthooks')
        ->where('amountout', '>', 0)
        ->count();

    $client = new Client($config, $logger);
    try {
        // PaymentHooks takes the refund amount in USD cents; map the WHMCS
        // amount (invoice currency) through the payment's own transaction fields.
        $payment = $client->get('/payments/' . rawurlencode($paymentId))['data'] ?? [];
        $amountUsdCents = RefundMapper::usdCents(is_array($payment) ? $payment : [], $amountMinor, $currency);
    } catch (ApiException $e) {
        return ['status' => 'declined', 'rawdata' => $e->errorCode . ': ' . $e->getMessage()];
    } catch (\RuntimeException $e) {
        $logger->error('refund-map', compact('paymentId', 'amountMinor', 'currency'), $e->getMessage());
        return ['status' => 'declined', 'rawdata' => $e->getMessage()];
    }
    $body = RefundMapper::body($amountUsdCents, 'Refund issued from WHMCS for invoice ' . ($params['invoiceid'] ?? ''));

    try {
        $resp = $client->post(
            '/payments/' . rawurlencode($paymentId) . '/refund',
            $body,
            'refund-' . $paymentId . '-' . $amountMinor . '-' . $refundsSoFar
        );
    } catch (ApiException $e) {
        return ['status' => 'declined', 'rawdata' => $e->errorCode . ': ' . $e->getMessage()];
    }

    $refundId = (string) ($resp['data']['id'] ?? '');
    if ($refundId === '') {
        return ['status' => 'error', 'rawdata' => $resp];
    }
    return ['status' => 'success', 'transid' => $refundId, 'rawdata' => $resp];
}

/**
 * Cancels the PH subscription when the WHMCS service is cancelled or
 * terminated. WHMCS passes the id stored on tblhosting.subscriptionid.
 */
function paymenthooks_cancelSubscription($params)
{
    Schema::ensure();

    $config = Config::fromParams($params);
    $logger = new Logger('paymenthooks', $config->logging);

    $subscriptionId = trim((string) ($params['subscriptionID'] ?? ''));
    if ($subscriptionId === '') {
        return ['status' => 'error', 'rawdata' => 'No PaymentHooks subscription id stored for this service.'];
    }

    try {
        // The service is ending, so billing stops now rather than at period end.
        $resp = (new Client($config, $logger))->post(
            '/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
            ['cancelAtPeriodEnd' => false]
        );
    } catch (ApiException $e) {
        // Already cancelled at PaymentHooks (e.g. from its dashboard).
        if ($e->errorCode === 'INVALID_STATE' && $e->httpStatus === 422) {
            return ['status' => 'success', 'rawdata' => $e->response];
        }
        return ['status' => 'error', 'rawdata' => $e->errorCode . ': ' . $e->getMessage()];
    }

    return ['status' => 'success', 'rawdata' => $resp];
}
