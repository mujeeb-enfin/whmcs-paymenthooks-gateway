<?php
/**
 * Runs the real webhook path (callback handle_webhook -> WebhookRouter ->
 * SubscriptionSync) against an in-memory stand-in for the WHMCS functions and
 * Capsule tables it uses, and checks what lands in the Gateway Log and on
 * invoices. Fixtures are the real PaymentHooks envelopes in tests/fixtures.
 *
 *   php tests/webhook-harness.php
 *
 * The stand-in implements only the query-builder calls the module makes; it is
 * not a WHMCS emulator. Final confirmation is a run on a real WHMCS install.
 */

namespace WHMCS\Database {
    class Capsule
    {
        /** @var array<string, array<int, array>> */
        public static $tables = [];
        /** Tables that have a native subscriptionid column in the simulated WHMCS version. */
        public static $withSubscriptionColumn = ['tblhosting', 'tbldomains', 'tblhostingaddons'];

        public static function table(string $name): Query
        {
            if (!isset(self::$tables[$name])) {
                self::$tables[$name] = [];
            }
            return new Query($name);
        }

        public static function schema(): Schema
        {
            return new Schema();
        }
    }

    class Schema
    {
        public function hasTable(string $t): bool { return true; }
        public function hasColumn(string $t, string $c): bool { return in_array($t, Capsule::$withSubscriptionColumn, true); }
    }

    class Query
    {
        private $table;
        private $wheres = [];
        private $order = null;

        public function __construct(string $table) { $this->table = $table; }

        public function where(string $col, $op, $val = null): self
        {
            if (func_num_args() === 2) { $val = $op; $op = '='; }
            $this->wheres[] = [$col, $op, $val];
            return $this;
        }
        public function whereIn(string $col, $vals): self
        {
            if (is_callable($vals)) {
                // Only form used: invoices containing given Hosting relids.
                $sub = new SubQuery();
                $vals($sub);
                $ids = [];
                foreach (Capsule::$tables['tblinvoiceitems'] ?? [] as $r) {
                    if ($r['type'] === 'Hosting' && in_array($r['relid'], $sub->relids, true)) {
                        $ids[] = $r['invoiceid'];
                    }
                }
                $vals = $ids;
            }
            $this->wheres[] = [$col, 'in', $vals];
            return $this;
        }
        public function whereNotNull(string $col): self { $this->wheres[] = [$col, 'notnull', null]; return $this; }
        public function orderBy(string $c, string $d = 'asc'): self { $this->order = [$c, $d]; return $this; }
        public function limit(int $n): self { return $this; }
        public function join(): self { return $this; }

        private function rows(): array
        {
            $out = [];
            foreach (Capsule::$tables[$this->table] as $i => $r) {
                $ok = true;
                foreach ($this->wheres as [$c, $op, $v]) {
                    $c = preg_replace('/^.*\./', '', $c);
                    $x = $r[$c] ?? null;
                    if ($op === '=' && $x != $v) { $ok = false; }
                    if ($op === '<' && !($x < $v)) { $ok = false; }
                    if ($op === '>' && !($x > $v)) { $ok = false; }
                    if ($op === 'in' && !in_array($x, (array) $v)) { $ok = false; }
                    if ($op === 'notnull' && $x === null) { $ok = false; }
                }
                if ($ok) { $out[$i] = $r; }
            }
            if ($this->order) {
                [$c, $d] = $this->order;
                uasort($out, function ($a, $b) use ($c, $d) { $x = ($a[$c] ?? 0) <=> ($b[$c] ?? 0); return $d === 'desc' ? -$x : $x; });
            }
            return $out;
        }

