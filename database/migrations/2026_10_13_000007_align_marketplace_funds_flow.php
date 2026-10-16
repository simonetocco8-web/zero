<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_orders', fn (Blueprint $t) => $t->json('funds_flow_snapshot')->nullable());
        Schema::table('exchange_difference_payments', fn (Blueprint $t) => $t->timestamp('expires_at')->nullable()->index());
        Schema::create('marketplace_return_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('vendor_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 32)->default('requested');
            $t->string('phase', 32);
            $t->text('reason');
            $t->text('notes')->nullable();
            $t->bigInteger('requested_amount_cents');
            $t->bigInteger('approved_amount_cents')->nullable();
            $t->json('goods_return_snapshot')->nullable();
            $t->json('financial_snapshot');
            $t->timestamps();
            $t->index(['vendor_order_id', 'status']);
        });
        Schema::create('marketplace_return_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('marketplace_return_case_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 48);
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
        Schema::create('marketplace_stock_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('marketplace_return_case_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_milliunits');
            $t->timestamp('created_at');
            $t->unique(['marketplace_return_case_id', 'order_item_id'], 'marketplace_stock_return_case_item');
        });
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->string('operation_kind', 48)->nullable();
            $t->string('entry_bucket', 16)->default('legacy');
            $t->boolean('simulated')->default(false);
            $t->json('operation_snapshot')->nullable();
        });
        // Native additive SQLite columns retain all existing append-only/validation triggers.
        foreach (['vendor_order_id' => 'vendor_orders', 'exchange_version_id' => 'exchange_versions', 'marketplace_return_case_id' => 'marketplace_return_cases', 'reverses_transaction_id' => 'wallet_transactions'] as $field => $table) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("ALTER TABLE wallet_transactions ADD COLUMN $field INTEGER NULL REFERENCES $table(id) ON DELETE RESTRICT");
            } else {
                Schema::table('wallet_transactions', fn (Blueprint $t) => $t->foreignId($field)->nullable()->constrained($table)->restrictOnDelete());
            }
        }
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->index(['vendor_order_id', 'operation_kind']);
            $t->index(['exchange_version_id', 'operation_kind']);
            $t->index(['retailer_id', 'simulated', 'entry_bucket', 'currency'], 'wallet_marketplace_bucket');
        });
        $rules = [
            'marketplace_return_cases' => ["phase IN ('before_dispatch','after_dispatch')", "status IN ('requested','approved','rejected','goods_pending','goods_received','refunded','closed')", 'requested_amount_cents >= 0 AND (approved_amount_cents IS NULL OR (approved_amount_cents >= 0 AND approved_amount_cents <= requested_amount_cents))'],
            'marketplace_stock_returns' => ['quantity_milliunits > 0'],
            'wallet_transactions' => ["entry_bucket IN ('legacy','pending','available','reserved')", '(vendor_order_id IS NULL OR exchange_version_id IS NULL)'],
        ];
        foreach ($rules as $table => $predicates) {
            foreach ($predicates as $i => $rule) {
                if (DB::getDriverName() === 'mysql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_funds_rule_$i CHECK ($rule)");
                } else {
                    $cols = implode('|', Schema::getColumnListing($table));
                    $rule = preg_replace('/\b('.$cols.')\b/', 'NEW.$1', $rule);
                    foreach (['INSERT', 'UPDATE'] as $op) {
                        DB::unprepared("CREATE TRIGGER {$table}_funds_rule_{$i}_$op BEFORE $op ON $table WHEN NOT ($rule) BEGIN SELECT RAISE(ABORT,'Invalid marketplace funds record'); END");
                    }
                }
            }
        }
        foreach (['marketplace_return_events', 'marketplace_stock_returns', 'sale_items'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable marketplace audit'");
                } else {
                    DB::unprepared("CREATE TRIGGER {$table}_immutable_$op BEFORE $op ON $table BEGIN SELECT RAISE(ABORT,'Immutable marketplace audit'); END");
                }
            }
        }
        $invalid = '(NEW.marketplace_return_case_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM marketplace_return_cases c JOIN vendor_orders v ON v.id=c.vendor_order_id WHERE c.id=NEW.marketplace_return_case_id AND v.retailer_id=NEW.retailer_id AND v.id=NEW.vendor_order_id)) OR (NEW.vendor_order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM vendor_orders v WHERE v.id=NEW.vendor_order_id AND v.retailer_id=NEW.retailer_id AND v.currency=NEW.currency)) OR (NEW.exchange_version_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM exchange_versions v WHERE v.id=NEW.exchange_version_id AND v.payee_retailer_id=NEW.retailer_id AND v.currency=NEW.currency)) OR (NEW.reverses_transaction_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM wallet_transactions w WHERE w.id=NEW.reverses_transaction_id AND w.retailer_id=NEW.retailer_id AND w.currency=NEW.currency AND w.simulated=NEW.simulated))';
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER wallet_marketplace_owner_INSERT BEFORE INSERT ON wallet_transactions FOR EACH ROW BEGIN IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid marketplace ledger owner'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER wallet_marketplace_owner_INSERT BEFORE INSERT ON wallet_transactions WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid marketplace ledger owner'); END");
        }
        $invalid = 'NOT EXISTS (SELECT 1 FROM marketplace_return_cases c JOIN order_items i ON i.vendor_order_id=c.vendor_order_id WHERE c.id=NEW.marketplace_return_case_id AND i.id=NEW.order_item_id AND NEW.quantity_milliunits<=i.quantity_milliunits)';
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER marketplace_stock_return_owner BEFORE INSERT ON marketplace_stock_returns FOR EACH ROW BEGIN IF $invalid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid stock return owner'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER marketplace_stock_return_owner BEFORE INSERT ON marketplace_stock_returns WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid stock return owner'); END");
        }

    }

    public function down(): void
    {
        throw new RuntimeException('Financial histories are retained; rollback application code only.');
    }
};
