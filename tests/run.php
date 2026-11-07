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
require_once $lib . 'RecurringPlanner.php';
require_once $lib . 'InvoicePlan.php';
require_once $lib . 'PayButton.php';
require_once $lib . 'RefundSync.php';
require_once $lib . 'SubscriptionSync.php';

use PaymentHooks\CheckoutBuilder;
use PaymentHooks\RefundMapper;
use PaymentHooks\Client;
use PaymentHooks\Config;
use PaymentHooks\ConfigException;
use PaymentHooks\EventParser;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\InvoicePlan;
use PaymentHooks\PayButton;
use PaymentHooks\PriceResolver;
use PaymentHooks\RecurringPlanner;
use PaymentHooks\RefundSync;
use PaymentHooks\Signature;
use PaymentHooks\SubscriptionSync;

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
same('Pay Now is one-time', 'payment', config(['enableSubscriptions' => 'on'])->resolveCheckoutMode('payment'));
same('no mode posted (older template) is one-time', 'payment', config(['enableSubscriptions' => 'on'])->resolveCheckoutMode(''));
same('Subscribe & Auto-pay with subscriptions enabled', 'subscription', config(['enableSubscriptions' => 'on'])->resolveCheckoutMode('subscription'));
throws('Subscribe & Auto-pay with subscriptions off fails', function () {
    config()->resolveCheckoutMode('subscription');
}, ConfigException::class);
same('legacy "One-time only" turns the Subscribe button off', false, config(['enableSubscriptions' => 'on', 'paymentModePolicy' => 'payment'])->subscriptionsEnabled);
same('legacy "Auto" keeps Enable Subscriptions', true, config(['enableSubscriptions' => 'on', 'paymentModePolicy' => 'auto'])->subscriptionsEnabled);
same('legacy "Subscription only" keeps Enable Subscriptions', true, config(['enableSubscriptions' => 'on', 'paymentModePolicy' => 'subscription'])->subscriptionsEnabled);
same('legacy policy never turns subscriptions on', false, config(['paymentModePolicy' => 'subscription'])->subscriptionsEnabled);
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

echo "WHMCS tax inside the balance\n";
// 1000.00 + 18% GST = 1180.00 invoice; the tax already charged by WHMCS is sent as inclusive.
$t = InvoiceMapper::taxInBalance(['total' => '1180.00', 'tax' => '180.00', 'tax2' => '0.00', 'taxrate' => '18.00', 'taxrate2' => '0.00'], 118000, 'INR');
same('full balance carries all the tax', ['tax_minor' => 18000, 'tax_rate' => 18.0], $t);
$t = InvoiceMapper::taxInBalance(['total' => '1180.00', 'tax' => '180.00', 'tax2' => '0.00', 'taxrate' => '18', 'taxrate2' => '0'], 59000, 'INR');
same('half paid: half the tax is in the remaining balance', 9000, $t['tax_minor']);
$t = InvoiceMapper::taxInBalance(['total' => '1200.00', 'tax' => '100.00', 'tax2' => '100.00', 'taxrate' => '9', 'taxrate2' => '9'], 120000, 'INR');
same('tax + tax2 (CGST + SGST) combined', ['tax_minor' => 20000, 'tax_rate' => 18.0], $t);
$t = InvoiceMapper::taxInBalance(['total' => '1000.00', 'tax' => '0.00', 'tax2' => '0.00'], 100000, 'INR');
same('untaxed invoice sends no tax', ['tax_minor' => 0, 'tax_rate' => 0.0], $t);

