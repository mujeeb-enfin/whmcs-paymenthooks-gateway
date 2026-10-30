<?php
/**
 * Standalone tests for the module's pure logic. No WHMCS, network or database.
 *
 *   php tests/run.php
 *
 * Webhook fixtures in tests/fixtures are generated from the PaymentHooks
 * payload builders (src/services/webhooks/whmcs-fixtures.test.ts in the
 * PaymentHooks repo), so these tests parse the exact live event shapes.
 */

define('WHMCS', true);

$lib = __DIR__ . '/../modules/gateways/paymenthooks/lib/';
require_once $lib . 'Config.php';
require_once $lib . 'Logger.php';
require_once $lib . 'Client.php';
require_once $lib . 'Signature.php';
require_once $lib . 'EventParser.php';
require_once $lib . 'CheckoutBuilder.php';
require_once $lib . 'PriceResolver.php';

// InvoiceMapper and WebhookRouter reference Capsule only in DB methods, which
// these tests never call.
if (!class_exists('WHMCS\\Database\\Capsule')) {
    eval('namespace WHMCS\\Database; class Capsule {}');
}
require_once $lib . 'InvoiceMapper.php';
require_once $lib . 'RefundMapper.php';

use PaymentHooks\CheckoutBuilder;
use PaymentHooks\RefundMapper;
use PaymentHooks\Client;
use PaymentHooks\Config;
use PaymentHooks\ConfigException;
use PaymentHooks\EventParser;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\PriceResolver;
use PaymentHooks\Signature;

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function same(string $name, $expected, $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $name, callable $fn, string $class): void
{
    try {
        $fn();
        check($name, false, "no {$class} thrown");
    } catch (\Throwable $e) {
        check($name, $e instanceof $class, 'threw ' . get_class($e) . ': ' . $e->getMessage());
    }
}

function fixture(string $name): array
{
    $path = __DIR__ . '/fixtures/' . $name . '.json';
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Missing or invalid fixture {$path}\n");
        exit(2);
    }
    return $data;
}

function config(array $overrides = []): Config
{
    return Config::fromParams($overrides + [
        'secretKey'     => 'phk_live_' . str_repeat('a', 64),
        'webhookSecret' => 'whsec_' . str_repeat('b', 32),
    ]);
}

echo "Signature\n";
$secret = 'whsec_test_secret';
$body = '{"id":"evt_1","type":"checkout.session.completed"}';
$t = (string) time();
$sig = hash_hmac('sha256', $t . '.' . $body, $secret);
check('valid v1', Signature::verify($body, "t={$t},v1={$sig}", $secret));
check('valid v1_previous during rotation', Signature::verify($body, "t={$t},v1=deadbeef,v1_previous={$sig}", $secret));
check('tampered body rejected', !Signature::verify($body . ' ', "t={$t},v1={$sig}", $secret));
$old = (string) (time() - 301);
check('stale timestamp rejected', !Signature::verify($body, "t={$old},v1=" . hash_hmac('sha256', $old . '.' . $body, $secret), $secret));
check('empty secret rejected', !Signature::verify($body, "t={$t},v1={$sig}", ''));
check('missing t rejected', !Signature::verify($body, "v1={$sig}", $secret));

echo "Config\n";
same('valid config has no problems', [], config()->validate());
check('old sk_live_ key rejected', count(config(['secretKey' => 'sk_live_abc'])->validate()) === 1);
check('missing webhook secret reported', count(config(['webhookSecret' => ''])->validate()) === 1);
check('bad publishable key reported', count(config(['publishableKey' => 'pk_live_x'])->validate()) === 1);
check('fee over 100% reported', count(config(['convenienceFeePercent' => '150'])->validate()) === 1);
same('API base is fixed', 'https://paymenthooks.com/api/v1', config(['customDomain' => 'pay.example.com'])->baseUrl);
same('host normalised', 'pay.example.com', Config::normaliseHost(' HTTPS://Pay.Example.com:443/checkout/ '));
same('own host means none', '', Config::normaliseHost('https://paymenthooks.com'));
same('checkout URL moves to custom domain', 'https://pay.example.com/checkout/cs_1',
    config(['customDomain' => 'pay.example.com'])->checkoutUrl('https://paymenthooks.com/checkout/cs_1'));
