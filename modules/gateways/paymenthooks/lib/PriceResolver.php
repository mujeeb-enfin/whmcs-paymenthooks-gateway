<?php
/**
 * Maps a WHMCS recurring service to a PaymentHooks Product + recurring Price,
 * so a subscription-mode checkout can reference a real priceId (the thing PH
 * needs to establish a mandate and run the billing cycle).
 *
 * The mapping is cached in mod_paymenthooks_price_map keyed by
 * (product_id, billing_cycle, currency, amount_minor). Amount/currency are part
 * of the key because the same WHMCS product can be sold at different prices
 * (promos, currency, config options), and a PH Price is immutable per amount.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class PriceResolver
{
    /** @var Client */
    private $client;
    /** @var Logger */
    private $logger;

    public function __construct(Client $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Returns a PH price id for the recurring service on this invoice, creating
     * the PH product/price on first use. Returns '' if no recurring service is
     * found or the mapping cannot be established.
     *
     * @param int    $invoiceId
     * @param string $currency   ISO code (e.g. INR)
     * @return string PH price id, or '' on failure
     */
    public function resolveForInvoice(int $invoiceId, string $currency): string
    {
        $service = $this->firstRecurringService($invoiceId);
        if ($service === null) {
            return '';
        }

        $cycle = strtolower((string) $service->billingcycle);
        $interval = self::cycleToInterval($cycle);
        if ($interval === null) {
            // Not a cycle PH can express as a recurring interval.
            return '';
        }
        $intervalCount = self::cycleToIntervalCount($cycle);

        $amountMinor = InvoiceMapper::toMinorUnits((float) $service->amount, $currency);
        if ($amountMinor <= 0) {
            return '';
        }

        $productId = (int) $service->packageid;
        $currencyU = strtoupper($currency);

        // Cache hit?
        $row = Capsule::table('mod_paymenthooks_price_map')
            ->where('product_id', $productId)
            ->where('billing_cycle', $cycle)
            ->where('currency', $currencyU)
            ->where('amount_minor', $amountMinor)
            ->orderBy('id', 'desc')
            ->first();
        if ($row && $row->ph_price_id) {
            return $row->ph_price_id;
        }

        // Create PH product (reuse if we already made one for this WHMCS product).
        $phProductId = $this->ensureProduct($productId);
        if ($phProductId === '') {
            return '';
        }

        // Create the recurring price.
        $phPriceId = $this->createPrice($phProductId, $amountMinor, $currencyU, $interval, $intervalCount);
        if ($phPriceId === '') {
            return '';
        }

        Capsule::table('mod_paymenthooks_price_map')->insert([
            'product_id'    => $productId,
            'billing_cycle' => $cycle,
            'currency'      => $currencyU,
            'amount_minor'  => $amountMinor,
            'ph_product_id' => $phProductId,
            'ph_price_id'   => $phPriceId,
            'created_at'    => time(),
        ]);

        return $phPriceId;
    }

    /** First recurring (non one-time) hosting service on the invoice, or null. */
    private function firstRecurringService(int $invoiceId)
    {
        $id = self::firstRecurringServiceId($invoiceId);
        if ($id === 0) {
            return null;
        }
        return Capsule::table('tblhosting')->where('id', $id)->first();
    }

    /**
     * Pure-DB: id of the first recurring (non one-time/free) hosting service on
     * the invoice, or 0. Shared with the webhook so the subscription is bound to
     * exactly the service it was created for, not every service on the invoice.
     */
    public static function firstRecurringServiceId(int $invoiceId): int
    {
        $relIds = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->orderBy('id', 'asc')
            ->pluck('relid')
            ->all();
        if (!$relIds) {
            return 0;
        }

        // Preserve invoice-item order so "first" is deterministic and matches
        // what resolveForInvoice billed.
        foreach ($relIds as $relId) {
            $row = Capsule::table('tblhosting')->where('id', $relId)->first();
            if (!$row) {
                continue;
            }
            $cycle = strtolower((string) $row->billingcycle);
            if ($cycle !== '' && $cycle !== 'one time' && $cycle !== 'free') {
                return (int) $row->id;
            }
        }
        return 0;
    }

    /** Reuses the PH product id already recorded for this WHMCS product, else creates one. */
    private function ensureProduct(int $whmcsProductId): string
    {
        $existing = Capsule::table('mod_paymenthooks_price_map')
            ->where('product_id', $whmcsProductId)
            ->whereNotNull('ph_product_id')
            ->orderBy('id', 'desc')
            ->value('ph_product_id');
        if ($existing) {
            return (string) $existing;
        }

        $name = (string) Capsule::table('tblproducts')->where('id', $whmcsProductId)->value('name');
        if ($name === '') {
            $name = 'WHMCS Product ' . $whmcsProductId;
        }

        try {
            $resp = $this->client->post('/products', [
                'name'     => $name,
                'type'     => 'service',
                'metadata' => ['whmcs_product_id' => (string) $whmcsProductId],
            ], 'prod-' . $whmcsProductId);
            return (string) ($resp['data']['id'] ?? '');
        } catch (ApiException $e) {
            $this->logger->error('price-ensure-product', ['whmcsProductId' => $whmcsProductId], $e->getMessage());
            return '';
        }
    }

    private function createPrice(string $phProductId, int $amountMinor, string $currency, string $interval, int $intervalCount): string
    {
        try {
            $body = [
                'productId' => $phProductId,
                'amount'    => $amountMinor,
                'currency'  => $currency,
                'type'      => 'recurring',
                'interval'  => $interval,
            ];
            if ($intervalCount > 1) {
                $body['intervalCount'] = $intervalCount;
            }
            $resp = $this->client->post('/prices', $body,
                'price-' . $phProductId . '-' . $amountMinor . '-' . $interval . '-' . $intervalCount);
            return (string) ($resp['data']['id'] ?? '');
        } catch (ApiException $e) {
            $this->logger->error('price-create', compact('phProductId', 'amountMinor', 'currency', 'interval', 'intervalCount'), $e->getMessage());
            return '';
        }
    }

    /**
     * Maps a WHMCS billing cycle to a PH price interval (day/week/month/year).
     * WHMCS cycles longer than a year are expressed via intervalCount, but PH's
     * documented intervals are the four base units, so we fold multi-period
     * cycles onto the closest base unit and rely on PH's intervalCount where the
     * caller passes it. Here we return just the base unit; multi-year cycles
     * (e.g. Biennially) map to 'year' and the amount already covers the period.
     */
    public static function cycleToInterval(string $cycle): ?string
    {
        switch (strtolower($cycle)) {
            case 'weekly':
                return 'week';
            case 'monthly':
                return 'month';
            case 'quarterly':
            case 'semi-annually':
            case 'semiannually':
                return 'month';
            case 'annually':
            case 'biennially':
            case 'triennially':
                return 'year';
            default:
                return null;
        }
    }

    /**
     * intervalCount for cycles that are a multiple of the base unit, so PH bills
     * on the correct cadence (e.g. Quarterly = every 3 months).
     */
    public static function cycleToIntervalCount(string $cycle): int
    {
        switch (strtolower($cycle)) {
            case 'quarterly':
                return 3;
            case 'semi-annually':
            case 'semiannually':
                return 6;
            case 'biennially':
                return 2;
            case 'triennially':
                return 3;
            default:
                return 1;
        }
    }
}