echo "Checkout body\n";
$mapped = ['lineItems' => [['description' => 'Invoice #9', 'quantity' => 1, 'unitPrice' => 100000]], 'subtotal_minor' => 100000];
$b = CheckoutBuilder::build(config(['convenienceFeePercent' => '2']), 9, 'INR', $mapped, 'payment', 'https://billing.example.com/', 'ph_cus_1', 'c@example.com');
same('itemLocalCurrency upper case', 'INR', $b['itemLocalCurrency']);
check('no retired currency field', !array_key_exists('currency', $b));
same('line items carry unitPrice in minor units', 100000, $b['lineItems'][0]['unitPrice']);
same('line items always sent', 1, count($b['lineItems']));
same('fee is an integer of the subtotal', 2000, $b['itemConvenienceFeeLocalCurrencyAmount']);
same('invoice id in metadata', '9', $b['metadata']['whmcs_invoice_id']);
check('no webhookUrl (registered endpoint only)', !array_key_exists('webhookUrl', $b));
same('success URL', 'https://billing.example.com/viewinvoice.php?id=9&paymentsuccess=true', $b['successUrl']);
check('no priceId on one-time', !isset($b['metadata']['priceId']) && !array_key_exists('priceId', $b));
$subPlan = RecurringPlanner::plan([
    ['type' => 'Hosting', 'relType' => 'hosting', 'relId' => 77, 'description' => 'Starter', 'amountMinor' => 100000,
     'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 100000], 'subscribed' => false],
], 100000);
$b = CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'subscription', 'https://billing.example.com', '', '', $subPlan);
same('subscription sends the plan line with inline recurring', ['interval' => 'month', 'intervalCount' => 1], $b['lineItems'][0]['recurring']);
check('subscription sends no priceId anywhere', !array_key_exists('priceId', $b) && !isset($b['metadata']['priceId']));
same('subscribed item count in metadata', '1', $b['metadata']['whmcs_subscribed_items']);
check('no empty customer fields', !array_key_exists('customerId', $b) && !array_key_exists('customerEmail', $b));
check('no fee field without a fee', !array_key_exists('itemConvenienceFeeLocalCurrencyAmount', $b));
$b = CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'payment', 'https://billing.example.com', '', '');
check('one-time line items carry no recurring', !isset($b['lineItems'][0]['recurring']));
same('itemTaxLocalCurrencyAmount 0 so PaymentHooks auto-tax never taxes the WHMCS balance again', 0, $b['itemTaxLocalCurrencyAmount']);
same('tax is declared inclusive so auto-tax never adds to the WHMCS balance', 'inclusive', $b['taxBehavior']);
$taxed = $mapped + ['tax_minor' => 15254, 'tax_rate' => 18.0];
$b = CheckoutBuilder::build(config(), 9, 'INR', $taxed, 'payment', 'https://billing.example.com', '', '');
same('WHMCS tax sent as the tax inside the total', 15254, $b['itemTaxLocalCurrencyAmount']);
same('WHMCS rate sent for receipts and reports', 18.0, $b['taxRate']);
check('no tax name unless configured', !array_key_exists('taxName', $b));
$b = CheckoutBuilder::build(config(['taxName' => ' GST ']), 9, 'INR', $taxed, 'payment', 'https://billing.example.com', '', '');
same('configured tax name sent with the tax', 'GST', $b['taxName']);
$b = CheckoutBuilder::build(config(['taxName' => 'GST']), 9, 'INR', $mapped + ['tax_minor' => 0, 'tax_rate' => 0.0], 'payment', 'https://billing.example.com', '', '');
check('no tax name on an untaxed invoice', !array_key_exists('taxName', $b));
check('no retired taxAmount / convenienceFee fields', !array_key_exists('taxAmount', $b) && !array_key_exists('convenienceFee', $b));
$k1 = CheckoutBuilder::idempotencyKey('aaaa', 9, 100000, 'payment', 0);
$k2 = CheckoutBuilder::idempotencyKey('bbbb', 9, 100000, 'payment', 0);
check('idempotency key differs per install', $k1 !== $k2);
check('idempotency key fits the 200 limit', strlen($k1) <= 200 && Client::idempotencyKey($k1) === $k1);
throws('subscription without a plan fails', function () use ($mapped) {
    CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'subscription', 'https://b.example.com', '', '');
}, \InvalidArgumentException::class);
throws('subscription with a plan that offers nothing fails', function () use ($mapped) {
    CheckoutBuilder::build(config(), 9, 'INR', $mapped, 'subscription', 'https://b.example.com', '', '', RecurringPlanner::plan([], 100000));
}, \InvalidArgumentException::class);
throws('missing currency fails', function () use ($mapped) {
    CheckoutBuilder::build(config(), 9, '', $mapped, 'payment', 'https://b.example.com', '', '');
}, \InvalidArgumentException::class);

