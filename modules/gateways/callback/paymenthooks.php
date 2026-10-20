<?php
/**
 * PaymentHooks callback endpoint. Two jobs:
 *
 *  1. action=checkout (POST from the invoice "Pay Now" button): create a PH
 *     checkout session for the invoice and redirect the client to the hosted page.
 *  2. Webhook receiver (POST from PH with X-PaymentHooks-Signature): verify the
 *     signature, dedupe, and reconcile the WHMCS invoice.
 */

use WHMCS\Database\Capsule;
use PaymentHooks\Config;
use PaymentHooks\Client;
use PaymentHooks\ApiException;
use PaymentHooks\Logger;
use PaymentHooks\Signature;
use PaymentHooks\Schema;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\PriceResolver;
use PaymentHooks\WebhookRouter;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/../paymenthooks/lib/Config.php';
require_once __DIR__ . '/../paymenthooks/lib/Logger.php';
require_once __DIR__ . '/../paymenthooks/lib/Client.php';
require_once __DIR__ . '/../paymenthooks/lib/Signature.php';
require_once __DIR__ . '/../paymenthooks/lib/Schema.php';
require_once __DIR__ . '/../paymenthooks/lib/InvoiceMapper.php';
require_once __DIR__ . '/../paymenthooks/lib/PriceResolver.php';
require_once __DIR__ . '/../paymenthooks/lib/SubscriptionSync.php';
require_once __DIR__ . '/../paymenthooks/lib/WebhookRouter.php';

$gatewayModuleName = 'paymenthooks';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    die('PaymentHooks module not activated');
}

Schema::ensure();

$config = Config::fromParams($gatewayParams);
$logger = new Logger($gatewayModuleName, !empty($gatewayParams['logging']));

// Route: a "Pay Now" click carries action=checkout; everything else is a webhook.
if (($_POST['action'] ?? '') === 'checkout' && isset($_POST['invoice_id'])) {
    handle_checkout_click($config, $logger, $gatewayParams);
} else {
    handle_webhook($config, $logger);
}

/**
 * Creates (or reuses) a PH checkout session for the invoice and redirects.
 */
