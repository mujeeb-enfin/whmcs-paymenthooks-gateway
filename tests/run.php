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
require_once $lib . 'SubscriptionMatcher.php';
require_once $lib . 'RenewalInvoice.php';
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

echo "One-time line items\n";
$tot = function (int $total, int $tax = 0, int $balance = -1, int $credit = 0): array {
    return ['totalMinor' => $total, 'taxMinor' => $tax, 'creditMinor' => $credit, 'balanceMinor' => $balance < 0 ? $total : $balance];
};
$unit = function (array $m): array { return array_column($m['lineItems'], 'unitPrice'); };
$hostLine = ['type' => 'Hosting', 'relId' => 77, 'description' => "Hosting\nStarter plan", 'amountMinor' => 80000, 'taxed' => true];
$domLine = ['type' => 'DomainRegister', 'relId' => 12, 'description' => 'Domain', 'amountMinor' => 20000, 'taxed' => true];
// No tax: 800 + 200 = 1,000.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(100000));
same('no tax: invoice lines sent as they are', [80000, 20000], $unit($m));
same('no tax: no fallback reason', '', $m['fallbackReason']);
same('multi-line description flattened', 'Hosting Starter plan', $m['lineItems'][0]['description']);
same('quantity 1 per WHMCS row', [1, 1], array_column($m['lineItems'], 'quantity'));
// 18% exclusive GST on both items: 1,000 + 180 = 1,180.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(118000, 18000));
same('18% exclusive tax: two lines, each with its own tax', [94400, 23600], $unit($m));
same('18% exclusive tax: lines add up to the total', 118000, array_sum($unit($m)));
same('18% exclusive tax: no fallback', '', $m['fallbackReason']);
$b = CheckoutBuilder::build(config(), 7, 'INR', $m + InvoiceMapper::taxInBalance(['total' => '1180.00', 'tax' => '180.00', 'tax2' => '0', 'taxrate' => '18', 'taxrate2' => '0'], 118000, 'INR'),
    'payment', 'https://billing.example.com', '', '');
same('18% exclusive tax: checkout sends both lines', ['Hosting Starter plan', 'Domain'], array_column($b['lineItems'], 'description'));
same('18% exclusive tax: tax sent as inclusive, inside the lines', [18000, 'inclusive', 18.0], [$b['itemTaxLocalCurrencyAmount'], $b['taxBehavior'], $b['taxRate']]);
check('fully unpaid invoice: no paid / credit metadata', !isset($b['metadata']['whmcs_already_paid']) && !isset($b['metadata']['whmcs_credit_applied']));
// Tax on one line only, odd amounts: remainder lands on the last taxed line.
$m = InvoiceMapper::oneTimeLines(7, [['description' => 'A', 'amountMinor' => 333, 'taxed' => true], ['description' => 'Untaxed', 'amountMinor' => 500, 'taxed' => false],
    ['description' => 'B', 'amountMinor' => 334, 'taxed' => true]], $tot(1267, 100));
same('mixed taxed / untaxed: tax only on taxed lines, remainder on the last', [382, 500, 385], $unit($m));
// Inclusive tax: lines already equal the total (tax inside).
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(100000, 15254));
same('inclusive tax: lines untouched', [80000, 20000], $unit($m));
// Single item.
$m = InvoiceMapper::oneTimeLines(7, [['description' => 'Domain Renewal - example.com', 'amountMinor' => 129900, 'taxed' => true]], $tot(153282, 23382));
same('single taxed item: one line at the gross price', [['description' => 'Domain Renewal - example.com', 'quantity' => 1, 'unitPrice' => 153282]], $m['lineItems']);
// Promotion folded into the item it discounts, then taxed.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, ['type' => 'PromoHosting', 'relId' => 77, 'description' => 'Promo: 25% off', 'amountMinor' => -20000, 'taxed' => true], $domLine],
    $tot(94400, 14400));
