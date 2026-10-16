<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $triggers = DB::getDriverName() === 'sqlite' ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'") : [];
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status', 40)->default('payment_pending')->change();
        });
        DB::table('sales')->where('status', 'pending')->update(['status' => 'payment_pending']);
        Schema::create('vendor_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['payment_pending', 'paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled', 'refunded', 'partially_refunded'])->default('payment_pending');
            $table->enum('fulfillment_status', ['payment_pending', 'paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled'])->default('payment_pending');
            $table->enum('delivery_method', ['shipping', 'pickup'])->default('shipping');
            $table->enum('stock_rule', ['external', 'deduct_only', 'restore_on_full_refund'])->default('external');
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_code', 191)->nullable();
            foreach (['shipped_at', 'ready_for_pickup_at', 'completed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->timestamps();
            $table->unique(['sale_id', 'retailer_id']);
            $table->unique(['id', 'sale_id', 'retailer_id']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_order_id')->nullable();
            $table->foreign(['vendor_order_id', 'sale_id', 'retailer_id'], 'sale_item_vendor_owner_fk')->references(['id', 'sale_id', 'retailer_id'])->on('vendor_orders')->restrictOnDelete();
            $table->bigInteger('stock_deducted_milliunits')->default(0);
            $table->bigInteger('stock_restored_milliunits')->default(0);
        });
        foreach (DB::table('sales')->orderBy('id')->get() as $sale) {
            foreach (DB::table('sale_items')->where('sale_id', $sale->id)->distinct()->pluck('retailer_id') as $retailer) {
                $lineIds = DB::table('sale_items')->where('sale_id', $sale->id)->where('retailer_id', $retailer)->pluck('id');
                $gross = (int) DB::table('sale_items')->whereIn('id', $lineIds)->sum('line_total_cents');
                $refunded = -(int) DB::table('wallet_transactions')->whereIn('sale_item_id', $lineIds)->where('type', 'refund')->sum('amount_cents');
                $status = $refunded > 0 ? ($refunded === $gross ? 'refunded' : 'partially_refunded') : ($sale->paid_at ? 'paid' : $sale->status);
                $id = DB::table('vendor_orders')->insertGetId(['sale_id' => $sale->id, 'retailer_id' => $retailer, 'status' => $status, 'fulfillment_status' => $sale->paid_at ? 'paid' : 'payment_pending', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('sale_items')->where('sale_id', $sale->id)->where('retailer_id', $retailer)->update(['vendor_order_id' => $id]);
            }
        }
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_retailer_id')->constrained('retailers')->restrictOnDelete();
            $provider = $table->string('provider', 100);
            $externalId = $table->string('external_exchange_id', 191);
            if (DB::getDriverName() === 'mysql') {
                $provider->collation('utf8mb4_bin');
                $externalId->collation('utf8mb4_bin');
            }
            $table->string('payment_reference', 191)->nullable();
            $table->enum('status', ['payment_pending', 'confirmed', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled'])->default('payment_pending');
            $table->enum('delivery_method', ['shipping', 'pickup'])->default('shipping');
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_code', 191)->nullable();
            foreach (['shipped_at', 'ready_for_pickup_at', 'completed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->timestamps();
            $table->unique(['provider', 'external_exchange_id']);
        });
        Schema::create('lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        foreach ($triggers as $trigger) {
            if (! DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$trigger->name])) {
                DB::unprepared($trigger->sql);
            }
        }
        foreach (['UPDATE', 'DELETE'] as $operation) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER lifecycle_events_immutable_$operation BEFORE $operation ON lifecycle_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only record'");
            } else {
                DB::unprepared("CREATE TRIGGER lifecycle_events_immutable_$operation BEFORE $operation ON lifecycle_events BEGIN SELECT RAISE(ABORT, 'Append-only record'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('lifecycle_events');
        Schema::dropIfExists('exchanges');
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign('sale_item_vendor_owner_fk');
            $table->dropColumn('vendor_order_id');
            $table->dropColumn(['stock_deducted_milliunits', 'stock_restored_milliunits']);
        });
        Schema::dropIfExists('vendor_orders');
    }
};
