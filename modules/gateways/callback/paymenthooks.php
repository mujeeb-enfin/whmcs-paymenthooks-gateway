<?php
/**
 * PaymentHooks callback endpoint. Two jobs:
 *
 *  1. action=checkout (POST from the invoice "Pay Now" or "Subscribe & Auto-pay"
 *     button, mode=payment|subscription): create a PH checkout session for the
 *     invoice and redirect the client to the hosted page.
 *  2. Webhook receiver (POST from the PH webhook endpoint with
 *     X-PaymentHooks-Signature): verify, dedupe, and reconcile the WHMCS invoice.
 */

use WHMCS\Database\Capsule;
use PaymentHooks\Config;
use PaymentHooks\Client;
use PaymentHooks\ApiException;
use PaymentHooks\Logger;
use PaymentHooks\GatewayLog;
use PaymentHooks\Signature;
use PaymentHooks\Schema;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\CheckoutBuilder;
use PaymentHooks\InvoicePlan;
use PaymentHooks\WebhookRouter;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/../paymenthooks/lib/bootstrap.php';

$gatewayModuleName = 'paymenthooks';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    // Activity Log, not Gateway Log: logTransaction needs an active gateway.
    logActivity('PaymentHooks: callback called but the PaymentHooks gateway is not activated in WHMCS.');
    http_response_code(503);
    die('PaymentHooks module not activated');
}

try {
    Schema::ensure();
} catch (\Throwable $e) {
    logTransaction($gatewayModuleName, ['error' => $e->getMessage()], 'Error');
    http_response_code(500);
    die('Retry');
}

$config = Config::fromParams($gatewayParams);
$logger = new Logger($gatewayModuleName, $config->logging);

// Route: a "Pay Now" click carries action=checkout; everything else is a webhook.
if (($_POST['action'] ?? '') === 'checkout' && isset($_POST['invoice_id'])) {
    handle_checkout_click($config, $logger, $gatewayParams);
} else {
    handle_webhook($config, $logger, (string) file_get_contents('php://input'));
}

/**
 * Creates (or reuses) a PH checkout session for the invoice and redirects.
 */
