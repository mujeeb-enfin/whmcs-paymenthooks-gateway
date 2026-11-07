<?php
/**
 * Finds (or has WHMCS raise) the renewal invoice(s) a PaymentHooks renewal
 * charge pays for. PaymentHooks charges on its own schedule; WHMCS raises
 * renewal invoices on its own. The module only connects the two, it never
 * charges.
 *
 * For each subscribed item (service, domain, addon):
 *  1. the oldest Unpaid invoice in the charge currency with a line for it;
 *  2. else, when the item is due (nextduedate within DUE_WINDOW days), WHMCS
 *     raises its renewal invoice now (localAPI GenInvoices for exactly those
 *     items, no emails) and step 1 is repeated;
 *  3. else (not due: WHMCS already has this period paid, e.g. the client
 *     paid by hand) the item is reported as already paid.
 * An item that is due but still has no invoice after GenInvoices makes the
 * caller ask PaymentHooks to redeliver.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class RenewalInvoice
{
    /** PaymentHooks' period end and WHMCS's due date can differ by clock and timezone. */
    const DUE_WINDOW = 7;

    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<int, object> $items mod_paymenthooks_subscriptions rows (rel_type, rel_id)
     * @return array{invoices: array<int, array{id:int, balanceMinor:int}>, missing: array, alreadyPaid: array, generated: bool}
     */
    public function locate(array $items, string $currency, bool $generate): array
    {
        $found = $this->find($items, $currency);
        $missing = array_values(array_filter($items, static function ($it) use ($found) {
            return !isset($found['byItem'][$it->rel_type . ':' . $it->rel_id]);
        }));

        $due = [];
        $alreadyPaid = [];
        foreach ($missing as $it) {
            if ($this->isDue($it)) {
                $due[] = $it;
            } else {
                $alreadyPaid[] = $it;
            }
        }

        $generated = false;
        if ($due && $generate) {
            $this->generate($due);
            $generated = true;
            $found = $this->find($items, $currency);
            $due = array_values(array_filter($due, static function ($it) use ($found) {
                return !isset($found['byItem'][$it->rel_type . ':' . $it->rel_id]);
            }));
        }

        return ['invoices' => array_values($found['invoices']), 'missing' => $due, 'alreadyPaid' => $alreadyPaid, 'generated' => $generated];
    }

    /** Oldest unpaid invoice per item, in the currency. */
    private function find(array $items, string $currency): array
    {
        $byItem = [];
        $invoices = [];
        foreach ($items as $it) {
            $lines = Capsule::table('tblinvoiceitems')
                ->where('relid', (int) $it->rel_id)
                ->whereIn('type', InvoicePlan::itemTypes((string) $it->rel_type))
                ->pluck('invoiceid')
                ->all();
            if (!$lines) {
                continue;
            }
            $rows = Capsule::table('tblinvoices')
                ->whereIn('id', array_values(array_unique(array_map('intval', $lines))))
                ->where('status', 'Unpaid')
                ->orderBy('id', 'asc')
                ->get();
            foreach ($rows as $inv) {
                if (WebhookRouter::invoiceCurrency((int) $inv->userid) !== $currency) {
                    continue;
                }
                $byItem[$it->rel_type . ':' . $it->rel_id] = (int) $inv->id;
                if (!isset($invoices[(int) $inv->id])) {
                    $invoices[(int) $inv->id] = ['id' => (int) $inv->id, 'balanceMinor' => InvoiceMapper::balanceMinor((int) $inv->id, $currency)];
                }
                break;
            }
        }
        ksort($invoices);
        return ['byItem' => $byItem, 'invoices' => $invoices];
    }

    private function isDue(object $it): bool
    {
        $table = SubscriptionMap::TABLES[(string) $it->rel_type] ?? '';
        if ($table === '') {
            return false;
        }
        $next = (string) Capsule::table($table)->where('id', (int) $it->rel_id)->value('nextduedate');
        if ($next === '' || strpos($next, '0000') === 0) {
            return false;
        }
        $ts = strtotime($next);
        return $ts !== false && $ts <= time() + self::DUE_WINDOW * 86400;
    }

    /** WHMCS raises the renewal invoice for exactly these items (its own pricing, tax and numbering). */
    private function generate(array $items): void
    {
        $args = ['noemails' => true];
        $keys = ['hosting' => 'serviceids', 'domain' => 'domainids', 'addon' => 'addonids'];
        foreach ($items as $it) {
            $args[$keys[(string) $it->rel_type]][] = (int) $it->rel_id;
        }
        $result = localAPI('GenInvoices', $args);
        if (($result['result'] ?? '') !== 'success') {
            $message = 'GenInvoices failed: ' . (string) ($result['message'] ?? 'unknown error');
            $this->logger->error('renewal-geninvoices', $args, $message);
            throw new \RuntimeException($message);
        }
        $this->logger->call('renewal-geninvoices', $args, $result);
    }
}