same('checkout URL unchanged without custom domain', 'https://paymenthooks.com/checkout/cs_1',
    config()->checkoutUrl('https://paymenthooks.com/checkout/cs_1'));
same('yesno "on" enables subscriptions', true, config(['enableSubscriptions' => 'on'])->subscriptionsEnabled);
same('auto + recurring + enabled = subscription', 'subscription', config(['enableSubscriptions' => 'on'])->resolveCheckoutMode(true));
same('auto + one-time = payment', 'payment', config(['enableSubscriptions' => 'on'])->resolveCheckoutMode(false));
same('auto + recurring + disabled = payment', 'payment', config()->resolveCheckoutMode(true));
same('payment policy ignores recurring', 'payment', config(['enableSubscriptions' => 'on', 'paymentModePolicy' => 'payment'])->resolveCheckoutMode(true));
throws('subscription policy without the feature fails', function () {
    config(['paymentModePolicy' => 'subscription'])->resolveCheckoutMode(true);
}, ConfigException::class);
same('idempotency key sanitised', 'inv-1-a-b', Client::idempotencyKey('inv-1 a/b'));
same('idempotency key capped at PaymentHooks limit of 200', 200, strlen(Client::idempotencyKey(str_repeat('k', 300))));

echo "Minor units\n";
same('INR 499.00', 49900, InvoiceMapper::toMinorUnits(499.00, 'INR'));
same('USD 0.1 + 0.2 rounds', 30, InvoiceMapper::toMinorUnits(0.1 + 0.2, 'usd'));
same('JPY has no minor unit', 1500, InvoiceMapper::toMinorUnits(1500, 'JPY'));
same('ISK uses two decimals (PaymentHooks list)', 150000, InvoiceMapper::toMinorUnits(1500, 'ISK'));
same('MGA is zero-decimal', 1500, InvoiceMapper::toMinorUnits(1500, 'MGA'));
same('back to major', 499.0, InvoiceMapper::toMajorUnits(49900, 'INR'));

echo "Line items\n";
$rows = [['description' => "Hosting\nStarter plan", 'amount' => 800.0], ['description' => 'Domain', 'amount' => 200.0]];
$m = InvoiceMapper::lineItemsForBalance(7, $rows, 100000, 'INR');
same('rows used when they equal the balance', 2, count($m['lineItems']));
same('multi-line description flattened', 'Hosting Starter plan', $m['lineItems'][0]['description']);
$m = InvoiceMapper::lineItemsForBalance(7, $rows, 118000, 'INR');
same('single balance line when tax makes rows differ', [['description' => 'Invoice #7', 'quantity' => 1, 'unitPrice' => 118000]], $m['lineItems']);
$m = InvoiceMapper::lineItemsForBalance(7, [['description' => 'Hosting', 'amount' => 1000.0], ['description' => 'Credit', 'amount' => -200.0]], 80000, 'INR');
same('negative rows collapse to the balance', 80000, $m['lineItems'][0]['unitPrice']);
same('subtotal is the balance', 80000, $m['subtotal_minor']);
throws('nothing due fails', function () {
    InvoiceMapper::lineItemsForBalance(7, [], 0, 'INR');
}, \RuntimeException::class);

