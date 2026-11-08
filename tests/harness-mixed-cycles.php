<?php
/**
 * Subscribe & Auto-pay end to end in the harness (included by
 * tests/webhook-harness.php, which defines the Capsule stand-in, deliver(),
 * ok(), rows() and the WHMCS function stubs):
 *   invoice block (paymenthooks_link): offer, summary, the reason note, logs
 *   checkout click: the request sent, the platform-contract check
 *   checkout.session.completed: each subscription bound to its own items
 *   renewals: each subscription paid onto its own items' invoices
 * PaymentHooks is HarnessClient: it answers like the API and records requests.
 */

namespace {
    use WHMCS\Database\Capsule;

    class App
    {
        public static function getSystemURL(): string { return 'https://billing.example.com/'; }
    }

    /** Records the redirect; the following `exit` was rewritten to `return`. */
    function harness_header(string $h): void { $GLOBALS['redirect'] = substr($h, strlen('Location: ')); }

    /** PaymentHooks API stand-in. $GLOBALS['platform'] decides what a created session echoes. */
    class HarnessClient extends \PaymentHooks\Client
    {
        public function get(string $path, array $query = []): array { return ['data' => []]; }
        public function put(string $path, array $body): array { return ['data' => []]; }
        public function post(string $path, array $body, ?string $idempotencyKey = null): array
        {
            if ($path === '/customers') {
                return ['data' => ['id' => 'ph_cus_5']];
            }
            $GLOBALS['sentBody'] = $body;
            return ['data' => ($GLOBALS['platform'])($body)];
        }
    }

    /** Full support: keeps recurring incl. startsAt (as an ISO date-time) and lists one subscription per group. */
    function platform_full(array $body): array
    {
        $lines = $body['lineItems'];
        $subs = [];
        foreach ($lines as $i => $l) {
            if (isset($l['recurring']['startsAt'])) {
                $lines[$i]['recurring']['startsAt'] .= 'T00:00:00.000Z';
            }
            if (isset($l['recurring'])) {
                $subs[$l['recurring']['interval'] . ($l['recurring']['startsAt'] ?? '')] = ['id' => null, 'interval' => $l['recurring']['interval']];
            }
        }
        return ['id' => 'cs_mix_' . count(rows('mod_paymenthooks_sessions')), 'url' => 'https://paymenthooks.com/checkout/cs_mix', 'mode' => $body['mode'],
            'lineItems' => $lines, 'subscriptions' => array_values($subs)];
    }

    function click(int $invoiceId, string $mode): string
    {
        global $logger;
        $config = \PaymentHooks\Config::fromParams(['secretKey' => 'phk_live_' . str_repeat('a', 64), 'webhookSecret' => $GLOBALS['secret'], 'enableSubscriptions' => 'on']);
        $_SESSION = ['uid' => 5];
        $_POST = ['action' => 'checkout', 'invoice_id' => (string) $invoiceId, 'mode' => $mode];
        $GLOBALS['redirect'] = '';
        $GLOBALS['sentBody'] = null;
        handle_checkout_click($config, $logger, ['systemurl' => 'https://billing.example.com/']);
        return $GLOBALS['redirect'];
    }

    function link_html(int $invoiceId, bool $subscriptions): string
    {
        return paymenthooks_link([
            'secretKey' => 'phk_live_' . str_repeat('a', 64), 'webhookSecret' => 'whsec_' . str_repeat('c', 32),
            'enableSubscriptions' => $subscriptions ? 'on' : '', 'systemurl' => 'https://billing.example.com/',
            'invoiceid' => $invoiceId, 'currency' => 'INR', 'langpaynow' => 'Pay Now',
        ]);
    }

