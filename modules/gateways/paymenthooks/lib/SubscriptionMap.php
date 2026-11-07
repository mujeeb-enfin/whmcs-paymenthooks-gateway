<?php
/**
 * PaymentHooks subscription <-> the WHMCS services, domains and addons it
 * auto-renews. mod_paymenthooks_subscriptions is the source of truth (one row
 * per item); the id is mirrored onto the item's own `subscriptionid` column
 * where WHMCS has one (tblhosting always; tbldomains / tblhostingaddons on
 * versions that carry it), so the admin area shows it and WHMCS passes it to
 * paymenthooks_cancelSubscription().
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class SubscriptionMap
{
    const TABLES = ['hosting' => 'tblhosting', 'domain' => 'tbldomains', 'addon' => 'tblhostingaddons'];

    public static function isSubscribed(string $relType, int $relId): bool
    {
        return Capsule::table('mod_paymenthooks_subscriptions')
            ->where('rel_type', $relType)
            ->where('rel_id', $relId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Binds every item the checkout subscribed (the plan stored with the
     * session) to the subscription. An item bound to an older subscription is
     * moved to the new one.
     *
     * @param array<int, array{relType:string, relId:int, renewalMinor:int, interval:string, intervalCount:int}> $items
     */
    public static function bind(string $subscriptionId, int $invoiceId, string $currency, array $items): void
    {
        $now = time();
        foreach ($items as $item) {
            $relType = (string) $item['relType'];
            if (!isset(self::TABLES[$relType])) {
                throw new \RuntimeException('Unknown subscribed item type "' . $relType . '".');
            }
            Capsule::table('mod_paymenthooks_subscriptions')
                ->where('rel_type', $relType)
                ->where('rel_id', (int) $item['relId'])
                ->where('status', 'active')
                ->update(['status' => 'replaced', 'updated_at' => $now]);
            Capsule::table('mod_paymenthooks_subscriptions')->insert([
                'subscription_id'  => $subscriptionId,
                'rel_type'         => $relType,
                'rel_id'           => (int) $item['relId'],
                'invoice_id'       => $invoiceId,
                'currency'         => strtoupper($currency),
                'amount_minor'     => (int) $item['renewalMinor'],
                'billing_interval' => (string) $item['interval'],
                'interval_count'   => (int) $item['intervalCount'],
                'status'           => 'active',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            self::mirror($relType, (int) $item['relId'], $subscriptionId);
        }
    }

    /** @return array<int, object> active rows of the subscription */
    public static function items(string $subscriptionId): array
    {
        return Capsule::table('mod_paymenthooks_subscriptions')
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Stops treating the items as auto-renewing: rows marked $status and the
     * mirrored ids cleared. Returns the items that were bound.
     *
     * @return array<int, object>
     */
    public static function unbind(string $subscriptionId, string $status = 'cancelled'): array
    {
        $items = self::items($subscriptionId);
        Capsule::table('mod_paymenthooks_subscriptions')
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->update(['status' => $status, 'updated_at' => time()]);
        foreach ($items as $item) {
            self::mirror((string) $item->rel_type, (int) $item->rel_id, '', $subscriptionId);
        }
        // Services bound by module versions before the subscriptions table.
        Capsule::table('tblhosting')->where('subscriptionid', $subscriptionId)->update(['subscriptionid' => '']);
        return $items;
    }

    /** Writes (or clears, when it still holds $only) the item's own subscriptionid column. */
    private static function mirror(string $relType, int $relId, string $value, string $only = ''): void
    {
        $table = self::TABLES[$relType];
        if ($relType !== 'hosting' && !Schema::hasSubscriptionColumn($table)) {
            return;
        }
        $q = Capsule::table($table)->where('id', $relId);
        if ($only !== '') {
            $q->where('subscriptionid', $only);
        }
        $q->update(['subscriptionid' => $value]);
    }

    /**
     * Pre-subscriptions-table binding (tblhosting.subscriptionid only), as
     * rows shaped like the table's, so renewals of existing subscriptions keep
     * working after the upgrade.
     *
     * @return array<int, object>
     */
    public static function legacyItems(string $subscriptionId): array
    {
        $out = [];
        foreach (Capsule::table('tblhosting')->where('subscriptionid', $subscriptionId)->pluck('id')->all() as $id) {
            $out[] = (object) ['rel_type' => 'hosting', 'rel_id' => (int) $id, 'amount_minor' => 0];
        }
        return $out;
    }
}
