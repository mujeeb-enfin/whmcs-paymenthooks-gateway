<?php
/**
 * PaymentHooks payment gateway module for WHMCS 8.x
 * --------------------------------------------------
 * Connects a WHMCS install to a PaymentHooks (PH) deployment. PH performs the
 * actual payment, hosted checkout, gateway routing, subscriptions and refunds.
 * This module creates PH checkout sessions, redirects the client to PH's hosted
 * page, and reconciles WHMCS invoices from PH webhooks (see callback/paymenthooks.php).
 *
 * Gateway API 1.1. Compatible with PHP 7.4 - 8.x.
 *
 * @see https://paymenthooks.com/docs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/paymenthooks/lib/Config.php';
require_once __DIR__ . '/paymenthooks/lib/Logger.php';
require_once __DIR__ . '/paymenthooks/lib/Client.php';
require_once __DIR__ . '/paymenthooks/lib/Signature.php';
require_once __DIR__ . '/paymenthooks/lib/Schema.php';
require_once __DIR__ . '/paymenthooks/lib/InvoiceMapper.php';

use WHMCS\Database\Capsule;
use PaymentHooks\Config;
use PaymentHooks\Client;
use PaymentHooks\Logger;
use PaymentHooks\Schema;
use PaymentHooks\InvoiceMapper;

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
        // PH exposes no off-session charge API, so we do not store WHMCS tokens.
        'TokenisedStorage'            => false,
    ];
}

/**
 * Admin configuration screen. Field names become the keys in $params passed to
 * every other function.
 */
function paymenthooks_config()
{
    // The webhook URL the merchant must paste into their PH dashboard.
    $systemUrl = rtrim(\App::getSystemURL(), '/');
    $webhookUrl = $systemUrl . '/modules/gateways/callback/paymenthooks.php';

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'PaymentHooks',
        ],
        'mode' => [
            'FriendlyName' => 'Mode',
            'Type'         => 'dropdown',
            'Options'      => [
                'test' => 'Test',
                'live' => 'Live',
            ],
            'Default'      => 'test',
            'Description'  => 'Test uses sk_test_/pk_test_ keys; Live uses sk_live_/pk_live_ keys.',
        ],
        'customDomain' => [
            'FriendlyName' => 'PaymentHooks Custom Domain',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => 'Optional. e.g. payments.mr-innovations.com. '
                . 'Leave blank to use the default PaymentHooks URL (paymenthooks.com). '
                . 'HTTPS is always used; any scheme or trailing slash you enter is ignored.',
        ],
        'testSecretKey' => [
            'FriendlyName' => 'Test Secret Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'sk_test_...',
        ],
        'liveSecretKey' => [
            'FriendlyName' => 'Live Secret Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'sk_live_...',
        ],
        'testPublishableKey' => [
            'FriendlyName' => 'Test Publishable Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'pk_test_... (used for any client-side embed)',
        ],
        'livePublishableKey' => [
            'FriendlyName' => 'Live Publishable Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'pk_live_...',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'The signing secret from your PH webhook endpoint.',
        ],
        'webhookUrlDisplay' => [
            'FriendlyName' => 'Webhook URL',
            'Type'         => 'text',
            'Size'         => '70',
            'Default'      => $webhookUrl,
            'Description'  => 'Read-only. Add this URL as a webhook endpoint in your PH dashboard, '
                . 'then copy its signing secret into the field above.',
        ],
        'convenienceFeePercent' => [
            'FriendlyName' => 'Convenience Fee %',
            'Type'         => 'text',
            'Size'         => '6',
            'Default'      => '',
            'Description'  => 'Optional. Percentage added to each payment (e.g. 2 for 2%).',
        ],
        'descriptionTemplate' => [
            'FriendlyName' => 'Description Template',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Invoice {invoiceid}',
            'Description'  => 'Sent to the PH checkout session. {invoiceid} is replaced.',
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
                . 'If blank, the module uses modules/gateways/paymenthooks/logo.png when present, '
                . 'otherwise no image is shown. (The logo on the PH payment page itself is set in your PH dashboard.)',
        ],
        'enableSubscriptions' => [
            'FriendlyName' => 'Enable Subscriptions',
            'Type'         => 'yesno',
            'Description'  => 'Tick to let recurring products set up a PH subscription (mandate) at checkout.',
        ],
        'paymentModePolicy' => [
            'FriendlyName' => 'Payment Mode',
            'Type'         => 'dropdown',
            'Options'      => [
                'auto'         => 'Auto — subscription for recurring products, one-time otherwise',
                'subscription' => 'Subscription only — force every payment through a mandate',
                'payment'      => 'One-time only — never set up a subscription',
            ],
            'Default'      => 'auto',
            'Description'  => 'Controls how each checkout session is opened. '
                . '"Subscription only" forces a mandate on every invoice and requires Enable Subscriptions to be ticked.',
        ],
        'logging' => [
            'FriendlyName' => 'Debug Logging',
            'Type'         => 'yesno',
            'Description'  => 'Tick to log API calls to Utilities > Logs > Module Log.',
        ],
    ];
}

