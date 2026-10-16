<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->unique(['id', 'user_id'], 'orders_bonus_owner_unique'));
        Schema::create('customer_bonus_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->string('campaign');
            $t->string('idempotency_key')->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency', 3)->default('EUR');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['id', 'user_id']);
            $t->index(['user_id', 'expires_at']);
        });
        Schema::create('customer_bonus_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('grant_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('idempotency_key')->unique();
            $t->string('kind');
            $t->string('bucket');
            $t->bigInteger('amount_cents');
            $t->timestamp('created_at');
            $t->foreign(['grant_id', 'user_id'])->references(['id', 'user_id'])->on('customer_bonus_grants')->restrictOnDelete();
            $t->foreign(['order_id', 'user_id'])->references(['id', 'user_id'])->on('orders')->restrictOnDelete();
            $t->index(['order_id', 'kind']);
        });
        Schema::table('marketplace_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('bonus_cents')->default(0);
            $t->unsignedBigInteger('card_cents')->nullable();
            $t->json('funding_snapshot')->nullable();
        });
        foreach (['customer_bonus_grants', 'customer_bonus_entries'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = $table.'_'.$operation.'_guard';
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Bonus history is append-only'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bonus history is append-only'");
                }
            }
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_bonus_grants ADD CONSTRAINT bonus_grant_positive CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE customer_bonus_entries ADD CONSTRAINT bonus_entry_valid CHECK (amount_cents <> 0 AND bucket IN ('available','reserved','spent') AND kind IN ('grant','purchase_reservation','purchase_spend','purchase_release','refund_credit'))");
            DB::statement('ALTER TABLE marketplace_payments ADD CONSTRAINT marketplace_funding_valid CHECK (bonus_cents <= amount_cents AND (card_cents IS NULL OR card_cents + bonus_cents = amount_cents))');
        }
    }

    public function down(): void
    {
        // Financial history intentionally retained.
    }
};
