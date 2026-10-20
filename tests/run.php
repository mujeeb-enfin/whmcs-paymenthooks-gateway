<?php
/**
 * Standalone tests for the pure, WHMCS-independent logic:
 *   - Signature verification (HMAC, replay window, rotation)
 *   - Minor-unit conversion (normal + zero-decimal currencies)
 *   - Billing-cycle -> PH interval / intervalCount mapping
 *   - Custom-domain normalisation and checkout-mode policy
 *
 * Run:  php tests/run.php
 * No WHMCS, no network. Exits non-zero on any failure.
 */

define('WHMCS', true); // let the guarded library files load

require __DIR__ . '/../modules/gateways/paymenthooks/lib/Signature.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/InvoiceMapper.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/PriceResolver.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/Config.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/Logger.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/SubscriptionSync.php';
require __DIR__ . '/../modules/gateways/paymenthooks/lib/WebhookRouter.php';

use PaymentHooks\Signature;
use PaymentHooks\InvoiceMapper;
use PaymentHooks\PriceResolver;
use PaymentHooks\Config;
use PaymentHooks\WebhookRouter;

$pass = 0;
$fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n        got:  " . var_export($got, true) . "\n        want: " . var_export($want, true) . "\n";
    }
}
function check_true(string $label, bool $got): void { check($label, $got, true); }
function check_false(string $label, bool $got): void { check($label, $got, false); }

/* ---- Signature ------------------------------------------------------- */
echo "Signature:\n";
$secret = 'whsec_test_123';
$body = '{"id":"evt_1","type":"checkout.session.completed"}';
$t = time();
$good = hash_hmac('sha256', $t . '.' . $body, $secret);
check_true('valid signature accepted',
    Signature::verify($body, "t={$t},v1={$good}", $secret));
check_false('tampered body rejected',
    Signature::verify($body . 'x', "t={$t},v1={$good}", $secret));
check_false('wrong secret rejected',
    Signature::verify($body, "t={$t},v1={$good}", 'other'));
$old = $t - 10000;
$oldSig = hash_hmac('sha256', $old . '.' . $body, $secret);
check_false('stale timestamp rejected (replay window)',
    Signature::verify($body, "t={$old},v1={$oldSig}", $secret));
check_true('rotation: previous-secret signature accepted via v1_previous',
    Signature::verify($body, "t={$t},v1=deadbeef,v1_previous={$good}", $secret));
check_false('empty secret rejected', Signature::verify($body, "t={$t},v1={$good}", ''));
check_false('missing t rejected', Signature::verify($body, "v1={$good}", $secret));

/* ---- Minor units ----------------------------------------------------- */
echo "InvoiceMapper minor units:\n";
check('INR 499.00 -> 49900', InvoiceMapper::toMinorUnits(499.00, 'INR'), 49900);
check('USD 29.90 -> 2990', InvoiceMapper::toMinorUnits(29.90, 'USD'), 2990);
check('JPY 500 -> 500 (zero-decimal)', InvoiceMapper::toMinorUnits(500.0, 'JPY'), 500);
check('rounding 10.005 -> 1001', InvoiceMapper::toMinorUnits(10.005, 'USD'), 1001);
check('back: 49900 INR -> 499.0', InvoiceMapper::toMajorUnits(49900, 'INR'), 499.0);
check('back: 500 JPY -> 500.0', InvoiceMapper::toMajorUnits(500, 'JPY'), 500.0);

/* ---- Cycle mapping --------------------------------------------------- */
echo "PriceResolver cycle mapping:\n";
check('monthly -> month', PriceResolver::cycleToInterval('Monthly'), 'month');
check('annually -> year', PriceResolver::cycleToInterval('Annually'), 'year');
check('quarterly -> month', PriceResolver::cycleToInterval('Quarterly'), 'month');
check('weekly -> week', PriceResolver::cycleToInterval('Weekly'), 'week');
check('one time -> null', PriceResolver::cycleToInterval('One Time'), null);
check('quarterly count 3', PriceResolver::cycleToIntervalCount('Quarterly'), 3);
check('semi-annually count 6', PriceResolver::cycleToIntervalCount('Semi-Annually'), 6);
check('biennially count 2', PriceResolver::cycleToIntervalCount('Biennially'), 2);
check('monthly count 1', PriceResolver::cycleToIntervalCount('Monthly'), 1);

/* ---- Domain normalisation ------------------------------------------- */
echo "Config host normalisation:\n";
check('blank -> default', Config::normaliseHost(''), Config::DEFAULT_HOST);
check('strip https + slash', Config::normaliseHost('https://payments.mr-innovations.com/'), 'payments.mr-innovations.com');
check('strip http + path', Config::normaliseHost('http://payments.mr-innovations.com/api/v1'), 'payments.mr-innovations.com');
check('strip port', Config::normaliseHost('payments.mr-innovations.com:8443'), 'payments.mr-innovations.com');
check('lowercase', Config::normaliseHost('Payments.MR-Innovations.COM'), 'payments.mr-innovations.com');

/* ---- Checkout-mode policy ------------------------------------------- */
echo "Config checkout-mode policy:\n";
function cfg(string $policy, bool $subsEnabled): Config
{
    return Config::fromParams([
        'mode'                => 'test',
        'paymentModePolicy'   => $policy,
        'enableSubscriptions' => $subsEnabled ? 'on' : '',
    ]);
}
check('auto + recurring + enabled -> subscription', cfg('auto', true)->resolveCheckoutMode(true), 'subscription');
check('auto + recurring + disabled -> payment', cfg('auto', false)->resolveCheckoutMode(true), 'payment');
check('auto + one-time -> payment', cfg('auto', true)->resolveCheckoutMode(false), 'payment');
check('force subscription + enabled -> subscription', cfg('subscription', true)->resolveCheckoutMode(false), 'subscription');
check('force subscription + disabled -> payment (safe fallback)', cfg('subscription', false)->resolveCheckoutMode(true), 'payment');
check('force payment + recurring -> payment', cfg('payment', true)->resolveCheckoutMode(true), 'payment');
check('unknown policy -> auto behaviour', cfg('garbage', true)->resolveCheckoutMode(true), 'subscription');

/* ---- Subscription id extraction ------------------------------------- */
echo "WebhookRouter subscription-id extraction:\n";
check('scalar string', WebhookRouter::scalarId('sub_1'), 'sub_1');
check('scalar int', WebhookRouter::scalarId(42), '42');
check('array -> empty', WebhookRouter::scalarId(['id' => 'x']), '');
check('null -> empty', WebhookRouter::scalarId(null), '');
check('camelCase field', WebhookRouter::extractSubscriptionId(['subscriptionId' => 'sub_a']), 'sub_a');
check('snake_case field', WebhookRouter::extractSubscriptionId(['subscription_id' => 'sub_b']), 'sub_b');
check('bare subscription string', WebhookRouter::extractSubscriptionId(['subscription' => 'sub_c']), 'sub_c');
check('nested subscription object', WebhookRouter::extractSubscriptionId(['subscription' => ['id' => 'sub_d']]), 'sub_d');
check('none present -> empty', WebhookRouter::extractSubscriptionId(['foo' => 'bar']), '');
check('precedence: camel over nested', WebhookRouter::extractSubscriptionId(['subscriptionId' => 'sub_x', 'subscription' => ['id' => 'sub_y']]), 'sub_x');

/* ---- Summary --------------------------------------------------------- */
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