echo "Checkout body\n";
$mapped = ['lineItems' => [['description' => 'Invoice #9', 'quantity' => 1, 'unitPrice' => 100000]], 'subtotal_minor' => 100000];
$b = CheckoutBuilder::build(config(['convenienceFeePercent' => '2']), 9, 'INR', $mapped, 'payment', 'https://billing.example.com/', 'ph_cus_1', 'c@example.com', '');
same('itemLocalCurrency upper case', 'INR', $b['itemLocalCurrency']);
check('no retired currency field', !array_key_exists('currency', $b));
same('line items carry unitPrice in minor units', 100000, $b['lineItems'][0]['unitPrice']);
same('line items always sent', 1, count($b['lineItems']));
same('fee is an integer of the subtotal', 2000, $b['itemConvenienceFeeLocalCurrencyAmount']);
same('invoice id in metadata', '9', $b['metadata']['whmcs_invoice_id']);
check('no webhookUrl (registered endpoint only)', !array_key_exists('webhookUrl', $b));
same('success URL', 'https://billing.example.com/viewinvoice.php?id=9&paymentsuccess=true', $b['successUrl']);
check('no priceId on one-time', !isset($b['metadata']['priceId']));
$b = CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'subscription', 'https://billing.example.com', '', '', 'ph_pri_1');
same('subscription price in metadata.priceId', 'ph_pri_1', $b['metadata']['priceId']);
check('no top-level priceId', !array_key_exists('priceId', $b));
check('no empty customer fields', !array_key_exists('customerId', $b) && !array_key_exists('customerEmail', $b));
check('no fee field without a fee', !array_key_exists('itemConvenienceFeeLocalCurrencyAmount', $b));
$b = CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'payment', 'https://billing.example.com', '', '', '');
same('itemTaxLocalCurrencyAmount 0 so PaymentHooks auto-tax never taxes the WHMCS balance again', 0, $b['itemTaxLocalCurrencyAmount']);
check('no retired taxAmount / convenienceFee fields', !array_key_exists('taxAmount', $b) && !array_key_exists('convenienceFee', $b));
$k1 = CheckoutBuilder::idempotencyKey('aaaa', 9, 100000, 'payment', 0);
$k2 = CheckoutBuilder::idempotencyKey('bbbb', 9, 100000, 'payment', 0);
check('idempotency key differs per install', $k1 !== $k2);
check('idempotency key fits the 200 limit', strlen($k1) <= 200 && Client::idempotencyKey($k1) === $k1);
throws('subscription without price fails', function () use ($mapped) {
    CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'subscription', 'https://b.example.com', '', '', '');
}, \InvalidArgumentException::class);
throws('missing currency fails', function () use ($mapped) {
    CheckoutBuilder::build(config(), 9, '', $mapped, 'payment', 'https://b.example.com', '', '', '');
}, \InvalidArgumentException::class);

echo "Events (live payload shapes)\n";
$e = EventParser::checkoutCompleted(fixture('checkout_session_completed'));
same('completed: session id', 'cs_whmcs_1', $e['sessionId']);
same('completed: payment id from payment_intent', 'ph_pay_whmcs_1', $e['paymentId']);
same('completed: amount_total in minor units', 102000, $e['amountMinor']);
same('completed: currency upper-cased', 'INR', $e['currency']);
same('completed: amount_usd_cents read', 1074, $e['amountUsdCents']);
same('completed: invoice id from metadata', 1001, $e['invoiceId']);
same('completed: paid', true, $e['paid']);
same('completed: no subscription', '', $e['subscriptionId']);
$e = EventParser::checkoutCompleted(fixture('checkout_session_completed_subscription'));
same('subscription checkout: subscription id', 'ph_sub_whmcs_1', $e['subscriptionId']);
$r = EventParser::paymentRefunded(fixture('payment_refunded'));
same('refunded: payment id', 'ph_pay_whmcs_1', $r['paymentId']);
same('refunded: amount refunded', 50000, $r['amountRefundedMinor']);
same('refunded: amount_usd_cents read', 1071, $r['amountUsdCents']);
$s = EventParser::subscription(fixture('subscription_renewed'));
same('renewed: subscription id', 'ph_sub_whmcs_1', $s['subscriptionId']);
same('renewed: payment id from latest_payment', 'ph_pay_whmcs_3', $s['paymentId']);
same('renewed: amount', 49900, $s['amountMinor']);
same('renewed: currency', 'INR', $s['currency']);
$s = EventParser::subscription(fixture('subscription_renewed_credit'));
same('credit-covered renewal has no payment', '', $s['paymentId']);
$s = EventParser::subscription(fixture('subscription_cancelled'));
same('cancelled: id and status', ['ph_sub_whmcs_1', 'cancelled'], [$s['subscriptionId'], $s['status']]);
same('non-numeric invoice id ignored', 0, EventParser::invoiceId(['metadata' => ['whmcs_invoice_id' => '1 OR 1=1']]));
$f = fixture('checkout_session_completed');
$f['data']['object']['amount_total'] = 102000.0;
same('amount encoded as a whole float is read', 102000, EventParser::checkoutCompleted($f)['amountMinor']);
$f['data']['object']['amount_total'] = 1020.5;
same('fractional amount rejected', 0, EventParser::checkoutCompleted($f)['amountMinor']);

