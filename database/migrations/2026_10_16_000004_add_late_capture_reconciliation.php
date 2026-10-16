<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_late_capture_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('exchange_version_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('payment_intent_id')->unique();
            $t->string('idempotency_key')->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency', 3);
            $t->string('stripe_refund_id')->nullable()->unique();
            $t->string('status')->default('requested')->index();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE marketplace_late_capture_refunds ADD CONSTRAINT late_refund_one_source CHECK ((order_id IS NULL) <> (exchange_version_id IS NULL))');
            DB::statement('ALTER TABLE marketplace_late_capture_refunds ADD CONSTRAINT late_refund_positive CHECK (amount_cents > 0)');
        }
        foreach (['marketplace_provider_refunds' => ['vendor_order_id', 'marketplace_return_case_id', 'requested_by', 'idempotency_key', 'payment_intent_id', 'card_cents', 'bonus_cents', 'currency', 'created_at'], 'marketplace_late_capture_refunds' => ['order_id', 'exchange_version_id', 'payment_intent_id', 'idempotency_key', 'amount_cents', 'currency', 'created_at']] as $table => $columns) {
            if (DB::getDriverName() === 'sqlite') {
                $changed = implode(' OR ', array_map(fn ($c) => "NEW.$c IS NOT OLD.$c", $columns));
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON {$table} WHEN {$changed} BEGIN SELECT RAISE(ABORT, 'Refund financial snapshot is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Refund history is immutable'); END");
            } else {
                $changed = implode(' OR ', array_map(fn ($c) => "NOT (NEW.$c <=> OLD.$c)", $columns));
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund financial snapshot is immutable'; END IF; END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund history is immutable'");
            }
        }
    }

    public function down(): void {}
};
