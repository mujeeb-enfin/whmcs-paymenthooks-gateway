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
        public function hasColumn(string $t, string $c): bool { return true; }
    }

    class Query
    {
        private $table;
        private $wheres = [];

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
        public function orderBy(string $c, string $d = 'asc'): self { return $this; }
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
                    if ($op === 'in' && !in_array($x, $v)) { $ok = false; }
                    if ($op === 'notnull' && $x === null) { $ok = false; }
                }
                if ($ok) { $out[$i] = $r; }
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
        public function insert(array $row): bool
        {
            if ($this->table === 'mod_paymenthooks_events') {
                foreach (Capsule::$tables[$this->table] as $r) {
                    if ($r['event_id'] === $row['event_id']) {
                        throw new \Illuminate\Database\QueryException('Duplicate entry', '23000');
                    }
                }
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
        \WHMCS\Database\Capsule::$tables['tblaccounts'][] = ['transid' => $transId, 'gateway' => $gateway, 'invoiceid' => $invoiceId, 'amountin' => $amount];
    }
    function localAPI($cmd, $args)
    {
        foreach (\WHMCS\Database\Capsule::$tables['tblinvoices'] as $inv) {
            if ($inv['id'] == $args['invoiceid']) {
                return ['result' => 'success', 'balance' => $inv['total']];
            }
        }
        return ['result' => 'error', 'message' => 'not found'];
    }

    require __DIR__ . '/../modules/gateways/paymenthooks/lib/bootstrap.php';

    // Load only the callback's functions (its top-level code bootstraps WHMCS).
    $src = file_get_contents(__DIR__ . '/../modules/gateways/callback/paymenthooks.php');
    $src = substr($src, strpos($src, 'function handle_checkout_click'));
    $src = str_replace('die($body);', 'throw new HarnessResponse($status, $body);', $src);
    eval('use WHMCS\Database\Capsule; use PaymentHooks\Config; use PaymentHooks\Client; use PaymentHooks\ApiException;'
        . ' use PaymentHooks\Logger; use PaymentHooks\GatewayLog; use PaymentHooks\Signature; use PaymentHooks\Schema;'
        . ' use PaymentHooks\InvoiceMapper; use PaymentHooks\CheckoutBuilder; use PaymentHooks\PriceResolver;'
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
            'mod_paymenthooks_sessions' => [['invoice_id' => 1001, 'session_id' => 'cs_whmcs_1', 'status' => 'open', 'fee_minor' => 2000]],
        ];
        $GLOBALS['gatewayLog'] = [];
        $GLOBALS['payments'] = [];
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
    $st = deliver(fx('subscription_renewed'), $secret);
    ok('renewal before WHMCS invoice: 500 (redeliver) and logged', $st === 500 && (lastLog()['status'] ?? '') === 'Retry Requested', json_encode(lastLog()));
    ok('renewal before invoice: claim released for redelivery', count(\WHMCS\Database\Capsule::$tables['mod_paymenthooks_events']) === 0);

    reset_db();
    $st = deliver(fx('subscription_renewed_credit'), $secret);
    ok('credit-covered renewal: logged, nothing applied', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored' && !$GLOBALS['payments']);

    reset_db();
    $st = deliver(fx('subscription_cancelled'), $secret);
    ok('cancelled: logged and service unbound', $st === 200 && (lastLog()['status'] ?? '') === 'Subscription Cancelled'
        && \WHMCS\Database\Capsule::$tables['tblhosting'][0]['subscriptionid'] === '');

    reset_db();
    $st = deliver(fx('payment_refunded'), $secret);
    ok('refund of unknown payment: logged as Ignored', $st === 200 && (lastLog()['status'] ?? '') === 'Ignored');
    deliver(fx('checkout_session_completed'), $secret);
    // A distinct refund event (the earlier one was already processed).
    $ev = json_decode(fx('payment_refunded'), true);
    $ev['id'] = 'evt_whmcs_refunded_2';
    $st = deliver(json_encode($ev), $secret);
    ok('refund of recorded payment: logged as Refund Notification', $st === 200 && (lastLog()['status'] ?? '') === 'Refund Notification', json_encode(lastLog()));

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