function handle_checkout_click(Config $config, Logger $logger, array $params): void
{
    $invoiceId = (int) $_POST['invoice_id'];

    // Only the logged-in owner of the invoice may start its checkout.
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($clientId <= 0 || !$invoice || (int) $invoice->userid !== $clientId) {
        redirect_to_invoice($invoiceId, 'paymentfailed');
    }
    if ($invoice->status === 'Paid') {
        redirect_to_invoice($invoiceId, 'paymentsuccess');
    }
    if ($invoice->status !== 'Unpaid') {
        // Cancelled, Refunded, Collections or Draft invoices are not payable.
        redirect_to_invoice($invoiceId, 'paymentfailed');
    }

    try {
        $client = new Client($config, $logger);
        $currency = WebhookRouter::invoiceCurrency((int) $invoice->userid);
        $mapped = InvoiceMapper::buildLineItems($invoiceId, $currency);
        // The checkout shows one "Invoice #N" line instead of the invoice's own
        // lines only when they cannot add up to the balance; record why.
        if ($mapped['fallbackReason'] !== '') {
            $logger->info('checkout-single-line', ['invoiceId' => $invoiceId, 'balanceMinor' => $mapped['subtotal_minor']],
                'Invoice ' . $invoiceId . ' is sent as one "Invoice #' . $invoiceId . '" line: ' . $mapped['fallbackReason'] . '.');
        }
        // The client chose with the button they pressed; Pay Now is one-time.
        $mode = $config->resolveCheckoutMode((string) ($_POST['mode'] ?? 'payment'));
        $plan = $mode === 'subscription' ? InvoicePlan::forInvoice($invoiceId, $currency) : null;
        if ($plan !== null && empty($plan['offer'])) {
            throw new \RuntimeException('Invoice ' . $invoiceId . ' cannot be subscribed [' . $plan['reasonCode'] . ']: '
                . $plan['reason'] . ($plan['detail'] !== '' ? ' ' . $plan['detail'] : ''));
        }

        // PaymentHooks has taken (or is taking) a payment whose webhook has not
        // arrived yet: never start a second charge.
        if (payment_in_flight($client, $invoiceId)) {
            redirect_to_invoice($invoiceId, 'paymentsuccess');
        }

        // Reuse the open session for this exact balance on refresh/back.
        $reuse = reusable_session_url($client, $config, $invoiceId, $mapped['subtotal_minor'], $mode);
        if ($reuse !== '') {
            header('Location: ' . $reuse);
            exit;
        }

        $email = trim((string) Capsule::table('tblclients')->where('id', $invoice->userid)->value('email'));
        $body = CheckoutBuilder::build(
            $config,
            $invoiceId,
            $currency,
            $mapped,
            $mode,
            (string) $params['systemurl'],
            resolve_customer_id($client, (int) $invoice->userid),
            $email,
            $plan
        );

        // Unique per attempt: guards one create against a double submit, while
        // a later attempt (after expiry or a balance change) gets a new session.
        $attemptSeq = (int) Capsule::table('mod_paymenthooks_sessions')->where('invoice_id', $invoiceId)->count();
        $idemKey = CheckoutBuilder::idempotencyKey(Schema::installId(), $invoiceId, $mapped['subtotal_minor'], $mode, $attemptSeq);
        $resp = $client->post('/checkout-sessions', $body, $idemKey);

        $sessionId = (string) ($resp['data']['id'] ?? '');
        $url = (string) ($resp['data']['url'] ?? '');
        if ($sessionId === '' || $url === '') {
            throw new ApiException('PaymentHooks did not return a checkout session id and URL.', 'UNEXPECTED_RESPONSE');
        }
        // A PaymentHooks API that drops `recurring`, `recurring.startsAt` or
        // cannot open one subscription per billing group would charge
        // something other than what the client was shown. Refuse it (the
        // client is never sent there) instead of guessing.
        if ($mode === 'subscription') {
            $problem = CheckoutBuilder::recurringProblem($body['lineItems'], (array) $resp['data']);
            if ($problem !== '') {
                throw new ApiException('PaymentHooks did not accept the subscription line items for session ' . $sessionId . ': '
                    . $problem . '. Subscribe & Auto-pay was refused; the client can use Pay Now.', 'RECURRING_LINE_ITEMS_UNSUPPORTED');
            }
        }

        $now = time();
        Capsule::table('mod_paymenthooks_sessions')->insert([
            'invoice_id' => $invoiceId,
            'session_id' => $sessionId,
            'status'     => 'open',
            'fee_minor'  => (int) ($body['itemConvenienceFeeLocalCurrencyAmount'] ?? 0),
            // What this checkout subscribes, one group per subscription, bound on completion.
            'plan_json'  => $plan ? json_encode(['groups' => $plan['groups'], 'subscribed' => $plan['subscribed']], JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        header('Location: ' . $config->checkoutUrl($url));
        exit;
    } catch (\Throwable $e) {
        $logger->error('checkout-click', ['invoice_id' => $invoiceId], get_class($e) . ': ' . $e->getMessage(), [$config->secretKey]);
        logTransaction('paymenthooks', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()], 'Checkout Error');
        redirect_to_invoice($invoiceId, 'paymentfailed');
    }
}

/**
 * True when a session for this invoice is paid or being paid at PaymentHooks
 * (status complete or pending) but WHMCS has not recorded the payment yet.
 * Sessions marked completed locally were already applied by the webhook.
 */
function payment_in_flight(Client $client, int $invoiceId): bool
{
    $rows = Capsule::table('mod_paymenthooks_sessions')
        ->where('invoice_id', $invoiceId)
        ->whereIn('status', ['open', 'pending', 'complete'])
        ->orderBy('id', 'desc')
        ->limit(5)
        ->get();
    foreach ($rows as $row) {
        if (!$row->session_id) {
            continue;
        }
        $s = $client->get('/checkout-sessions/' . rawurlencode($row->session_id))['data'] ?? [];
        $status = (string) ($s['status'] ?? '');
        if ($status === 'complete' || $status === 'pending') {
            Capsule::table('mod_paymenthooks_sessions')
                ->where('id', $row->id)
                ->update(['status' => $status, 'updated_at' => time()]);
            return true;
        }
    }
    return false;
}

/**
 * Hosted URL of this invoice's latest session if it is still open at PH, not
 * about to expire, and was opened for the same balance and mode, else ''.
 */
function reusable_session_url(Client $client, Config $config, int $invoiceId, int $subtotalMinor, string $mode): string
{
    $existing = Capsule::table('mod_paymenthooks_sessions')
        ->where('invoice_id', $invoiceId)
        ->where('status', 'open')
        ->orderBy('id', 'desc')
        ->first();
    if (!$existing || !$existing->session_id) {
        return '';
    }

    $check = $client->get('/checkout-sessions/' . rawurlencode($existing->session_id));
    $s = $check['data'] ?? [];
    $expiresAt = isset($s['expiresAt']) ? strtotime((string) $s['expiresAt']) : false;
    // A minute of margin so the client is not sent to a page about to expire.
    $stillOpen = ($s['status'] ?? '') === 'open' && $expiresAt !== false && $expiresAt > time() + 60;
    // The session's line-item subtotal, in the invoice currency's minor units.
    $sameBalance = (int) ($s['itemSubtotalLocalCurrencyAmount'] ?? -1) === $subtotalMinor;
    $sameMode = ($s['mode'] ?? '') === $mode;
    if ($stillOpen && $sameBalance && $sameMode && !empty($s['url'])) {
        return $config->checkoutUrl((string) $s['url']);
    }

    // Expired or for an old balance: never reuse it. (Paid sessions were
    // caught by payment_in_flight before this.)
    Capsule::table('mod_paymenthooks_sessions')
        ->where('id', $existing->id)
        ->update(['status' => 'stale', 'updated_at' => time()]);
    return '';
}

/**
 * Verifies and processes an incoming PH webhook.
 */
function handle_webhook(Config $config, Logger $logger, string $rawBody): void
{
    $sigHeader = (string) ($_SERVER['HTTP_X_PAYMENTHOOKS_SIGNATURE'] ?? '');
    $secrets = [$config->secretKey, $config->webhookSecret];
    $log = new GatewayLog();
    $base = [
        'delivery' => (string) ($_SERVER['HTTP_X_PAYMENTHOOKS_DELIVERY'] ?? ''),
        'event'    => (string) ($_SERVER['HTTP_X_PAYMENTHOOKS_EVENT'] ?? ''),
    ];

    if (!Signature::verify($rawBody, $sigHeader, $config->webhookSecret)) {
        $log->set(GatewayLog::BAD_SIGNATURE, $config->webhookSecret === ''
            ? 'Webhook Signing Secret is not set in the module settings.'
            : 'Signature did not match: the Webhook Signing Secret must be the whsec_ secret of this endpoint, '
                . 'and the server clock must be within 5 minutes.', ['signatureHeader' => $sigHeader]);
        $logger->error('webhook-signature', ['header' => $sigHeader], 'Signature verification failed', $secrets);
        respond_webhook($log, $base, $secrets, 401, 'Invalid signature');
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
        $log->set(GatewayLog::BAD_PAYLOAD, 'Signed body is not a PaymentHooks event envelope.', ['body' => substr($rawBody, 0, 500)]);
        respond_webhook($log, $base, $secrets, 400, 'Invalid payload');
    }

    $eventId = (string) $event['id'];
    $base = ['eventId' => $eventId, 'type' => (string) $event['type'], 'livemode' => !empty($event['livemode'])] + $base;

    // Claim the event id (primary key). Retries of one event keep the same id.
    // A finished event is acknowledged; a claim left by a delivery that died
    // mid-way (timeout, fatal) is taken over after 10 minutes. Applying twice
    // is still prevented: payments are checked by transaction id first.
    try {
        $claimed = claim_event($eventId, (string) $event['type'], $logger);
    } catch (\Throwable $e) {
        $log->set(GatewayLog::ERROR, 'Could not record the event (' . $e->getMessage() . '); PaymentHooks will redeliver it.');
        $logger->error('webhook-claim', ['id' => $eventId], get_class($e) . ': ' . $e->getMessage(), $secrets);
        respond_webhook($log, $base, $secrets, 500, 'Retry');
    }
    if (!$claimed) {
        $log->set(GatewayLog::DUPLICATE, 'Event already processed (or being processed); acknowledged.');
        respond_webhook($log, $base, $secrets, 200, 'Duplicate ignored');
    }

    try {
        $handled = (new WebhookRouter($logger, $log))->dispatch($event);
    } catch (\Throwable $e) {
        $handled = false;
        $log->set(GatewayLog::ERROR, get_class($e) . ': ' . $e->getMessage() . '. PaymentHooks will redeliver the event.');
        $logger->error('webhook-dispatch', ['id' => $eventId, 'type' => $event['type']], get_class($e) . ': ' . $e->getMessage(), $secrets);
    }

    if (!$handled) {
        // Release the claim so PaymentHooks' redelivery is processed.
        Capsule::table('mod_paymenthooks_events')->where('event_id', $eventId)->delete();
        respond_webhook($log, $base, $secrets, 500, 'Retry');
    }

    Capsule::table('mod_paymenthooks_events')->where('event_id', $eventId)->update(['status' => 'done']);
    respond_webhook($log, $base, $secrets, 200, 'OK');
}

/** Writes the delivery's Gateway Log entry, then answers PaymentHooks. */
function respond_webhook(GatewayLog $log, array $base, array $secrets, int $status, string $body): void
{
    try {
        $log->write('paymenthooks', $base + ['httpStatus' => $status], $secrets);
    } catch (\Throwable $e) {
        // The response must still be sent; the failure itself is logged.
        logActivity('PaymentHooks: could not write the Gateway Log entry: ' . $e->getMessage());
    }
    http_response_code($status);
    die($body);
}

/**
 * Claims an event for processing. False when it is already done or another
 * delivery is processing it right now. Database errors other than a
 * duplicate key are thrown (the caller answers 500, PaymentHooks redelivers).
 */
function claim_event(string $eventId, string $type, Logger $logger): bool
{
    $now = time();
    try {
        Capsule::table('mod_paymenthooks_events')->insert([
            'event_id'    => $eventId,
            'type'        => $type,
            'status'      => 'processing',
            'received_at' => $now,
        ]);
        return true;
    } catch (\Illuminate\Database\QueryException $e) {
        // SQLSTATE 23000 = integrity constraint (duplicate key).
        if ((string) $e->getCode() !== '23000') {
            throw $e;
        }
    }

    // Take over a processing claim older than 10 minutes (the earlier
    // delivery died). The conditional update lets only one taker win.
    $taken = Capsule::table('mod_paymenthooks_events')
        ->where('event_id', $eventId)
        ->where('status', 'processing')
        ->where('received_at', '<', $now - 600)
        ->update(['received_at' => $now]);
    if ($taken > 0) {
        $logger->error('webhook-reclaim', ['id' => $eventId, 'type' => $type], 'Earlier delivery did not finish; reprocessing');
        return true;
    }
    return false;
}

/* ----------------------------------------------------------------------- */

/**
 * The PH customer for this WHMCS client: created on first checkout, then
 * found by exact email (PaymentHooks returns 409 DUPLICATE_EMAIL on create).
 *
 * @throws ApiException when it can be neither created nor found
 */
function resolve_customer_id(Client $client, int $userId): string
{
    $row = Capsule::table('tblclients')->where('id', $userId)->first();
    if (!$row || trim((string) $row->email) === '') {
        throw new \RuntimeException('WHMCS client ' . $userId . ' has no email address.');
    }
    $body = CheckoutBuilder::customerBody((array) $row);
    $email = $body['email'];

    try {
        $resp = $client->post('/customers', $body);
        $id = (string) ($resp['data']['id'] ?? '');
        if ($id === '') {
            throw new ApiException('PaymentHooks returned no customer id.', 'UNEXPECTED_RESPONSE');
        }
        return $id;
    } catch (ApiException $e) {
        if ($e->errorCode !== 'DUPLICATE_EMAIL') {
            throw $e;
        }
    }

    // `search` matches name or email by substring; take the exact email.
    $list = $client->get('/customers', ['search' => $email, 'pageSize' => 100]);
    foreach ($list['data'] ?? [] as $customer) {
        if (strcasecmp((string) ($customer['email'] ?? ''), $email) === 0 && !empty($customer['id'])) {
            // Fill in a phone WHMCS has and PaymentHooks lacks, so checkout
            // does not ask the client for it. Never overwrite a stored value.
            if (isset($body['phone']) && trim((string) ($customer['phone'] ?? '')) === '') {
                $client->put('/customers/' . rawurlencode((string) $customer['id']), ['phone' => $body['phone']]);
            }
            return (string) $customer['id'];
        }
    }
    throw new ApiException('PaymentHooks reported a duplicate email but the customer could not be found.', 'CUSTOMER_LOOKUP_FAILED');
}

function redirect_to_invoice(int $invoiceId, string $flag): void
{
    $systemUrl = rtrim(\App::getSystemURL(), '/');
    header('Location: ' . $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&' . $flag . '=true');
    exit;
}