    function note_of(string $html): ?string
    {
        return preg_match('#id="paymenthooks_pay_subscribe_unavailable"[^>]*>(.*?)</div>#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    function activity_with(string $needle): array
    {
        return array_values(array_filter($GLOBALS['activity'], function ($a) use ($needle) { return strpos($a, $needle) !== false; }));
    }

    function renewal(string $eventId, string $subId, string $payId, int $amount): int
    {
        $ev = json_decode(fx('subscription_renewed'), true);
        $ev['id'] = $eventId;
        $ev['data']['object']['id'] = $subId;
        $ev['data']['object']['latest_payment'] = ['id' => $payId, 'amount' => $amount, 'currency' => 'inr'];
        $GLOBALS['payments'] = [];
        return deliver(json_encode($ev), $GLOBALS['secret']);
    }

    require_once __DIR__ . '/../modules/gateways/paymenthooks.php';

    $GLOBALS['secret'] = $secret;
    $today = date('Y-m-d');
    $nextYear = \PaymentHooks\RecurringPlanner::addCycle($today, 'year', 1);
    $nextMonth = \PaymentHooks\RecurringPlanner::addCycle($today, 'month', 1);

    function reset_mixed(): void
    {
        reset_db();
        $today = date('Y-m-d');
        $t = &Capsule::$tables;
        $t['tblpaymentgateways'] = [];
        $t['mod_paymenthooks_install'] = [];
        $t['mod_paymenthooks_sessions'] = [];
        $t['tblclients'] = [['id' => 5, 'currency' => 1, 'email' => 'asha@example.com', 'firstname' => 'Asha', 'lastname' => 'Rao']];
        $t['tblhosting'] = [['id' => 77, 'billingcycle' => 'Monthly', 'amount' => 1000.00, 'subscriptionid' => '', 'nextduedate' => $today],
            ['id' => 78, 'billingcycle' => 'Monthly', 'amount' => 1000.00, 'subscriptionid' => '', 'nextduedate' => $today]];
        $t['tbldomains'] = [
            ['id' => 12, 'registrationperiod' => 1, 'recurringamount' => 800.00, 'subscriptionid' => '', 'nextduedate' => $today],
            ['id' => 31, 'registrationperiod' => 1, 'recurringamount' => 1299.00, 'subscriptionid' => '', 'nextduedate' => $today],
            ['id' => 40, 'registrationperiod' => 1, 'recurringamount' => 0.00, 'subscriptionid' => '', 'nextduedate' => $today],
        ];
        $t['tblhostingaddons'] = [['id' => 5, 'billingcycle' => 'Monthly', 'recurring' => 200.00, 'subscriptionid' => '', 'nextduedate' => $today]];
        $t['tblinvoices'] = [
            // Monthly hosting + setup fee + first-year-discounted domain + monthly addon.
            ['id' => 4001, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1850.00, 'tax' => 0],
            // The live case: 1,144.28 + 18% GST.
            ['id' => 4100, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1350.25, 'tax' => 205.97],
            // Part-paid (150.00 paid), credit applied (150.00), free renewal.
            ['id' => 4101, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1150.00, 'tax' => 0, 'paid' => 150.00],
            ['id' => 4102, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1000.00, 'tax' => 0, 'credit' => 150.00],
            ['id' => 4103, 'userid' => 5, 'status' => 'Unpaid', 'total' => 300.00, 'tax' => 0],
        ];
        $line = function (int $id, int $inv, string $type, int $rel, string $desc, float $amount, bool $due = true, bool $taxed = false) use ($today) {
            return ['id' => $id, 'invoiceid' => $inv, 'type' => $type, 'relid' => $rel, 'description' => $desc, 'amount' => $amount,
                'taxed' => $taxed ? 1 : 0, 'duedate' => $due ? $today : '0000-00-00'];
        };
        $t['tblinvoiceitems'] = [
            $line(1, 4001, 'Hosting', 77, 'Starter Hosting (example.com)', 1000.00),
            $line(2, 4001, 'Setup', 77, 'Setup Fee', 150.00, false),
            $line(3, 4001, 'DomainRegister', 12, 'Domain Registration - example.com - 1 Year/s', 500.00),
            $line(4, 4001, 'Addon', 5, 'Backups', 200.00),
            $line(5, 4100, 'DomainRegister', 31, 'Domain Registration - taptipm.com - 1 Year/s + DNS Management + Email Forwarding', 1144.28, true, true),
            $line(6, 4101, 'Hosting', 78, 'Starter Hosting', 1000.00),
            $line(7, 4101, 'Setup', 78, 'Setup Fee', 150.00, false),
            $line(8, 4102, 'Hosting', 78, 'Starter Hosting', 1000.00),
            $line(9, 4102, 'Setup', 78, 'Setup Fee', 150.00, false),
            $line(10, 4103, 'DomainRegister', 40, 'Domain Registration - free.example - 1 Year/s', 300.00),
        ];
        $GLOBALS['platform'] = 'platform_full';
        $GLOBALS['activity'] = [];
    }

    echo "Invoice block: Subscribe offered, or why not\n";
    reset_mixed();
    $html = link_html(4100, true);
    ok('live case (discounted first-year domain): Subscribe & Auto-pay shown', strpos($html, 'id="paymenthooks_pay_subscribe"') !== false, $html);
    ok('live case: "1,350.25 now, then 1,532.82 every year from <next year>"', strpos($html, '₹1,350.25</strong> now') !== false
        && strpos($html, '₹1,532.82 every year from ' . \PaymentHooks\RecurringPlanner::formatDate($nextYear)) !== false, $html);
    ok('live case: no unavailable note, nothing logged', note_of($html) === null && !activity_with('subscribe-'));
    $html = link_html(4100, false);
    ok('Enable Subscriptions off: Pay Now only, no note, nothing logged', strpos($html, 'paymenthooks_pay_subscribe') === false && !activity_with('subscribe-'));
    $html = link_html(4101, true);
    ok('part-paid: note says why', note_of($html) === \PaymentHooks\RecurringPlanner::REASONS['part_paid'], (string) note_of($html));
    link_html(4101, true);
    ok('part-paid: logged once per invoice view at info level', count(activity_with('PaymentHooks info [subscribe-unavailable]: Subscribe & Auto-pay not offered on invoice 4101')) === 1
        && strpos(activity_with('invoice 4101')[0], '"reason":"part_paid"') !== false, json_encode($GLOBALS['activity']));
    ok('credit applied: note says why', note_of(link_html(4102, true)) === \PaymentHooks\RecurringPlanner::REASONS['credit_applied']);
    ok('free renewal: note says why', note_of(link_html(4103, true)) === \PaymentHooks\RecurringPlanner::REASONS['free_renewal']);
    $html = link_html(9999, true);
    ok('plan threw: "Auto-pay is unavailable for this invoice right now"', strpos((string) note_of($html), 'Auto-pay is unavailable for this invoice right now') === 0, $html);
    ok('plan threw: error logged (subscribe-plan)', count(activity_with('PaymentHooks error [subscribe-plan]: RuntimeException: Invoice 9999 does not exist.')) === 1);
    ok('plan threw: Pay Now still there', strpos($html, 'id="paymenthooks_pay_submit"') !== false);

    echo "One checkout for monthly hosting + yearly domain + addon + setup fee\n";
    reset_mixed();
    $to = click(4001, 'subscription');
    $sent = $GLOBALS['sentBody']['lineItems'] ?? [];
    ok('client sent to the PaymentHooks checkout', $to === 'https://paymenthooks.com/checkout/cs_mix', $to . ' ' . json_encode(lastLog()));
    ok('request: hosting and addon monthly from now, domain yearly from its renewal date',
        array_map(function ($l) { return $l['recurring'] ?? null; }, $sent) === [
            ['interval' => 'month', 'intervalCount' => 1], null, ['interval' => 'year', 'intervalCount' => 1, 'startsAt' => $nextYear], null, ['interval' => 'month', 'intervalCount' => 1]],
        json_encode($sent));
    ok('request: discounted domain year charged once at 500.00, setup fee once', array_column($sent, 'unitPrice') === [100000, 15000, 80000, 50000, 20000], json_encode($sent));
    ok('request: lines charged now add up to the balance', 185000 === 100000 + 15000 + 50000 + 20000 && ($GLOBALS['sentBody']['metadata']['whmcs_subscriptions'] ?? '') === '2');
    $session = rows('mod_paymenthooks_sessions')[0] ?? [];
    $plan = json_decode((string) ($session['plan_json'] ?? ''), true);
    ok('session stores both groups', count($plan['groups'] ?? []) === 2 && $plan['groups'][1]['startsAt'] === $nextYear, (string) ($session['plan_json'] ?? ''));

    $ev = json_decode(fx('checkout_session_completed_subscription'), true);
    $ev['id'] = 'evt_mix_completed';
    $o = &$ev['data']['object'];
    $o['id'] = $session['session_id'];
    $o['amount_total'] = 185000;
    $o['payment_intent'] = 'ph_pay_mix_1';
    $o['subscription'] = 'ph_sub_month';
    $o['metadata'] = ['whmcs_invoice_id' => '4001'];
    $o['subscriptions'] = [
        ['id' => 'ph_sub_year', 'interval' => 'year', 'interval_count' => 1, 'starts_at' => $nextYear],
        ['id' => 'ph_sub_month', 'interval' => 'month', 'interval_count' => 1, 'starts_at' => null],
    ];
    unset($o);
    $completed = json_encode($ev);
    $st = deliver($completed, $secret);
    $bound = array_map(function ($r) { return [$r['subscription_id'], $r['rel_type'], $r['rel_id']]; }, rows('mod_paymenthooks_subscriptions'));
    ok('completed: payment applied once to the invoice', $st === 200 && count($GLOBALS['payments']) === 1 && $GLOBALS['payments'][0]['amount'] == 1850.0, json_encode(lastLog()));
    ok('completed: hosting + addon bound to the monthly subscription, domain to the yearly one',
        $bound === [['ph_sub_month', 'hosting', 77], ['ph_sub_month', 'addon', 5], ['ph_sub_year', 'domain', 12]], json_encode($bound));
    ok('completed: ids mirrored on each item', rows('tblhosting')[0]['subscriptionid'] === 'ph_sub_month' && rows('tbldomains')[0]['subscriptionid'] === 'ph_sub_year'
        && rows('tblhostingaddons')[0]['subscriptionid'] === 'ph_sub_month');
    ok('completed: Gateway Log names both subscriptions', (lastLog()['status'] ?? '') === 'Successful' && strpos(lastLog()['data']['result'] ?? '', 'ph_sub_year (every year from ' . $nextYear) !== false, json_encode(lastLog()));

    echo "Renewals routed per subscription\n";
    $t = &Capsule::$tables;
    $t['tblhosting'][0]['nextduedate'] = $nextMonth;
    $t['tblhostingaddons'][0]['nextduedate'] = $nextMonth;
    $t['tbldomains'][0]['nextduedate'] = $nextYear;
    $t['tblinvoices'][] = ['id' => 5001, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1200.00];
    $t['tblinvoices'][] = ['id' => 5002, 'userid' => 5, 'status' => 'Unpaid', 'total' => 800.00];
    $t['tblinvoiceitems'][] = ['id' => 20, 'invoiceid' => 5001, 'type' => 'Hosting', 'relid' => 77, 'amount' => 1000.00];
    $t['tblinvoiceitems'][] = ['id' => 21, 'invoiceid' => 5001, 'type' => 'Addon', 'relid' => 5, 'amount' => 200.00];
    $t['tblinvoiceitems'][] = ['id' => 22, 'invoiceid' => 5002, 'type' => 'Domain', 'relid' => 12, 'amount' => 800.00];
    $st = renewal('evt_mix_renew_m', 'ph_sub_month', 'ph_pay_mix_m1', 120000);
    ok('monthly renewal paid onto the hosting renewal invoice only', $st === 200 && count($GLOBALS['payments']) === 1 && $GLOBALS['payments'][0]['invoiceId'] === 5001
        && $GLOBALS['payments'][0]['amount'] == 1200.0 && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode([$GLOBALS['payments'], lastLog()]));
    $st = renewal('evt_mix_renew_y', 'ph_sub_year', 'ph_pay_mix_y1', 80000);
    ok('yearly renewal paid onto the domain renewal invoice only', $st === 200 && count($GLOBALS['payments']) === 1 && $GLOBALS['payments'][0]['invoiceId'] === 5002
        && $GLOBALS['payments'][0]['amount'] == 800.0 && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode([$GLOBALS['payments'], lastLog()]));

    // WHMCS put the hosting, addon and domain on one renewal invoice (same due date), 18% tax on top.
    $t['tblinvoices'][] = ['id' => 5003, 'userid' => 5, 'status' => 'Unpaid', 'total' => 2360.00];
    $t['tblinvoiceitems'][] = ['id' => 23, 'invoiceid' => 5003, 'type' => 'Hosting', 'relid' => 77, 'amount' => 1000.00];
    $t['tblinvoiceitems'][] = ['id' => 24, 'invoiceid' => 5003, 'type' => 'Addon', 'relid' => 5, 'amount' => 200.00];
    $t['tblinvoiceitems'][] = ['id' => 25, 'invoiceid' => 5003, 'type' => 'Domain', 'relid' => 12, 'amount' => 800.00];
    $st = renewal('evt_mix_renew_m2', 'ph_sub_month', 'ph_pay_mix_m2', 141600);
    ok('combined renewal invoice: monthly subscription pays its own share (1,416.00), not flagged', $st === 200 && $GLOBALS['payments'][0]['invoiceId'] === 5003
        && $GLOBALS['payments'][0]['amount'] == 1416.0 && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode([$GLOBALS['payments'], lastLog()]));
    $st = renewal('evt_mix_renew_y2', 'ph_sub_year', 'ph_pay_mix_y2', 94400);
    $inv = array_values(array_filter(rows('tblinvoices'), function ($r) { return $r['id'] === 5003; }))[0];
    ok('combined renewal invoice: yearly subscription pays the domain share and the invoice is Paid', $st === 200 && $GLOBALS['payments'][0]['amount'] == 944.0
        && $inv['status'] === 'Paid' && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode([$GLOBALS['payments'], lastLog()]));

    echo "Completed event without a subscriptions list (two groups)\n";
    reset_mixed();
    click(4001, 'subscription');
    $ev = json_decode($completed, true);
    $ev['id'] = 'evt_mix_no_list';
    $ev['data']['object']['id'] = rows('mod_paymenthooks_sessions')[0]['session_id'];
    unset($ev['data']['object']['subscriptions']);
    $st = deliver(json_encode($ev), $secret);
    ok('payment applied, nothing guessed: Needs Attention, no item bound', $st === 200 && count($GLOBALS['payments']) === 1 && !rows('mod_paymenthooks_subscriptions')
        && (lastLog()['status'] ?? '') === 'Needs Attention' && activity_with('webhook-subscriptions-unmatched'), json_encode(lastLog()));

    echo "PaymentHooks API without startsAt or several subscriptions\n";
    reset_mixed();
    $GLOBALS['platform'] = function (array $body): array {
        $r = platform_full($body);
        foreach ($r['lineItems'] as $i => $l) {
            if (isset($l['recurring'])) {
                unset($r['lineItems'][$i]['recurring']['startsAt']);
            }
        }
        return $r;
    };
    $to = click(4100, 'subscription');
    ok('startsAt dropped: Subscribe refused, client back on the invoice', strpos($to, 'viewinvoice.php?id=4100&paymentfailed=true') !== false, $to);
    ok('startsAt dropped: Gateway Log Checkout Error says why', (lastLog()['status'] ?? '') === 'Checkout Error'
        && strpos(lastLog()['data']['error'] ?? '', 'recurring start dates unsupported') !== false, json_encode(lastLog()));
    ok('startsAt dropped: error in the Activity Log, no session stored', activity_with('PaymentHooks error [checkout-click]') && !rows('mod_paymenthooks_sessions'));
    $to = click(4100, 'payment');
    ok('startsAt dropped: Pay Now still works', $to === 'https://paymenthooks.com/checkout/cs_mix' && !isset($GLOBALS['sentBody']['lineItems'][0]['recurring']), $to);
    $GLOBALS['platform'] = function (array $body): array {
        $r = platform_full($body);
        unset($r['subscriptions']);
        return $r;
    };
    $to = click(4001, 'subscription');
    ok('no subscriptions list for two groups: Subscribe refused with why', strpos($to, 'paymentfailed=true') !== false
        && strpos(lastLog()['data']['error'] ?? '', 'several subscriptions in one checkout unsupported') !== false, json_encode(lastLog()));
    $GLOBALS['platform'] = 'platform_full';
    $to = click(4101, 'subscription');
    ok('Subscribe posted for a part-paid invoice: refused with the reason, nothing created', strpos($to, 'paymentfailed=true') !== false
        && $GLOBALS['sentBody'] === null && strpos(lastLog()['data']['error'] ?? '', '[part_paid]') !== false, json_encode(lastLog()));

    echo "Pay Now sends the invoice's own lines\n";
    reset_mixed();
    click(4100, 'payment');
    $b = $GLOBALS['sentBody'] ?? [];
    ok('taxed live case: the domain line at its gross price, tax sent inside it',
        array_column($b['lineItems'] ?? [], 'unitPrice') === [135025]
        && strpos($b['lineItems'][0]['description'] ?? '', 'taptipm.com') !== false && ($b['itemTaxLocalCurrencyAmount'] ?? null) === 20597
        && ($b['taxBehavior'] ?? '') === 'inclusive', json_encode($b));
    ok('taxed live case: nothing logged about a single line', !activity_with('checkout-single-line'));
    click(4001, 'payment');
    ok('four-line invoice: four lines', array_column($GLOBALS['sentBody']['lineItems'] ?? [], 'unitPrice') === [100000, 15000, 50000, 20000], json_encode($GLOBALS['sentBody']));
    click(4101, 'payment');
    ok('part-paid: lines scaled to the 1,000.00 due, already-paid in metadata',
        array_column($GLOBALS['sentBody']['lineItems'] ?? [], 'unitPrice') === [86956, 13044]
        && ($GLOBALS['sentBody']['metadata']['whmcs_already_paid'] ?? '') === '15000', json_encode($GLOBALS['sentBody']));
    click(4102, 'payment');
    ok('credit applied: lines scaled to the 1,000.00 due, credit in metadata',
        array_column($GLOBALS['sentBody']['lineItems'] ?? [], 'unitPrice') === [86956, 13044]
        && ($GLOBALS['sentBody']['metadata']['whmcs_credit_applied'] ?? '') === '15000', json_encode($GLOBALS['sentBody']));
    Capsule::$tables['tblinvoiceitems'][] = ['id' => 11, 'invoiceid' => 4103, 'type' => 'Item', 'relid' => 0, 'description' => 'Goodwill credit', 'amount' => -100.00, 'taxed' => 0, 'duedate' => '0000-00-00'];
    Capsule::$tables['tblinvoices'][4]['total'] = 200.00;
    click(4103, 'payment');
    ok('negative line with no item: one "Invoice #4103" line for the balance',
        ($GLOBALS['sentBody']['lineItems'] ?? null) === [['description' => 'Invoice #4103', 'quantity' => 1, 'unitPrice' => 20000]], json_encode($GLOBALS['sentBody']));
    ok('negative line with no item: why logged at info level', count(activity_with('PaymentHooks info [checkout-single-line]: Invoice 4103 is sent as one')) === 1
        && strpos(activity_with('checkout-single-line')[0], 'Goodwill credit') !== false, json_encode($GLOBALS['activity']));
}
