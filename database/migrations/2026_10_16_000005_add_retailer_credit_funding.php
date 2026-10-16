<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_credit_holds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('exchange_version_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency', 3)->default('EUR');
            $t->boolean('simulated');
            $t->string('status')->default('reserved');
            $t->timestamps();
            $t->index(['retailer_id', 'status']);
        });
        if (DB::getDriverName() === 'sqlite') {
            // Native additive ALTER preserves all existing append-only/sign/ownership triggers.
            DB::statement('ALTER TABLE wallet_transactions ADD COLUMN retailer_credit_hold_id INTEGER NULL REFERENCES retailer_credit_holds(id) ON DELETE RESTRICT');
        } else {
            Schema::table('wallet_transactions', fn (Blueprint $t) => $t->foreignId('retailer_credit_hold_id')->nullable()->constrained()->restrictOnDelete());
        }
        Schema::table('wallet_transactions', fn (Blueprint $t) => $t->index(['retailer_credit_hold_id', 'operation_kind'], 'wallet_credit_hold_kind'));
        Schema::table('marketplace_payments', fn (Blueprint $t) => $t->unsignedBigInteger('retailer_credit_cents')->default(0));
        Schema::table('exchange_difference_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('retailer_credit_cents')->default(0);
            $t->unsignedBigInteger('card_cents')->nullable();
            $t->json('funding_snapshot')->nullable();
        });
        if (DB::getDriverName() === 'mysql') {
            // Preserve all historical amounts; extend the existing invariant for a third funding source.
            DB::statement('ALTER TABLE marketplace_payments DROP CHECK marketplace_funding_valid, ADD CONSTRAINT marketplace_funding_valid CHECK (bonus_cents + retailer_credit_cents <= amount_cents AND (card_cents IS NULL OR card_cents + bonus_cents + retailer_credit_cents = amount_cents))');
            DB::statement('ALTER TABLE exchange_difference_payments ADD CONSTRAINT exchange_credit_funding_valid CHECK (retailer_credit_cents <= amount_cents AND (card_cents IS NULL OR card_cents + retailer_credit_cents = amount_cents))');
        }
        $invalid = "(NEW.order_id IS NULL) = (NEW.exchange_version_id IS NULL) OR NEW.amount_cents <= 0 OR NEW.currency <> 'EUR' OR NOT EXISTS (SELECT 1 FROM retailers r WHERE r.id = NEW.retailer_id AND ((NEW.order_id IS NOT NULL AND EXISTS (SELECT 1 FROM orders o WHERE o.id=NEW.order_id AND o.user_id=r.user_id)) OR (NEW.exchange_version_id IS NOT NULL AND EXISTS (SELECT 1 FROM exchange_versions v WHERE v.id=NEW.exchange_version_id AND v.payer_retailer_id=r.id))))";
        $walletInvalid = 'NEW.retailer_credit_hold_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM retailer_credit_holds h WHERE h.id=NEW.retailer_credit_hold_id AND h.retailer_id=NEW.retailer_id AND h.currency=NEW.currency AND h.simulated=NEW.simulated)';
        foreach (['retailer_credit_holds' => $invalid, 'wallet_transactions' => $walletInvalid] as $table => $condition) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$table}_credit_owner BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid retailer credit owner'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_credit_owner BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid retailer credit owner'); END");
            }
        }
        foreach (['marketplace_payments', 'exchange_difference_payments'] as $table) {
            $bonus = $table === 'marketplace_payments' ? 'NEW.bonus_cents' : '0';
            $invalidFunding = "NEW.retailer_credit_cents < 0 OR NEW.retailer_credit_cents + {$bonus} > NEW.amount_cents OR (NEW.card_cents IS NULL AND NEW.retailer_credit_cents > 0) OR (NEW.card_cents IS NOT NULL AND NEW.card_cents + NEW.retailer_credit_cents + {$bonus} <> NEW.amount_cents)";
            $fields = ['amount_cents', 'currency', 'retailer_credit_cents', 'card_cents', 'funding_snapshot'];
            if ($table === 'marketplace_payments') {
                $fields[] = 'bonus_cents';
            }
            $immutable = implode(' OR ', array_map(fn ($field) => DB::getDriverName() === 'mysql' ? "NOT (NEW.{$field} <=> OLD.{$field})" : "NEW.{$field} IS NOT OLD.{$field}", $fields));
            // Preserve pre-existing payments with null historical card amounts; funding is fixed on insertion.
            $this->guard($table.'_credit_funding_insert', $table, 'INSERT', $invalidFunding);
            $this->guard($table.'_credit_funding_update', $table, 'UPDATE', $immutable);
        }
        $holdFields = ['retailer_id', 'order_id', 'exchange_version_id', 'amount_cents', 'currency', 'simulated', 'created_at'];
        $immutable = implode(' OR ', array_map(fn ($field) => DB::getDriverName() === 'mysql' ? "NOT (NEW.{$field} <=> OLD.{$field})" : "NEW.{$field} IS NOT OLD.{$field}", $holdFields));
        $this->guard('credit_hold_update', 'retailer_credit_holds', 'UPDATE', $immutable." OR OLD.status <> 'reserved' OR NEW.status NOT IN ('spent','released')");
        $this->guard('credit_hold_delete', 'retailer_credit_holds', 'DELETE', '1=1');

    }

    private function guard(string $name, string $table, string $operation, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable credit funding'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Immutable credit funding'); END");
        }
    }

    public function down(): void
    {
        // Financial history intentionally retained. No automatic rollback.
    }
};
