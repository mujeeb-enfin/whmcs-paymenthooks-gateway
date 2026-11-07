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
use PaymentHooks\InvoicePlan;
use PaymentHooks\PayButton;
use PaymentHooks\RefundMapper;
use PaymentHooks\RefundSync;
use PaymentHooks\SubscriptionMap;
use PaymentHooks\SubscriptionSync;

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
        // PaymentHooks holds the mandate and charges every renewal itself;
        // WHMCS stores no tokens and has no capture function, so WHMCS can
        // never be the one that triggers a charge.
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
                . 'subscription.renewed, subscription.renewal_failed, subscription.cancelled, '
                . 'subscription.deleted and invoice.paid.',
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
        'taxName' => [
            'FriendlyName' => 'Tax Name',
            'Type'         => 'text',
            'Size'         => '20',
            'Default'      => '',
            'Description'  => 'Optional. Name of the tax your invoices carry (e.g. GST, VAT), shown on the checkout and receipt next to the WHMCS tax amount.',
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
            'Description'  => 'Tick to show a "Subscribe & Auto-pay" button next to Pay Now on invoices with hosting, '
                . 'domains or addons. The client chooses; PaymentHooks then charges every renewal itself and WHMCS '
                . 'records it from the webhook.',
        ],
        'subscribeButtonText' => [
            'FriendlyName' => 'Subscribe Button Text',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => 'Subscribe & Auto-pay',
            'Description'  => 'Label of the subscription button.',
        ],
        'logging' => [
            'FriendlyName' => 'Debug Logging',
            'Type'         => 'yesno',
            'Description'  => 'Tick to log every API call to Utilities > Logs > Module Log. Errors are always logged.',
        ],
    ];
}

/**
 * Renders the invoice payment block: "Pay Now" always, and "Subscribe &
 * Auto-pay" next to it when subscriptions are enabled and the invoice has an
 * item that can renew automatically. Both post to the callback, which creates
 * the PH checkout session on click, so viewing an invoice never creates one.
 */
function paymenthooks_link($params)
{
    Schema::ensure();

    $config = Config::fromParams($params);
    $problems = $config->validate();
    if ($problems) {
        (new Logger('paymenthooks', true))->error('config', [], implode(' ', $problems));
        return '<div class="alert alert-danger" id="paymenthooks_pay_unavailable">'
            . 'Online payment is unavailable. Please contact us.</div>';
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $invoiceId = (int) $params['invoiceid'];
    $currency  = strtoupper((string) ($params['currency'] ?? ''));

    $plan = null;
    $planError = false;
    if ($config->subscriptionsEnabled) {
        $logger = new Logger('paymenthooks', $config->logging);
        try {
            if ($currency === '') {
                throw new \RuntimeException('The invoice has no currency code.');
            }
            $plan = InvoicePlan::forInvoice($invoiceId, $currency);
        } catch (\Throwable $e) {
            // Pay Now still works; the client sees that auto-pay is unavailable right now.
            $planError = true;
            $logger->error('subscribe-plan', ['invoice_id' => $invoiceId], get_class($e) . ': ' . $e->getMessage());
        }
        // Once per invoice view: WHMCS can call _link more than once while rendering a page.
        static $logged = [];
        if ($plan !== null && empty($plan['offer']) && !isset($logged[$invoiceId])) {
            $logged[$invoiceId] = true;
            $logger->info('subscribe-unavailable', ['invoice_id' => $invoiceId, 'reason' => $plan['reasonCode'], 'detail' => $plan['detail']],
                'Subscribe & Auto-pay not offered on invoice ' . $invoiceId . ': ' . $plan['reason']);
        }
    }

    $logoUrl = trim($params['logoUrl'] ?? '');
    if ($logoUrl === '' && is_file(__DIR__ . '/paymenthooks/logo.png')) {
        $logoUrl = $systemUrl . '/modules/gateways/paymenthooks/logo.png';
    }

    return PayButton::render((string) file_get_contents(__DIR__ . '/paymenthooks/templates/pay_button.tpl'), [
        'actionUrl'     => $systemUrl . '/modules/gateways/callback/paymenthooks.php',
        'invoiceId'     => $invoiceId,
        'payText'       => (string) ($params['langpaynow'] ?? 'Pay Now'),
        'subscribeText' => (string) ($params['subscribeButtonText'] ?? ''),
        'logoUrl'       => $logoUrl,
        'heading'       => (string) ($params['checkoutHeading'] ?? ''),
        'blurb'         => (string) ($params['checkoutBlurb'] ?? ''),
        'currency'      => $currency,
        'plan'          => $plan,
        'planError'     => $planError,
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

    // Reserved before the call, so this refund's own payment.refunded webhook
    // (which can arrive before WHMCS saves the refund) is not booked again.
    $reservation = RefundSync::reserveWhmcsRefund($paymentId, $amountMinor);
    try {
        $resp = $client->post(
            '/payments/' . rawurlencode($paymentId) . '/refund',
            $body,
            'refund-' . $paymentId . '-' . $amountMinor . '-' . $refundsSoFar
        );
    } catch (ApiException $e) {
        RefundSync::releaseWhmcsRefund($reservation);
        return ['status' => 'declined', 'rawdata' => $e->errorCode . ': ' . $e->getMessage()];
    }

    $refundId = (string) ($resp['data']['id'] ?? '');
    if ($refundId === '') {
        $logger->error('refund-response', compact('paymentId', 'amountMinor'), 'PaymentHooks returned no refund id; check the payment in PaymentHooks before retrying.');
        return ['status' => 'error', 'rawdata' => $resp];
    }
    RefundSync::confirmWhmcsRefund($reservation, $refundId);
    return ['status' => 'success', 'transid' => $refundId, 'rawdata' => $resp];
}

/**
 * Cancels the PH subscription when the WHMCS service is cancelled or
 * terminated (or the admin clicks Cancel Subscription). WHMCS passes the id
 * mirrored on the item's subscriptionid. One subscription can renew several
 * items (hosting, its domain, addons): it is cancelled for all of them, and
 * the others then renew through their WHMCS invoices.
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
            SubscriptionMap::unbind($subscriptionId);
            return ['status' => 'success', 'rawdata' => $e->response];
        }
        $logger->error('cancel-subscription', ['subscriptionId' => $subscriptionId], $e->errorCode . ': ' . $e->getMessage(), [$config->secretKey]);
        return ['status' => 'error', 'rawdata' => $e->errorCode . ': ' . $e->getMessage()];
    }

    $items = SubscriptionMap::unbind($subscriptionId);
    if (count($items) > 1) {
        logActivity('PaymentHooks: subscription ' . $subscriptionId . ' cancelled; it also auto-renewed '
            . SubscriptionSync::describe($items) . ', which now renew through their WHMCS invoices.');
    }
    return ['status' => 'success', 'rawdata' => $resp];
}