        public function first() { $r = $this->rows(); return $r ? (object) reset($r) : null; }
        public function get() { return new Collection(array_map(function ($r) { return (object) $r; }, array_values($this->rows()))); }
        public function exists(): bool { return (bool) $this->rows(); }
        public function count(): int { return count($this->rows()); }
        public function value(string $c)
        {
            // Currency join used by invoiceCurrency(): client -> currency code.
            if ($c === 'tblcurrencies.code') {
                $client = $this->first();
                return $client ? (Capsule::$tables['tblcurrencies'][$client->currency]['code'] ?? null) : null;
            }
            $r = $this->first();
            return $r ? ($r->$c ?? null) : null;
        }
        public function pluck(string $c): Collection { return new Collection(array_column($this->rows(), $c)); }
        public function sum(string $c) { return array_sum(array_column($this->rows(), $c)); }
        public function insert(array $row): bool
        {
            if ($this->table === 'mod_paymenthooks_events') {
                foreach (Capsule::$tables[$this->table] as $r) {
                    if ($r['event_id'] === $row['event_id']) {
                        throw new \Illuminate\Database\QueryException('Duplicate entry', '23000');
                    }
                }
            }
            if (!isset($row['id']) && in_array($this->table, ['mod_paymenthooks_subscriptions', 'mod_paymenthooks_refunds', 'mod_paymenthooks_sessions'], true)) {
                $row['id'] = count(Capsule::$tables[$this->table]) + 1;
            }
            Capsule::$tables[$this->table][] = $row;
            return true;
        }
        public function update(array $set): int
        {
            $n = 0;
            foreach ($this->rows() as $i => $r) {
                Capsule::$tables[$this->table][$i] = $set + $r;
                $n++;
            }
            return $n;
        }
        public function delete(): int
        {
            $n = 0;
            foreach (array_keys($this->rows()) as $i) { unset(Capsule::$tables[$this->table][$i]); $n++; }
            return $n;
        }
    }

    class SubQuery
    {
        public $relids = [];
        public function select() { return $this; }
        public function from() { return $this; }
        public function whereIn(string $c, array $v) { $this->relids = $v; return $this; }
        public function where() { return $this; }
    }

    class Collection implements \IteratorAggregate
    {
        private $items;
        public function __construct(array $i) { $this->items = $i; }
        public function all(): array { return $this->items; }
        public function isEmpty(): bool { return !$this->items; }
        public function first() { return $this->items[0] ?? null; }
        public function getIterator(): \Iterator { return new \ArrayIterator($this->items); }
    }
}

namespace Illuminate\Database {
    class QueryException extends \Exception
    {
        public function __construct(string $m, string $code) { parent::__construct($m); $this->code = $code; }
    }
}

namespace {
    define('WHMCS', true);