echo "Billing cycles\n";
$cycles = [
    'Weekly' => ['week', 1], 'Monthly' => ['month', 1], 'Quarterly' => ['month', 3], 'Semi-Annually' => ['month', 6],
    'Annually' => ['year', 1], 'Biennially' => ['year', 2], 'Triennially' => ['year', 3],
];
foreach ($cycles as $cycle => [$interval, $n]) {
    same("{$cycle} -> {$interval} x{$n}", [$interval, $n], [PriceResolver::cycleToInterval($cycle), PriceResolver::cycleToIntervalCount($cycle)]);
}
same('One Time is not recurring', null, PriceResolver::cycleToInterval('One Time'));

echo "Price body\n";
same('price body uses itemLocalCurrency fields',
    ['productId' => 'prod_1', 'itemLocalCurrencyAmount' => 49900, 'itemLocalCurrency' => 'INR', 'type' => 'recurring', 'interval' => 'month', 'intervalCount' => 3],
    PriceResolver::priceBody('prod_1', 49900, 'inr', 'month', 3));
check('no intervalCount for a single period', !array_key_exists('intervalCount', PriceResolver::priceBody('prod_1', 49900, 'INR', 'month', 1)));

echo "Refund amount (USD cents)\n";
$pay = ['transactionLocalCurrency' => 'INR', 'transactionLocalCurrencyAmount' => 102000, 'transactionAmountUsdCents' => 1071, 'transactionRefundedAmountUsdCents' => null];
same('full refund is the whole USD charge', 1071, RefundMapper::usdCents($pay, 102000, 'INR'));
same('partial refund is pro rata', 525, RefundMapper::usdCents($pay, 50000, 'inr'));
$partly = ['transactionRefundedAmountUsdCents' => 525] + $pay;
same('refunding the rest after a partial is the USD remainder', 546, RefundMapper::usdCents($partly, 52000, 'INR'));
throws('refunding the whole charge again after a partial refused', function () use ($partly) {
    RefundMapper::usdCents($partly, 102000, 'INR');
}, \RuntimeException::class);
throws('refund under one US cent refused', function () use ($pay) {
    RefundMapper::usdCents($pay, 10, 'INR');
}, \RuntimeException::class);
same('USD payment refunds the same cents', 700, RefundMapper::usdCents(['transactionLocalCurrency' => 'USD', 'transactionLocalCurrencyAmount' => 2000, 'transactionAmountUsdCents' => 2000], 700, 'USD'));
same('refund body sends transactionAmountUsdCents', ['transactionAmountUsdCents' => 525, 'reason' => 'r'], RefundMapper::body(525, 'r'));
throws('currency mismatch refused', function () use ($pay) {
    RefundMapper::usdCents($pay, 50000, 'USD');
}, \RuntimeException::class);
throws('refund above the charge refused', function () use ($pay) {
    RefundMapper::usdCents($pay, 102001, 'INR');
}, \RuntimeException::class);
throws('payment without transaction amounts refused', function () {
    RefundMapper::usdCents(['transactionLocalCurrency' => 'INR'], 100, 'INR');
}, \RuntimeException::class);
throws('fully refunded payment refused', function () use ($pay) {
    RefundMapper::usdCents(['transactionRefundedAmountUsdCents' => 1071] + $pay, 100, 'INR');
}, \RuntimeException::class);

echo "Customer body\n";
same('name, email, phone and country from the client',
    ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '+91.9876543210', 'country' => 'IN'],
    CheckoutBuilder::customerBody(['firstname' => 'Asha', 'lastname' => 'Rao', 'email' => ' asha@example.com ', 'phonenumber' => '+91.9876543210', 'country' => 'in']));
same('company name when the client has no personal name',
    ['name' => 'MR Innovations', 'email' => 'ops@example.com'],
    CheckoutBuilder::customerBody(['firstname' => '', 'lastname' => '', 'companyname' => 'MR Innovations', 'email' => 'ops@example.com']));
same('email as name when nothing else is known',
    ['name' => 'x@example.com', 'email' => 'x@example.com'],
    CheckoutBuilder::customerBody(['email' => 'x@example.com', 'phonenumber' => '  ']));
$threw = false;
try { CheckoutBuilder::customerBody(['firstname' => 'A']); } catch (\InvalidArgumentException $e) { $threw = true; }
same('client without email is refused', true, $threw);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