function handle_checkout_click(Config $config, Logger $logger, array $params): void
{
    $invoiceId = (int) $_POST['invoice_id'];

    // A client must be logged in, and must own this invoice. An anonymous POST
    // (uid absent/0) is rejected outright — otherwise anyone could drive checkout
    // for an arbitrary invoice and read its line items/amount.
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($clientId <= 0 || !$invoice || (int) $invoice->userid !== $clientId) {
        redirect_to_invoice($invoiceId, 'paymentfailed');
        return;
    }
    if ($invoice->status === 'Paid') {
        redirect_to_invoice($invoiceId, 'paymentsuccess');
        return;
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $currency  = currency_code_for_invoice($invoiceId);

    // Reuse an open session on refresh rather than spawning duplicates.
    $existing = Capsule::table('mod_paymenthooks_sessions')
        ->where('invoice_id', $invoiceId)
        ->where('status', 'open')
        ->orderBy('id', 'desc')
        ->first();

    try {
        $client = new Client($config, $logger);

        if ($existing && $existing->session_id) {
            // Confirm it is still open at PH; if so, reuse its hosted URL.
            try {
                $check = $client->get('/checkout-sessions/' . rawurlencode($existing->session_id));
                $status = $check['data']['status'] ?? 'expired';
                $url = $check['data']['url'] ?? '';
                if ($status === 'open' && $url !== '') {
                    header('Location: ' . $url);
                    exit;
                }
            } catch (ApiException $e) {
                // fall through and create a new session
            }
        }

        $customerId = resolve_customer_id($client, $invoice);
        $customerEmail = (string) Capsule::table('tblclients')->where('id', $invoice->userid)->value('email');
        $mapped = InvoiceMapper::buildLineItems($invoiceId, $currency);

        $mode = $config->resolveCheckoutMode(invoice_has_recurring_item($invoiceId));

        $description = strtr($config->descriptionTemplate, ['{invoiceid}' => $invoiceId]);

        $body = [
            'mode'          => $mode,
            'currency'      => strtolower($currency),
            'customerEmail' => $customerEmail !== '' ? $customerEmail : null,
            'successUrl'    => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true',
            'cancelUrl'     => $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true',
            'webhookUrl'    => $systemUrl . '/modules/gateways/callback/paymenthooks.php',
            'metadata'      => [
                'whmcs_invoice_id' => (string) $invoiceId,
                'whmcs_system_url' => $systemUrl,
            ],
        ];

        // Subscription mode needs a PH recurring priceId to establish the mandate.
        // Fall back to one-time if the mapping can't be built, so checkout still
        // completes rather than opening a broken subscription session.
        if ($mode === 'subscription') {
            $resolver = new PriceResolver($client, $logger);
            $priceId = $resolver->resolveForInvoice($invoiceId, $currency);
            if ($priceId !== '') {
                $body['priceId'] = $priceId;
            } else {
                $logger->error('subscription-no-price', ['invoice_id' => $invoiceId], 'No PH price resolved; falling back to one-time payment');
                $mode = 'payment';
                $body['mode'] = 'payment';
            }
        }

        // One-time (and the fallback) use line items.
        if ($mode === 'payment') {
            $body['lineItems'] = $mapped['lineItems'];
        }

        if ($customerId !== '') {
            $body['customerId'] = $customerId;
        }
        if ($config->convenienceFeePercent !== null && $config->convenienceFeePercent > 0) {
            $body['convenienceFee'] = (int) round($mapped['subtotal_minor'] * $config->convenienceFeePercent / 100);
        }

        // Idempotency key scoped to THIS attempt: it guards the single create
        // call against a transport-level retry, but must differ between attempts
        // so a new session can be minted after a prior one expired/was cancelled.
        // The prior-session count gives us that per-attempt uniqueness.
        $attemptSeq = (int) Capsule::table('mod_paymenthooks_sessions')
            ->where('invoice_id', $invoiceId)
            ->count();
        $idemKey = 'inv-' . $invoiceId . '-' . $mapped['subtotal_minor'] . '-' . $mode . '-' . $attemptSeq;
        $resp = $client->post('/checkout-sessions', array_filter($body, static function ($v) {
            return $v !== null;
        }), $idemKey);

        $session = $resp['data'] ?? [];
        $sessionId = $session['id'] ?? '';
        $url = $session['url'] ?? '';

        if ($url === '') {
            throw new ApiException('PaymentHooks did not return a checkout URL.');
        }

        $now = time();
        Capsule::table('mod_paymenthooks_sessions')->insert([
            'invoice_id' => $invoiceId,
            'session_id' => $sessionId,
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        header('Location: ' . $url);
        exit;
    } catch (\Throwable $e) {
        $logger->error('checkout-click', ['invoice_id' => $invoiceId], $e->getMessage(), [$config->secretKey]);
        redirect_to_invoice($invoiceId, 'paymentfailed');
    }
}

/**
 * Verifies and processes an incoming PH webhook.
 */
function handle_webhook(Config $config, Logger $logger): void
{
    $rawBody = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_X_PAYMENTHOOKS_SIGNATURE'] ?? '';

    if (!Signature::verify($rawBody, $sigHeader, $config->webhookSecret)) {
        $logger->error('webhook-signature', ['header' => $sigHeader], 'Signature verification failed', [$config->webhookSecret]);
        http_response_code(401);
        die('Invalid signature');
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        http_response_code(400);
        die('Invalid payload');
    }

    // Dedupe by event id (PH delivers at-least-once). event_id is the primary
    // key, so we claim it with an insert FIRST: a duplicate insert throws, which
    // means another delivery already owns this event -> acknowledge and stop.
    // This closes the check-then-insert race. If an id is missing we synthesise a
    // deterministic one from the body so retries of the same payload still dedupe.
    $eventId = $event['id'] ?? '';
    if ($eventId === '') {
        $eventId = 'sha256:' . hash('sha256', $rawBody);
    }

    try {
        Capsule::table('mod_paymenthooks_events')->insert([
            'event_id'    => $eventId,
            'type'        => $event['type'] ?? '',
            'received_at' => time(),
        ]);
    } catch (\Throwable $e) {
        // Duplicate key (or concurrent claim): already handled/claimed.
        http_response_code(200);
        die('Duplicate ignored');
    }

    $router = new WebhookRouter($logger);
    try {
        $handled = $router->dispatch($event);
    } catch (\Throwable $e) {
        // Release the claim so PH's retry can reprocess this event.
        Capsule::table('mod_paymenthooks_events')->where('event_id', $eventId)->delete();
        $logger->error('webhook-dispatch', $event, $e->getMessage());
        http_response_code(500);
        die('Processing error');
    }

    if (!$handled) {
        // Handler asked for a retry: drop the claim so the next delivery runs.
        Capsule::table('mod_paymenthooks_events')->where('event_id', $eventId)->delete();
    }

    http_response_code($handled ? 200 : 500);
    echo $handled ? 'OK' : 'Retry';
}

/* ----------------------------------------------------------------------- */

/** Creates or reuses a PH customer for the invoice's client. */
function resolve_customer_id(Client $client, $invoice): string
{
    $client_row = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client_row) {
        return '';
    }

    $email = trim((string) $client_row->email);
    if ($email === '') {
        return '';
    }

    $name = trim($client_row->firstname . ' ' . $client_row->lastname);
    try {
        $resp = $client->post('/customers', [
            'name'    => $name !== '' ? $name : $email,
            'email'   => $email,
            'country' => $client_row->country ?: null,
        ], 'cust-' . $invoice->userid);
        return $resp['data']['id'] ?? '';
    } catch (ApiException $e) {
        // Duplicate email: look the customer up and reuse it.
        if ($e->errorCode === 'DUPLICATE_EMAIL' || $e->httpStatus === 409) {
            try {
                $list = $client->get('/customers', ['email' => $email, 'pageSize' => 1]);
                $rows = $list['data'] ?? [];
                if (isset($rows[0]['id'])) {
                    return $rows[0]['id'];
                }
            } catch (ApiException $e2) {
                // fall through
            }
        }
        return '';
    }
}

function currency_code_for_invoice(int $invoiceId): string
{
    $row = Capsule::table('tblinvoices')
        ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
        ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
        ->where('tblinvoices.id', $invoiceId)
        ->value('tblcurrencies.code');
    return $row ? strtoupper($row) : 'INR';
}

function invoice_has_recurring_item(int $invoiceId): bool
{
    // A hosting line whose product has a non-"onetime" billing cycle implies recurring.
    $relIds = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('type', 'Hosting')
        ->pluck('relid')
        ->all();
    if (!$relIds) {
        return false;
    }
    $cycles = Capsule::table('tblhosting')
        ->whereIn('id', $relIds)
        ->pluck('billingcycle')
        ->all();
    foreach ($cycles as $cycle) {
        if ($cycle && strtolower($cycle) !== 'one time' && strtolower($cycle) !== 'free') {
            return true;
        }
    }
    return false;
}

function redirect_to_invoice(int $invoiceId, string $flag): void
{
    $systemUrl = rtrim(\App::getSystemURL(), '/');
    header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&' . $flag . '=true');
    exit;
}
