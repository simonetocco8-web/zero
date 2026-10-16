<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->unsignedBigInteger('stock_version')->default(0);
            $t->unsignedBigInteger('proposed_stock_version')->nullable();
        });
        // Existing proposals refer to the physical stock at migration time. No quantity is changed.
        DB::table('inventory_items')->whereNotNull('proposed_data')->update(['proposed_stock_version' => 0]);
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            // Reserved extension point: no exchange domain or reservation API exists yet.
            $t->unsignedBigInteger('exchange_id')->nullable();
            $t->bigInteger('quantity_milliunits');
            $t->string('status', 16)->default('active');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('idempotency_key', 191)->unique();
            $t->timestamps();
            $t->unique(['order_id', 'inventory_item_id']);
            $t->index(['inventory_item_id', 'status', 'expires_at'], 'stock_item_active_expiry');
            $t->index(['status', 'expires_at', 'order_id'], 'stock_expiry_scan');
        });
        Schema::create('marketplace_payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('provider', 32);
            $t->string('event_id', 191);
            $t->char('payload_hash', 64);
            $t->string('outcome', 32);
            $t->timestamps();
            $t->unique(['provider', 'event_id']);
        });
        $rules = [
            'quantity_milliunits > 0',
            "status IN ('active', 'consumed', 'released', 'expired')",
            "(status = 'active' AND consumed_at IS NULL AND released_at IS NULL) OR (status = 'consumed' AND consumed_at IS NOT NULL AND released_at IS NULL) OR (status IN ('released', 'expired') AND released_at IS NOT NULL AND consumed_at IS NULL)",
            'order_id IS NULL OR exchange_id IS NULL',
        ];
        if (DB::getDriverName() === 'mysql') {
            foreach ($rules as $i => $rule) {
                DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservation_rule_$i CHECK ($rule)");
            }
        } else {
            $columns = implode('|', Schema::getColumnListing('stock_reservations'));
            $rules = array_map(fn ($rule) => preg_replace('/\b('.$columns.')\b/', 'NEW.$1', $rule), $rules);
            $rules[] = "typeof(NEW.quantity_milliunits) = 'integer'";
            $invalid = implode(' OR ', array_map(fn ($rule) => "NOT ($rule)", $rules));
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER stock_reservations_validate_$operation BEFORE $operation ON stock_reservations WHEN $invalid BEGIN SELECT RAISE(ABORT, 'Invalid stock reservation'); END");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Reservations and payment receipts must be retained. Roll back application code only.');
    }
};
