<?php
/**
 * Creates the module's support tables on first use. No separate install step.
 *
 *   mod_paymenthooks_events    - seen webhook event ids (duplicate guard)
 *   mod_paymenthooks_sessions  - invoice -> checkout session / payment id mapping,
 *                                plus the convenience fee added to the session
 *   mod_paymenthooks_price_map - WHMCS product + billing cycle -> PH price id
 *   mod_paymenthooks_install   - one random id per install (idempotency keys)
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

    /**
     * Random id created with the tables, so a reinstall (fresh tables) never
     * reuses an earlier install's idempotency keys.
     */
    public static function installId(): string
    {
        self::ensure();
        $id = (string) Capsule::table('mod_paymenthooks_install')->value('install_id');
        if ($id !== '') {
            return $id;
        }
        $id = bin2hex(random_bytes(8));
        try {
            Capsule::table('mod_paymenthooks_install')->insert(['install_id' => $id, 'created_at' => time()]);
        } catch (\Throwable $e) {
            // Another request created it first; use that one.
        }
        $stored = (string) Capsule::table('mod_paymenthooks_install')->orderBy('created_at')->value('install_id');
        if ($stored === '') {
            throw new \RuntimeException('Could not store the PaymentHooks install id.');
        }
        return $stored;
    }

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
                $t->string('status', 20)->default('processing');
                $t->integer('received_at');
            });
        } elseif (!$sm->hasColumn('mod_paymenthooks_events', 'status')) {
            // Rows from earlier versions were fully processed.
            $sm->table('mod_paymenthooks_events', function ($t) {
                $t->string('status', 20)->default('done');
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
                $t->integer('fee_minor')->default(0);
                $t->integer('created_at');
                $t->integer('updated_at');
            });
        } elseif (!$sm->hasColumn('mod_paymenthooks_sessions', 'fee_minor')) {
            $sm->table('mod_paymenthooks_sessions', function ($t) {
                $t->integer('fee_minor')->default(0);
            });
        }

        if (!$sm->hasTable('mod_paymenthooks_install')) {
            $sm->create('mod_paymenthooks_install', function ($t) {
                $t->string('install_id', 32)->primary();
                $t->integer('created_at');
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