same('promo: folded into its service, then tax spread', [70800, 23600], $unit($m));
same('promo: named on the service line', 'Hosting Starter plan (Promo: 25% off)', $m['lineItems'][0]['description']);
$m = InvoiceMapper::oneTimeLines(7, [['type' => 'Hosting', 'relId' => 1, 'description' => 'Hosting', 'amountMinor' => 100000],
    ['type' => 'Item', 'relId' => 0, 'description' => 'Goodwill credit', 'amountMinor' => -20000]], $tot(80000));
same('negative line with no item: one balance line', [['description' => 'Invoice #7', 'quantity' => 1, 'unitPrice' => 80000]], $m['lineItems']);
check('negative line with no item: reason names the line', strpos($m['fallbackReason'], 'Goodwill credit') !== false, $m['fallbackReason']);
// Part paid: 1,180 invoice, 590 paid, 590 due -> each line at half, tax at half.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(118000, 18000, 59000));
same('part paid: lines scaled to the balance', [47200, 11800], $unit($m));
same('part paid: descriptions unchanged', ['Hosting Starter plan', 'Domain'], array_column($m['lineItems'], 'description'));
same('part paid: amount already paid reported', 59000, $m['paidMinor']);
$b = CheckoutBuilder::build(config(), 7, 'INR', $m, 'payment', 'https://billing.example.com', '', '');
same('part paid: already-paid amount in metadata', '59000', $b['metadata']['whmcs_already_paid']);
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(100000, 0, 33333));
same('part paid, uneven: remainder on the last line', [26666, 6667], $unit($m));
// Credit applied: WHMCS takes it off total. 1,180 invoice, 180 credit -> total 1,000, balance 1,000.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(100000, 18000, 100000, 18000));
same('credit applied: lines scaled to the balance', [80000, 20000], $unit($m));
same('credit applied: credit reported, nothing paid', [18000, 0], [$m['creditMinor'], $m['paidMinor']]);
$b = CheckoutBuilder::build(config(), 7, 'INR', $m, 'payment', 'https://billing.example.com', '', '');
same('credit applied: credit in metadata', '18000', $b['metadata']['whmcs_credit_applied']);
// Lines that cannot add up to the invoice total.
$m = InvoiceMapper::oneTimeLines(7, [$hostLine, $domLine], $tot(125000, 18000));
same('lines not adding up: one balance line', [125000], $unit($m));
check('lines not adding up: reason given', $m['fallbackReason'] !== '');
$m = InvoiceMapper::oneTimeLines(7, [], $tot(50000));
same('no lines: one balance line', ['Invoice #7'], array_column($m['lineItems'], 'description'));
same('subtotal is the balance', 50000, $m['subtotal_minor']);
same('zero-amount lines skipped', [100000], $unit(InvoiceMapper::oneTimeLines(7, [['description' => 'Free addon', 'amountMinor' => 0], ['description' => 'Hosting', 'amountMinor' => 100000]], $tot(100000))));
throws('nothing due fails', function () use ($tot) {
    InvoiceMapper::oneTimeLines(7, [], $tot(0));
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
$t = InvoiceMapper::taxInBalance(['total' => '1000.00', 'credit' => '180.00', 'tax' => '180.00', 'tax2' => '0', 'taxrate' => '18', 'taxrate2' => '0'], 100000, 'INR');
same('credit applied: tax share of total + credit (as the lines)', 15254, $t['tax_minor']);

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
     'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 100000], 'subscribed' => false,
     'periodStart' => date('Y-m-d'), 'lineDueDate' => date('Y-m-d')],
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

require __DIR__ . '/recurring-tests.php';

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

echo "Phone refusal\n";
same('a refusal naming only the phone is a phone refusal', true,
    CheckoutBuilder::isPhoneRefusal('VALIDATION_ERROR', ['error' => ['fields' => ['phone' => 'Enter a valid phone number for India.']]]));
same('a refusal naming other fields too is not', false,
    CheckoutBuilder::isPhoneRefusal('VALIDATION_ERROR', ['error' => ['fields' => ['phone' => 'x', 'email' => 'y']]]));
same('another error code is not', false, CheckoutBuilder::isPhoneRefusal('DUPLICATE_EMAIL', []));

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