echo "Recurring line items accepted by PaymentHooks\n";
$sent = [['description' => 'Starter', 'quantity' => 1, 'unitPrice' => 100000, 'recurring' => ['interval' => 'month', 'intervalCount' => 1]],
         ['description' => 'Setup', 'quantity' => 1, 'unitPrice' => 5000]];
$echo = ['mode' => 'subscription', 'lineItems' => [
    ['description' => 'Starter', 'unitPrice' => 100000, 'recurring' => ['interval' => 'month', 'intervalCount' => 1]],
    ['description' => 'Setup', 'unitPrice' => 5000],
]];
check('echoed recurring accepted', CheckoutBuilder::recurringAccepted($sent, $echo));
$dropped = $echo;
unset($dropped['lineItems'][0]['recurring']);
check('API that drops recurring is refused', !CheckoutBuilder::recurringAccepted($sent, $dropped));
$other = $echo;
$other['lineItems'][0]['recurring'] = ['interval' => 'year', 'intervalCount' => 1];
check('API that changes the cadence is refused', !CheckoutBuilder::recurringAccepted($sent, $other));
check('session not in subscription mode is refused', !CheckoutBuilder::recurringAccepted($sent, ['mode' => 'payment'] + $echo));
check('session without echoed line items is refused', !CheckoutBuilder::recurringAccepted($sent, ['mode' => 'subscription']));

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
$f = fixture('subscription_renewed');
$f['data']['object']['failure_reason'] = 'Card declined';
same('renewal failure reason read', 'Card declined', EventParser::subscription($f)['failureReason']);
$paid = ['id' => 'evt_inv', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'ph_inv_9', 'object' => 'invoice', 'subscription' => 'ph_sub_whmcs_1',
    'payment_intent' => 'ph_pay_whmcs_3', 'amount_paid' => 49900, 'currency' => 'inr', 'billing_reason' => 'subscription_cycle']]];
same('invoice.paid fields', ['invoiceId' => 'ph_inv_9', 'subscriptionId' => 'ph_sub_whmcs_1', 'paymentId' => 'ph_pay_whmcs_3', 'amountMinor' => 49900, 'currency' => 'INR', 'billingReason' => 'subscription_cycle'],
    EventParser::invoicePaid($paid));
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

