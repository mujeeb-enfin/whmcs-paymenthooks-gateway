<?php
/**
 * Creates the module's support tables on first use. No separate install step.
 *
 *   mod_paymenthooks_events    - seen webhook event ids (duplicate guard)
 *   mod_paymenthooks_sessions  - invoice -> checkout session / payment id mapping
 *   mod_paymenthooks_price_map - WHMCS product + billing cycle -> PH price id
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class Schema
{
    /** @var bool */
    private static $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        $sm = Capsule::schema();

        if (!$sm->hasTable('mod_paymenthooks_events')) {
            $sm->create('mod_paymenthooks_events', function ($t) {
                $t->string('event_id', 191)->primary();
                $t->string('type', 100)->nullable();
                $t->integer('received_at');
            });
        }

        if (!$sm->hasTable('mod_paymenthooks_sessions')) {
            $sm->create('mod_paymenthooks_sessions', function ($t) {
                $t->increments('id');
                $t->integer('invoice_id')->index();
                $t->string('session_id', 191)->nullable();
                $t->string('payment_id', 191)->nullable()->index();
                $t->string('subscription_id', 191)->nullable()->index();
                $t->string('status', 40)->default('open');
                $t->integer('created_at');
                $t->integer('updated_at');
            });
        }

        if (!$sm->hasTable('mod_paymenthooks_price_map')) {
            $sm->create('mod_paymenthooks_price_map', function ($t) {
                $t->increments('id');
                $t->integer('product_id')->index();
                $t->string('billing_cycle', 40);
                $t->string('currency', 10)->default('');
                $t->integer('amount_minor')->default(0);
                $t->string('ph_product_id', 191)->nullable();
                $t->string('ph_price_id', 191);
                $t->integer('created_at');
            });
        } else {
            // Upgrade path: add columns introduced after the first release.
            $sm->table('mod_paymenthooks_price_map', function ($t) use ($sm) {
                if (!$sm->hasColumn('mod_paymenthooks_price_map', 'currency')) {
                    $t->string('currency', 10)->default('');
                }
                if (!$sm->hasColumn('mod_paymenthooks_price_map', 'amount_minor')) {
                    $t->integer('amount_minor')->default(0);
                }
            });
        }
    }
}