/**
 * Renders the "Pay Now" button on the invoice. For a redirect gateway this posts
 * to the callback handler, which creates the PH checkout session on click and
 * redirects the client to the hosted page. We do NOT create the session here, so
 * that merely viewing the invoice does not spawn orphan sessions.
 */
function paymenthooks_link($params)
{
    Schema::ensure();

    $systemUrl  = rtrim($params['systemurl'], '/');
    $actionUrl  = $systemUrl . '/modules/gateways/callback/paymenthooks.php';
    $invoiceId  = (int) $params['invoiceid'];
    $buttonText = htmlspecialchars($params['langpaynow'] ?? 'Pay Now', ENT_QUOTES);

    // Resolve the logo: explicit URL wins, else a bundled asset if the merchant
    // dropped one in, else no image at all (no invented/placeholder logo).
    $logoUrl = trim($params['logoUrl'] ?? '');
    if ($logoUrl === '') {
        $assetPath = __DIR__ . '/paymenthooks/logo.png';
        if (is_file($assetPath)) {
            $logoUrl = $systemUrl . '/modules/gateways/paymenthooks/logo.png';
        }
    }

    $heading = trim($params['checkoutHeading'] ?? '');
    $blurb   = trim($params['checkoutBlurb'] ?? '');

    $logoHtml = $logoUrl !== ''
        ? '<div class="paymenthooks-logo" style="margin-bottom:10px"><img src="'
            . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="PaymentHooks" style="max-height:40px"></div>'
        : '';
    $headingHtml = $heading !== ''
        ? '<div class="paymenthooks-heading" style="font-weight:600;margin-bottom:4px">'
            . htmlspecialchars($heading, ENT_QUOTES) . '</div>'
        : '';
    $blurbHtml = $blurb !== ''
        ? '<div class="paymenthooks-blurb" style="color:#666;margin-bottom:10px">'
            . nl2br(htmlspecialchars($blurb, ENT_QUOTES)) . '</div>'
        : '';

    $tpl = file_get_contents(__DIR__ . '/paymenthooks/templates/pay_button.tpl');
    $html = strtr($tpl, [
        '{logo_html}'    => $logoHtml,
        '{heading_html}' => $headingHtml,
        '{blurb_html}'   => $blurbHtml,
        '{action_url}'   => htmlspecialchars($actionUrl, ENT_QUOTES),
        '{invoice_id}'   => $invoiceId,
        '{button_text}'  => $buttonText,
    ]);

    return $html;
}

/**
 * Refund handler. Called when an admin clicks Refund on a transaction.
 * Full refund if no amount constraint; partial when WHMCS passes an amount.
 */
function paymenthooks_refund($params)
{
    Schema::ensure();

    $logger = new Logger('paymenthooks', !empty($params['logging']));
    $config = Config::fromParams($params);

    // The transaction id WHMCS stored at capture is the PH payment id (pay_...).
    $paymentId = $params['transid'];
    $amount    = isset($params['amount']) ? (float) $params['amount'] : null;
    $currency  = $params['currency'] ?? '';

    $body = [];
    if ($amount !== null && $amount > 0) {
        $body['amount'] = InvoiceMapper::toMinorUnits($amount, $currency);
    }
    $body['reason'] = 'Refund issued from WHMCS admin for invoice ' . ($params['invoiceid'] ?? '');

    try {
        $client = new Client($config, $logger);
        $resp = $client->post('/payments/' . rawurlencode($paymentId) . '/refund', $body, 'refund-' . $paymentId . '-' . ($amount ?? 'full'));
    } catch (\Throwable $e) {
        return [
            'status'  => 'declined',
            'rawdata' => $e->getMessage(),
        ];
    }

    $data = $resp['data'] ?? [];
    $refundId = $data['id'] ?? ($data['refundId'] ?? $paymentId);

    return [
        'status'  => 'success',
        'transid' => $refundId,
        'rawdata' => $resp,
    ];
}

/**
 * Cancels a PH subscription when the WHMCS service is cancelled/terminated.
 * Relies on a stored mapping of the WHMCS service to the PH subscription id.
 */
function paymenthooks_cancelSubscription($params)
{
    Schema::ensure();

    $logger = new Logger('paymenthooks', !empty($params['logging']));
    $config = Config::fromParams($params);

    // WHMCS passes the subscription id it stored at setup time.
    $subscriptionId = $params['subscriptionID'] ?? '';
    if ($subscriptionId === '') {
        return [
            'status'  => 'error',
            'rawdata' => 'No PaymentHooks subscription id stored for this service.',
        ];
    }

    try {
        $client = new Client($config, $logger);
        $resp = $client->post(
            '/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
            ['atPeriodEnd' => false],
            'cancel-' . $subscriptionId
        );
    } catch (\Throwable $e) {
        return [
            'status'  => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }

    return [
        'status'  => 'success',
        'rawdata' => $resp,
    ];
}