    $GLOBALS['gatewayLog'] = [];
    $GLOBALS['payments'] = [];
    $GLOBALS['activity'] = [];
    function logTransaction($gateway, $data, $status) { $GLOBALS['gatewayLog'][] = ['gateway' => $gateway, 'status' => $status, 'data' => $data]; }
    function logActivity($m) { $GLOBALS['activity'][] = $m; }
    function logModuleCall() {}
    function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)
    {
        $GLOBALS['payments'][] = compact('invoiceId', 'transId', 'amount', 'fee', 'gateway');
        $t = &\WHMCS\Database\Capsule::$tables;
        $t['tblaccounts'][] = ['id' => count($t['tblaccounts']) + 1, 'transid' => $transId, 'gateway' => $gateway, 'invoiceid' => $invoiceId, 'amountin' => $amount, 'amountout' => 0, 'refundid' => 0];
        foreach ($t['tblinvoices'] as &$inv) {
            if ($inv['id'] == $invoiceId) {
                $inv['paid'] = ($inv['paid'] ?? 0) + $amount;
                if ($inv['paid'] + 0.001 >= $inv['total']) { $inv['status'] = 'Paid'; }
            }
        }
    }
    $GLOBALS['refunds'] = [];
    function refundInvoicePayment($transId, $amount, $sendToGateway, $addAsCredit, $sendEmail, $refundTransId)
    {
        $GLOBALS['refunds'][] = compact('transId', 'amount', 'sendToGateway', 'refundTransId');
        $t = &\WHMCS\Database\Capsule::$tables;
        $t['tblaccounts'][] = ['id' => count($t['tblaccounts']) + 1, 'transid' => $refundTransId, 'gateway' => 'paymenthooks', 'invoiceid' => 0, 'amountin' => 0, 'amountout' => $amount, 'refundid' => $transId];
        return 'manual';
    }
    $GLOBALS['api'] = [];
    function localAPI($cmd, $args)
    {
        $GLOBALS['api'][] = [$cmd, $args];
        $t = &\WHMCS\Database\Capsule::$tables;
        if ($cmd === 'GetInvoice') {
            foreach ($t['tblinvoices'] as $inv) {
                if ($inv['id'] == $args['invoiceid']) {
                    return ['result' => 'success', 'balance' => $inv['total'] - ($inv['paid'] ?? 0), 'total' => $inv['total']];
                }
            }
            return ['result' => 'error', 'message' => 'not found'];
        }
        if ($cmd === 'GenInvoices') {
            // WHMCS raises one renewal invoice for the due items it is asked about.
            if (!empty($GLOBALS['genInvoicesFails'])) { return ['result' => 'error', 'message' => 'cron lock held']; }
            if (!empty($GLOBALS['genInvoicesNoop'])) { return ['result' => 'success', 'latestinvoiceid' => 0]; }
            $id = 3000 + count($t['tblinvoices']);
            $total = 0;
            $map = ['serviceids' => ['Hosting', 'tblhosting', 'amount'], 'domainids' => ['DomainRegister', 'tbldomains', 'recurringamount'], 'addonids' => ['Addon', 'tblhostingaddons', 'recurring']];
            foreach ($map as $k => [$type, $table, $col]) {
                foreach ($args[$k] ?? [] as $rel) {
                    foreach ($t[$table] as $row) {
                        if ($row['id'] == $rel) { $total += $row[$col]; }
                    }
                    $t['tblinvoiceitems'][] = ['invoiceid' => $id, 'type' => $type, 'relid' => $rel];
                }
            }
            $t['tblinvoices'][] = ['id' => $id, 'userid' => 5, 'status' => 'Unpaid', 'total' => $total, 'notes' => ''];
            return ['result' => 'success', 'latestinvoiceid' => $id];
        }
        if ($cmd === 'UpdateInvoice') {
            foreach ($t['tblinvoices'] as &$inv) {
                if ($inv['id'] == $args['invoiceid']) { $inv['notes'] = $args['notes']; return ['result' => 'success']; }
            }
            return ['result' => 'error', 'message' => 'not found'];
        }
        return ['result' => 'error', 'message' => 'unsupported ' . $cmd];
    }

    require __DIR__ . '/../modules/gateways/paymenthooks/lib/bootstrap.php';

    // Load only the callback's functions (its top-level code bootstraps WHMCS).
    $src = file_get_contents(__DIR__ . '/../modules/gateways/callback/paymenthooks.php');
    $src = substr($src, strpos($src, 'function handle_checkout_click'));
    $src = str_replace('die($body);', 'throw new HarnessResponse($status, $body);', $src);
    eval('use WHMCS\Database\Capsule; use PaymentHooks\Config; use PaymentHooks\Client; use PaymentHooks\ApiException;'
        . ' use PaymentHooks\Logger; use PaymentHooks\GatewayLog; use PaymentHooks\Signature; use PaymentHooks\Schema;'
        . ' use PaymentHooks\InvoiceMapper; use PaymentHooks\CheckoutBuilder; use PaymentHooks\InvoicePlan;'
        . ' use PaymentHooks\WebhookRouter; ' . $src);

    class HarnessResponse extends \Exception
    {
        public $status;
        public function __construct(int $s, string $b) { parent::__construct($b); $this->status = $s; }
    }

    $secret = 'whsec_' . str_repeat('c', 32);
    $config = \PaymentHooks\Config::fromParams(['secretKey' => 'phk_live_' . str_repeat('a', 64), 'webhookSecret' => $secret]);
    $logger = new \PaymentHooks\Logger('paymenthooks', false);

    function reset_db(): void
    {
        \WHMCS\Database\Capsule::$tables = [
            'tblcurrencies' => [1 => ['id' => 1, 'code' => 'INR']],
            'tblclients' => [['id' => 5, 'currency' => 1]],
            'tblinvoices' => [
                ['id' => 1001, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1000.00],
                ['id' => 1003, 'userid' => 5, 'status' => 'Unpaid', 'total' => 499.00],
            ],
            'tblinvoiceitems' => [['invoiceid' => 1002, 'type' => 'Hosting', 'relid' => 77], ['invoiceid' => 1003, 'type' => 'Hosting', 'relid' => 77]],
            'tblhosting' => [['id' => 77, 'billingcycle' => 'Monthly', 'subscriptionid' => 'ph_sub_whmcs_1']],
            'tblaccounts' => [],
            'mod_paymenthooks_events' => [],
            'mod_paymenthooks_sessions' => [['id' => 1, 'invoice_id' => 1001, 'session_id' => 'cs_whmcs_1', 'status' => 'open', 'fee_minor' => 2000, 'plan_json' => null]],
            'mod_paymenthooks_subscriptions' => [],
            'mod_paymenthooks_refunds' => [],
            'tbldomains' => [],
            'tblhostingaddons' => [],
        ];
        \WHMCS\Database\Capsule::$withSubscriptionColumn = ['tblhosting', 'tbldomains', 'tblhostingaddons'];
        $GLOBALS['gatewayLog'] = [];
        $GLOBALS['payments'] = [];
        $GLOBALS['refunds'] = [];
        $GLOBALS['api'] = [];
        $GLOBALS['genInvoicesFails'] = false;
        $GLOBALS['genInvoicesNoop'] = false;
    }

    function deliver(string $body, string $secret): int
    {
        global $config, $logger;
        $t = (string) time();
        $_SERVER['HTTP_X_PAYMENTHOOKS_SIGNATURE'] = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
        try {
            handle_webhook($config, $logger, $body);
        } catch (HarnessResponse $r) {
            return $r->status;
        }
        return 0;
    }

    $fails = 0;
    function ok(string $name, bool $cond, string $detail = ''): void
    {
        global $fails;
        echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : ' -- ' . $detail) . "\n";
        if (!$cond) { $fails++; }
    }
    function lastLog(): array { $l = $GLOBALS['gatewayLog']; return $l ? end($l) : []; }
    function fx(string $n): string { return (string) file_get_contents(__DIR__ . '/fixtures/' . $n . '.json'); }

    echo "Webhook path -> Gateway Log\n";

    reset_db();
    $st = deliver(fx('checkout_session_completed'), $secret);
    ok('completed: 200', $st === 200, (string) $st);
    ok('completed: Gateway Log "Successful"', (lastLog()['status'] ?? '') === 'Successful', json_encode(lastLog()));
    ok('completed: log names the event', (lastLog()['data']['eventId'] ?? '') === 'evt_whmcs_completed' && (lastLog()['data']['type'] ?? '') === 'checkout.session.completed');
    ok('completed: invoice credited with balance (1020.00 - 20.00 fee)', ($GLOBALS['payments'][0]['amount'] ?? null) == 1000.0 && $GLOBALS['payments'][0]['fee'] === 0, json_encode($GLOBALS['payments']));
    ok('completed: transaction id is the PaymentHooks payment', ($GLOBALS['payments'][0]['transId'] ?? '') === 'ph_pay_whmcs_1');

    $st = deliver(fx('checkout_session_completed'), $secret);
    ok('redelivery: 200 and logged as Duplicate', $st === 200 && (lastLog()['status'] ?? '') === 'Duplicate', json_encode(lastLog()));
    ok('redelivery: not applied twice', count($GLOBALS['payments']) === 1);

    $st = deliver(fx('checkout_session_completed'), 'whsec_wrong');
    ok('bad signature: 401 and logged', $st === 401 && (lastLog()['status'] ?? '') === 'Invalid Signature', json_encode(lastLog()));
    ok('bad signature: secret not in the log', strpos(json_encode($GLOBALS['gatewayLog']), $secret) === false);

    $st = deliver('{"not":"an event"}', $secret);
    ok('bad payload: 400 and logged', $st === 400 && (lastLog()['status'] ?? '') === 'Invalid Payload');

    reset_db();
    $st = deliver(fx('subscription_renewed'), $secret);
    ok('renewal: 200 and logged', $st === 200 && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode(lastLog()));
    ok('renewal: applied to the service invoice', ($GLOBALS['payments'][0]['invoiceId'] ?? 0) === 1003 && $GLOBALS['payments'][0]['amount'] == 499.0, json_encode($GLOBALS['payments']));

    reset_db();
    \WHMCS\Database\Capsule::$tables['tblinvoices'][1]['status'] = 'Paid';
    \WHMCS\Database\Capsule::$tables['tblhosting'][0] += ['nextduedate' => date('Y-m-d', time() + 86400), 'amount' => 499.00];
    $GLOBALS['genInvoicesFails'] = true;
    $st = deliver(fx('subscription_renewed'), $secret);
    ok('renewal, GenInvoices failing: 500 (redeliver) and logged as Error', $st === 500 && (lastLog()['status'] ?? '') === 'Error', json_encode(lastLog()));
    ok('renewal, GenInvoices failing: claim released for redelivery', count(\WHMCS\Database\Capsule::$tables['mod_paymenthooks_events']) === 0);

    reset_db();
    \WHMCS\Database\Capsule::$tables['tblinvoices'][1]['status'] = 'Paid';
    \WHMCS\Database\Capsule::$tables['tblhosting'][0] += ['nextduedate' => date('Y-m-d', time() + 86400), 'amount' => 499.00];
    $st = deliver(fx('subscription_renewed'), $secret);
    $gen = array_values(array_filter($GLOBALS['api'], function ($c) { return $c[0] === 'GenInvoices'; }));
    ok('renewal before WHMCS invoice: WHMCS asked to raise it for exactly that service', count($gen) === 1 && ($gen[0][1]['serviceids'] ?? []) === [77] && !empty($gen[0][1]['noemails']), json_encode($gen));
    ok('renewal before WHMCS invoice: charge recorded on the generated invoice', $st === 200 && ($GLOBALS['payments'][0]['invoiceId'] ?? 0) >= 3000
        && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode(lastLog()));

    reset_db();
    \WHMCS\Database\Capsule::$tables['tblinvoices'][1]['status'] = 'Paid';
    \WHMCS\Database\Capsule::$tables['tblhosting'][0] += ['nextduedate' => date('Y-m-d', time() + 20 * 86400)];
    $st = deliver(fx('subscription_renewed'), $secret);
    $gen = array_filter($GLOBALS['api'], function ($c) { return $c[0] === 'GenInvoices'; });
    ok('renewal when WHMCS has the period paid and nothing due: Needs Attention, not applied, no invoice raised',
        $st === 200 && (lastLog()['status'] ?? '') === 'Needs Attention' && !$GLOBALS['payments'] && !$gen, json_encode(lastLog()));

    reset_db();
    \WHMCS\Database\Capsule::$tables['tblinvoices'][1]['status'] = 'Paid';
    \WHMCS\Database\Capsule::$tables['tblhosting'][0] += ['nextduedate' => date('Y-m-d', time() + 86400), 'amount' => 499.00];
    $GLOBALS['genInvoicesNoop'] = true;
    $st = deliver(fx('subscription_renewed'), $secret);
    ok('renewal due but WHMCS raised no invoice: 500 Retry Requested, nothing applied', $st === 500 && (lastLog()['status'] ?? '') === 'Retry Requested'
        && !$GLOBALS['payments'] && count(\WHMCS\Database\Capsule::$tables['mod_paymenthooks_events']) === 0, json_encode(lastLog()));

    reset_db();
    $st = deliver(fx('subscription_renewed_credit'), $secret);
    ok('credit-covered renewal: logged, nothing applied', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored' && !$GLOBALS['payments']);

    reset_db();
    $st = deliver(fx('subscription_cancelled'), $secret);
    ok('cancelled (legacy binding): logged and service unbound', $st === 200 && (lastLog()['status'] ?? '') === 'Subscription Cancelled'
        && \WHMCS\Database\Capsule::$tables['tblhosting'][0]['subscriptionid'] === '');

    echo "Subscribe & Auto-pay: hosting + domain + addon\n";
    function reset_subscribed(): void
    {
        reset_db();
        $t = &\WHMCS\Database\Capsule::$tables;
        $soon = date('Y-m-d', time() + 86400);
        $t['tblhosting'] = [['id' => 77, 'billingcycle' => 'Monthly', 'amount' => 1000.00, 'subscriptionid' => '', 'nextduedate' => $soon]];
        $t['tbldomains'] = [['id' => 12, 'registrationperiod' => 1, 'recurringamount' => 800.00, 'subscriptionid' => '', 'nextduedate' => $soon]];
        $t['tblhostingaddons'] = [['id' => 5, 'billingcycle' => 'Monthly', 'recurring' => 200.00, 'subscriptionid' => '', 'nextduedate' => $soon]];
        $t['tblinvoices'] = [['id' => 1002, 'userid' => 5, 'status' => 'Unpaid', 'total' => 499.00]];
        $t['tblinvoiceitems'] = [['invoiceid' => 1002, 'type' => 'Hosting', 'relid' => 77], ['invoiceid' => 1002, 'type' => 'Addon', 'relid' => 5]];
        $t['mod_paymenthooks_sessions'] = [['id' => 1, 'invoice_id' => 1002, 'session_id' => 'cs_whmcs_2', 'status' => 'open', 'fee_minor' => 0,
            'plan_json' => json_encode(['subscribed' => [
                ['relType' => 'hosting', 'relId' => 77, 'renewalMinor' => 100000, 'interval' => 'month', 'intervalCount' => 1, 'description' => 'Starter'],
                ['relType' => 'addon', 'relId' => 5, 'renewalMinor' => 20000, 'interval' => 'month', 'intervalCount' => 1, 'description' => 'Backups'],
            ]])]];
    }
    function rows(string $table): array { return array_values(\WHMCS\Database\Capsule::$tables[$table]); }

    reset_subscribed();
    $st = deliver(fx('checkout_session_completed_subscription'), $secret);
    $subs = rows('mod_paymenthooks_subscriptions');
    ok('first payment: 200, recorded', $st === 200 && ($GLOBALS['payments'][0]['transId'] ?? '') === 'ph_pay_whmcs_2', json_encode(lastLog()));
    ok('first payment: hosting and addon bound to the subscription', count($subs) === 2
        && $subs[0]['rel_type'] === 'hosting' && $subs[1]['rel_type'] === 'addon' && $subs[1]['subscription_id'] === 'ph_sub_whmcs_1', json_encode($subs));
    ok('first payment: id mirrored on tblhosting and tblhostingaddons', rows('tblhosting')[0]['subscriptionid'] === 'ph_sub_whmcs_1'
        && rows('tblhostingaddons')[0]['subscriptionid'] === 'ph_sub_whmcs_1');
    ok('first payment: domain (other cycle) not bound', rows('tbldomains')[0]['subscriptionid'] === '');

    // Renewal of 1,200.00 (hosting 1,000 + addon 200), WHMCS raised one combined renewal invoice.
    $t = &\WHMCS\Database\Capsule::$tables;
    $t['tblinvoices'][] = ['id' => 2001, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1200.00];
    $t['tblinvoiceitems'][] = ['invoiceid' => 2001, 'type' => 'Hosting', 'relid' => 77];
    $t['tblinvoiceitems'][] = ['invoiceid' => 2001, 'type' => 'Addon', 'relid' => 5];
    $ev = json_decode(fx('subscription_renewed'), true);
    $ev['id'] = 'evt_multi_renew';
    $ev['data']['object']['latest_payment'] = ['id' => 'ph_pay_multi_1', 'amount' => 120000, 'currency' => 'inr'];
    $GLOBALS['payments'] = [];
    $st = deliver(json_encode($ev), $secret);
    ok('multi-item renewal: one payment on the combined renewal invoice', $st === 200 && count($GLOBALS['payments']) === 1
        && $GLOBALS['payments'][0]['invoiceId'] === 2001 && $GLOBALS['payments'][0]['amount'] == 1200.0
        && (lastLog()['status'] ?? '') === 'Subscription Renewal Paid', json_encode([$GLOBALS['payments'], lastLog()]));

    $inv = ['id' => 'evt_inv_paid', 'type' => 'invoice.paid', 'livemode' => false, 'data' => ['object' => ['id' => 'ph_inv_7', 'object' => 'invoice',
        'subscription' => 'ph_sub_whmcs_1', 'payment_intent' => 'ph_pay_multi_1', 'amount_paid' => 120000, 'currency' => 'inr', 'billing_reason' => 'subscription_cycle']]];
    $st = deliver(json_encode($inv), $secret);
    ok('invoice.paid for the same renewal: Duplicate, applied once', $st === 200 && (lastLog()['status'] ?? '') === 'Duplicate' && count($GLOBALS['payments']) === 1, json_encode(lastLog()));

    // Next cycle: WHMCS raised separate invoices for hosting and addon.
    $t['tblinvoices'][] = ['id' => 2010, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1000.00];
    $t['tblinvoices'][] = ['id' => 2011, 'userid' => 5, 'status' => 'Unpaid', 'total' => 200.00];
    $t['tblinvoiceitems'][] = ['invoiceid' => 2010, 'type' => 'Hosting', 'relid' => 77];
    $t['tblinvoiceitems'][] = ['invoiceid' => 2011, 'type' => 'Addon', 'relid' => 5];
    $inv['id'] = 'evt_inv_paid_2';
    $inv['data']['object']['payment_intent'] = 'ph_pay_multi_2';
    $GLOBALS['payments'] = [];
    $st = deliver(json_encode($inv), $secret);
    ok('invoice.paid renewal split over separate WHMCS invoices', $st === 200 && count($GLOBALS['payments']) === 2
        && $GLOBALS['payments'][0]['invoiceId'] === 2010 && $GLOBALS['payments'][0]['amount'] == 1000.0
        && $GLOBALS['payments'][1]['invoiceId'] === 2011 && $GLOBALS['payments'][1]['amount'] == 200.0, json_encode($GLOBALS['payments']));

    $inv['id'] = 'evt_inv_foreign';
    $inv['data']['object']['subscription'] = 'ph_sub_elsewhere';
    $inv['data']['object']['payment_intent'] = 'ph_pay_foreign';
    $st = deliver(json_encode($inv), $secret);
    ok('invoice.paid for a subscription not bound here: Ignored', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored', json_encode(lastLog()));

    // Renewal failed: note on the unpaid renewal invoice, nothing charged.
    $t['tblinvoices'][] = ['id' => 2020, 'userid' => 5, 'status' => 'Unpaid', 'total' => 1200.00, 'notes' => 'existing note'];
    $t['tblinvoiceitems'][] = ['invoiceid' => 2020, 'type' => 'Hosting', 'relid' => 77];
    $ev = json_decode(fx('subscription_renewed'), true);
    $ev['id'] = 'evt_fail';
    $ev['type'] = 'subscription.renewal_failed';
    unset($ev['data']['object']['latest_payment']);
    $ev['data']['object']['failure_reason'] = 'Card declined';
    $GLOBALS['payments'] = [];
    $st = deliver(json_encode($ev), $secret);
    $notes = '';
    foreach (rows('tblinvoices') as $r) { if ($r['id'] === 2020) { $notes = $r['notes']; } }
    ok('renewal failed: 200, logged Failure, nothing charged', $st === 200 && (lastLog()['status'] ?? '') === 'Failure' && !$GLOBALS['payments'], json_encode(lastLog()));
    ok('renewal failed: note appended to the renewal invoice', strpos($notes, 'existing note') === 0 && strpos($notes, 'Card declined') !== false, $notes);
    ok('renewal failed: invoice stays Unpaid for WHMCS overdue handling', rows('tblinvoices')[count(rows('tblinvoices')) - 1]['status'] === 'Unpaid');

    // Cancelled at PaymentHooks: every bound item unbound.
    $st = deliver(fx('subscription_cancelled'), $secret);
    $subs = rows('mod_paymenthooks_subscriptions');
    ok('cancelled: logged', $st === 200 && (lastLog()['status'] ?? '') === 'Subscription Cancelled', json_encode(lastLog()));
    ok('cancelled: all items marked cancelled and ids cleared', $subs[0]['status'] === 'cancelled' && $subs[1]['status'] === 'cancelled'
        && rows('tblhosting')[0]['subscriptionid'] === '' && rows('tblhostingaddons')[0]['subscriptionid'] === '', json_encode($subs));
    $ev = json_decode(fx('subscription_renewed'), true);
    $ev['id'] = 'evt_after_cancel';
    $ev['data']['object']['latest_payment'] = ['id' => 'ph_pay_after', 'amount' => 120000, 'currency' => 'inr'];
    $GLOBALS['payments'] = [];
    $st = deliver(json_encode($ev), $secret);
    ok('renewal after cancellation: Needs Attention, not applied', $st === 200 && (lastLog()['status'] ?? '') === 'Needs Attention' && !$GLOBALS['payments']);

    reset_subscribed();
    \WHMCS\Database\Capsule::$withSubscriptionColumn = ['tblhosting'];
    deliver(fx('checkout_session_completed_subscription'), $secret);
    ok('WHMCS without addon subscriptionid column: bound in the module table only', count(rows('mod_paymenthooks_subscriptions')) === 2
        && rows('tblhostingaddons')[0]['subscriptionid'] === '' && rows('tblhosting')[0]['subscriptionid'] === 'ph_sub_whmcs_1');
    $ev = json_decode(fx('subscription_cancelled'), true);
    $ev['type'] = 'subscription.deleted';
    $ev['id'] = 'evt_deleted';
    $st = deliver(json_encode($ev), $secret);
    ok('subscription.deleted unbinds like cancelled', $st === 200 && rows('mod_paymenthooks_subscriptions')[0]['status'] === 'cancelled');

    echo "Refunds made at PaymentHooks\n";
    reset_db();
    $st = deliver(fx('payment_refunded'), $secret);
    ok('refund of unknown payment: logged as Ignored', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored');
    deliver(fx('checkout_session_completed'), $secret);
    $ev = json_decode(fx('payment_refunded'), true);
    $ev['id'] = 'evt_whmcs_refunded_2';
    $st = deliver(json_encode($ev), $secret);
    ok('dashboard refund: recorded in WHMCS against the payment', $st === 200 && count($GLOBALS['refunds']) === 1
        && $GLOBALS['refunds'][0]['amount'] == 500.0 && $GLOBALS['refunds'][0]['sendToGateway'] === false
        && (lastLog()['status'] ?? '') === 'Refund Notification', json_encode([$GLOBALS['refunds'], lastLog()]));
    $ev['id'] = 'evt_whmcs_refunded_3';
    $st = deliver(json_encode($ev), $secret);
    ok('same cumulative refund again: not booked twice', $st === 200 && count($GLOBALS['refunds']) === 1, json_encode($GLOBALS['refunds']));
    $ev['id'] = 'evt_whmcs_refunded_4';
    $ev['data']['object']['charges']['data'][0]['amount_refunded'] = 80000;
    $st = deliver(json_encode($ev), $secret);
    ok('second dashboard refund: only the increase (300.00) booked', $st === 200 && count($GLOBALS['refunds']) === 2 && $GLOBALS['refunds'][1]['amount'] == 300.0, json_encode($GLOBALS['refunds']));
    $ev['id'] = 'evt_whmcs_refunded_5';
    $ev['data']['object']['charges']['data'][0]['amount_refunded'] = 102000;
    $st = deliver(json_encode($ev), $secret);
    ok('full refund incl. convenience fee: WHMCS refunds what it credited, rest flagged', $st === 200 && count($GLOBALS['refunds']) === 3
        && $GLOBALS['refunds'][2]['amount'] == 200.0 && (lastLog()['status'] ?? '') === 'Needs Attention', json_encode([$GLOBALS['refunds'], lastLog()]));

    reset_db();
    deliver(fx('checkout_session_completed'), $secret);
    \PaymentHooks\RefundSync::reserveWhmcsRefund('ph_pay_whmcs_1', 50000);
    $ev = json_decode(fx('payment_refunded'), true);
    $ev['id'] = 'evt_refund_from_whmcs';
    $st = deliver(json_encode($ev), $secret);
    ok('refund issued from WHMCS: its webhook is not booked again', $st === 200 && !$GLOBALS['refunds'] && (lastLog()['status'] ?? '') === 'Refund Notification', json_encode(lastLog()));

    reset_db();
    deliver(fx('checkout_session_completed'), $secret);
    \WHMCS\Database\Capsule::$tables['tblcurrencies'][1]['code'] = 'USD';
    $ev = json_decode(fx('payment_refunded'), true);
    $ev['id'] = 'evt_refund_currency';
    $st = deliver(json_encode($ev), $secret);
    ok('refund in another currency: Needs Attention, not booked', $st === 200 && !$GLOBALS['refunds'] && (lastLog()['status'] ?? '') === 'Needs Attention');

    reset_db();
    $ev = json_decode(fx('checkout_session_completed'), true);
    $ev['id'] = 'evt_other';
    $ev['type'] = 'customer.created';
    $st = deliver(json_encode($ev), $secret);
    ok('unhandled type: 200 and logged as Ignored', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored');

    reset_db();
    \WHMCS\Database\Capsule::$tables['tblcurrencies'][1]['code'] = 'USD';
    $st = deliver(fx('checkout_session_completed'), $secret);
    ok('currency mismatch: logged Needs Attention, not applied', $st === 200 && (lastLog()['status'] ?? '') === 'Needs Attention' && !$GLOBALS['payments']);

    ok('every delivery wrote a Gateway Log entry under the module name', count(array_filter($GLOBALS['gatewayLog'], function ($e) { return $e['gateway'] !== 'paymenthooks'; })) === 0);

    echo "\n" . ($fails === 0 ? 'all passed' : $fails . ' failed') . "\n";
    exit($fails === 0 ? 0 : 1);
}