echo "Subscribe & Auto-pay plan (mixed invoice)\n";
// Hosting monthly 1000.00 + its 150.00 setup fee, domain yearly 800.00, addon monthly 200.00.
$hosting = ['type' => 'Hosting', 'relType' => 'hosting', 'relId' => 77, 'description' => 'Starter Hosting (example.com)', 'amountMinor' => 100000,
            'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 100000], 'subscribed' => false];
$setup   = ['type' => 'Setup', 'relType' => '', 'relId' => 77, 'description' => 'Setup Fee', 'amountMinor' => 15000, 'recurring' => null, 'subscribed' => false];
$domain  = ['type' => 'DomainRegister', 'relType' => 'domain', 'relId' => 12, 'description' => 'Domain Registration - example.com - 1 Year/s', 'amountMinor' => 80000,
            'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 80000], 'subscribed' => false];
$addon   = ['type' => 'Addon', 'relType' => 'addon', 'relId' => 5, 'description' => 'Backups', 'amountMinor' => 20000,
            'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 20000], 'subscribed' => false];
$p = RecurringPlanner::plan([$hosting, $setup, $domain, $addon], 215000);
same('mixed: offered', true, $p['offer']);
same('mixed: cadence anchored on the hosting service', ['month', 1], [$p['interval'], $p['intervalCount']]);
same('mixed: hosting and addon subscribed', [['hosting', 77], ['addon', 5]], array_map(function ($i) { return [$i['relType'], $i['relId']]; }, $p['subscribed']));
same('mixed: renews at hosting + addon', 120000, $p['renewalMinor']);
same('mixed: lines add up to the balance', 215000, array_sum(array_column($p['lineItems'], 'unitPrice')));
same('mixed: recurring lines', 2, count(array_filter($p['lineItems'], function ($l) { return isset($l['recurring']); })));
same('mixed: setup fee is one-time', 15000, $p['lineItems'][1]['unitPrice']);
check('mixed: setup fee has no recurring', !isset($p['lineItems'][1]['recurring']));
check('mixed: yearly domain charged once with the reason', strpos($p['oneTime'][1]['note'] ?? '', 'every year') !== false, json_encode($p['oneTime']));
$p = RecurringPlanner::plan([$domain], 80000);
same('domain only: subscribed yearly', ['year', 1, 'domain'], [$p['interval'], $p['intervalCount'], $p['subscribed'][0]['relType'] ?? '']);
$twoYear = ['recurring' => ['interval' => 'year', 'intervalCount' => 2, 'renewalMinor' => 160000], 'amountMinor' => 160000] + $domain;
$p = RecurringPlanner::plan([$twoYear], 160000);
same('2-year domain renews every 2 years', ['year', 2], [$p['interval'], $p['intervalCount']]);
$p = RecurringPlanner::plan([['recurring' => ['interval' => 'month', 'intervalCount' => 12, 'renewalMinor' => 80000]] + $domain,
    ['recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 20000]] + $addon], 100000);
same('12 months and 1 year share one subscription', 2, count($p['subscribed']));
$promo = ['amountMinor' => 50000] + $hosting;
$p = RecurringPlanner::plan([$promo, $addon], 70000);
same('discounted first month: hosting not subscribed, addon is', [['addon', 5]], array_map(function ($i) { return [$i['relType'], $i['relId']]; }, $p['subscribed']));
check('discounted first month: reason shown', strpos($p['oneTime'][0]['note'], 'discounted') !== false);
$premium = ['amountMinor' => 120000] + $hosting;
$p = RecurringPlanner::plan([$premium], 120000);
same('first period above renewal: renewal + one-time difference', [100000, 20000], array_column($p['lineItems'], 'unitPrice'));
$p = RecurringPlanner::plan([$hosting, $setup], 100000);
same('part-paid invoice: not offered', false, $p['offer']);
check('part-paid invoice: says why', strpos($p['reason'], 'balance') !== false);
same('part-paid invoice: no line items', [], $p['lineItems']);
$p = RecurringPlanner::plan([['subscribed' => true] + $hosting], 100000);
same('already subscribed: not offered', false, $p['offer']);
$p = RecurringPlanner::plan([$setup], 15000);
same('no recurring item: not offered', false, $p['offer']);
$p = RecurringPlanner::plan([$hosting, ['type' => 'PromoHosting', 'relType' => '', 'relId' => 77, 'description' => 'Promo', 'amountMinor' => -10000, 'recurring' => null, 'subscribed' => false]], 90000);
same('negative promo line: not offered', false, $p['offer']);
same('cadence labels', ['every month', 'every 3 months', 'every 2 years'],
    [RecurringPlanner::cadenceLabel('month', 1), RecurringPlanner::cadenceLabel('month', 3), RecurringPlanner::cadenceLabel('year', 2)]);

echo "WHMCS exclusive tax spread over the lines\n";
$rows = [['amountMinor' => 100000, 'taxed' => true, 'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 100000]],
         ['amountMinor' => 15000, 'taxed' => true, 'recurring' => null],
         ['amountMinor' => 80000, 'taxed' => false, 'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 80000]]];
$t = InvoicePlan::allocateTax($rows, 20700, 215700);
same('tax lands on taxed lines only', [118000, 17700, 80000], array_column($t, 'amountMinor'));
same('renewal price carries its tax', 118000, $t[0]['recurring']['renewalMinor']);
same('lines then equal the total', 215700, array_sum(array_column($t, 'amountMinor')));
same('inclusive tax (lines already equal the total) untouched', $rows, InvoicePlan::allocateTax($rows, 20700, 195000));
$odd = InvoicePlan::allocateTax([['amountMinor' => 333, 'taxed' => true, 'recurring' => null], ['amountMinor' => 333, 'taxed' => true, 'recurring' => null], ['amountMinor' => 334, 'taxed' => true, 'recurring' => null]], 100, 1100);
same('rounding remainder on the last taxed line', 1100, array_sum(array_column($odd, 'amountMinor')));
same('invoice-item types per subscribed item', [['Hosting'], ['Addon'], ['Domain', 'DomainRegister', 'DomainTransfer']],
    [InvoicePlan::itemTypes('hosting'), InvoicePlan::itemTypes('addon'), InvoicePlan::itemTypes('domain')]);

echo "Pay buttons\n";
$tpl = (string) file_get_contents(__DIR__ . '/../modules/gateways/paymenthooks/templates/pay_button.tpl');
$view = ['actionUrl' => 'https://billing.example.com/modules/gateways/callback/paymenthooks.php', 'invoiceId' => 1001, 'payText' => 'Pay Now', 'currency' => 'INR'];
$html = PayButton::render($tpl, $view);
check('one button without a plan', substr_count($html, 'type="submit"') === 1 && strpos($html, 'paymenthooks_pay_submit') !== false);
check('Pay Now posts mode=payment', strpos($html, 'name="mode" value="payment"') !== false);
check('no Subscribe button without a plan', strpos($html, 'paymenthooks_pay_subscribe') === false);
check('no placeholder left', strpos($html, '{') === false, $html);
$html = PayButton::render($tpl, $view + ['plan' => RecurringPlanner::plan([$setup], 15000)]);
check('no Subscribe button when nothing can renew', strpos($html, 'paymenthooks_pay_subscribe') === false);
$mixed = RecurringPlanner::plan([$hosting, $setup, $domain, $addon], 215000);
$html = PayButton::render($tpl, $view + ['plan' => $mixed]);
check('two buttons with a plan', substr_count($html, 'type="submit"') === 2);
check('Subscribe posts mode=subscription', strpos($html, 'id="paymenthooks_pay_subscribe"') !== false && strpos($html, 'name="mode" value="subscription"') !== false);
check('default Subscribe label', strpos($html, '>Subscribe &amp; Auto-pay</button>') !== false);
check('Subscribe button described by the summary', strpos($html, 'aria-describedby="paymenthooks_pay_subscribe_summary"') !== false
    && strpos($html, 'id="paymenthooks_pay_subscribe_summary"') !== false);
check('summary states cadence and renewal total', strpos($html, 'every month for 1,200.00 INR') !== false, $html);
check('summary lists the subscribed items', strpos($html, 'paymenthooks_pay_subscribe_item_1') !== false && strpos($html, 'Backups (200.00 INR)') !== false);
check('summary lists the yearly domain as charged once, with why', strpos($html, 'paymenthooks_pay_subscribe_once_item_') !== false && strpos($html, 'Domain Registration') !== false);
$html = PayButton::render($tpl, $view + ['plan' => $mixed, 'subscribeText' => '<b>x</b>', 'heading' => 'Pay "now"']);
check('labels and heading escaped', strpos($html, '<b>x</b>') === false && strpos($html, '&lt;b&gt;x&lt;/b&gt;') !== false && strpos($html, 'Pay &quot;now&quot;') !== false);
preg_match_all('/<(div|ul|li|button|form|input|img)\b(?![^>]*\bid=)[^>]*>/', $html, $noId);
same('every element has a paymenthooks_ id', [], $noId[0]);
same('money formatting', ['1,200.00 INR', '1,500 JPY'], [PayButton::money(120000, 'INR'), PayButton::money(1500, 'JPY')]);

echo "Renewal split and refunds from PaymentHooks\n";
same('charge split over renewal invoices oldest first', [2001 => 100000, 2002 => 20000],
    SubscriptionSync::split(120000, [['id' => 2001, 'balanceMinor' => 100000], ['id' => 2002, 'balanceMinor' => 20000]]));
same('overpayment goes to the last invoice', [2001 => 100000, 2002 => 30000],
    SubscriptionSync::split(130000, [['id' => 2001, 'balanceMinor' => 100000], ['id' => 2002, 'balanceMinor' => 20000]]));
same('short charge fills the oldest first', [2001 => 90000, 2002 => 0],
    SubscriptionSync::split(90000, [['id' => 2001, 'balanceMinor' => 100000], ['id' => 2002, 'balanceMinor' => 20000]]));
same('dashboard refund: whole cumulative amount is new', 50000, RefundSync::newRefundMinor(50000, 0, 0));
same('refund issued from WHMCS is not booked again', 0, RefundSync::newRefundMinor(50000, 50000, 0));
same('second dashboard refund books only the increase', 20000, RefundSync::newRefundMinor(70000, 50000, 50000));
same('refund entered by hand in WHMCS is not booked again', 0, RefundSync::newRefundMinor(50000, 0, 50000));

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
