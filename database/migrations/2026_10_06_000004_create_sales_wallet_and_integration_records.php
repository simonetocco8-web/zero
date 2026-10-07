<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $this->identifier($table, 'provider');
            $this->identifier($table, 'external_event_id');
            $table->string('event_type', 120);
            $table->char('payload_hash', 64);
            $table->longText('payload')->nullable();
            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_event_id']);
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $this->identifier($table, 'provider');
            $this->identifier($table, 'external_order_id');
            $table->enum('status', ['pending', 'paid', 'cancelled', 'partially_refunded', 'refunded'])->default('pending');
            $this->currency($table);
            $table->bigInteger('total_cents');
            $table->string('customer_email')->nullable();
            $table->timestamp('ordered_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_order_id']);
            $table->unique(['id', 'currency']);
            $table->index(['status', 'ordered_at']);
        });
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $this->identifier($table, 'external_line_id');
            $table->string('name');
            $table->string('sku', 191)->nullable();
            $table->string('unit', 32)->default('piece');
            $table->bigInteger('quantity_milliunits');
            $this->currency($table);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->integer('commission_basis_points');
            $table->bigInteger('commission_cents');
            $table->timestamps();
            $table->foreign(['sale_id', 'currency'])->references(['id', 'currency'])->on('sales')->restrictOnDelete();
            $table->foreign(['inventory_item_id', 'retailer_id'], 'sale_item_inventory_owner_fk')
                ->references(['id', 'retailer_id'])->on('inventory_items')->restrictOnDelete();
            $table->unique(['sale_id', 'external_line_id']);
            $table->unique(['id', 'retailer_id', 'currency']);
            $table->index(['retailer_id', 'created_at']);
        });
        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $this->currency($table);
            $table->bigInteger('amount_cents');
            $table->enum('status', ['pending', 'paid', 'rejected'])->default('pending');
            $table->text('iban')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'retailer_id', 'currency']);
            $table->index(['retailer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_item_id')->nullable();
            $table->unsignedBigInteger('payout_request_id')->nullable();
            $table->foreignId('integration_event_id')->nullable()->constrained()->restrictOnDelete();
            $this->identifier($table, 'idempotency_key');
            $table->enum('type', ['sale_credit', 'commission', 'refund', 'payout_reservation', 'payout_release', 'payout', 'payout_reversal', 'adjustment']);
            $this->currency($table);
            $table->bigInteger('amount_cents');
            $table->string('description')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
            $table->foreign(['sale_item_id', 'retailer_id', 'currency'], 'wallet_sale_item_owner_fk')
                ->references(['id', 'retailer_id', 'currency'])->on('sale_items')->restrictOnDelete();
            $table->foreign(['payout_request_id', 'retailer_id', 'currency'], 'wallet_payout_owner_fk')
                ->references(['id', 'retailer_id', 'currency'])->on('payout_requests')->restrictOnDelete();
            $table->unique('idempotency_key');
            $table->unique(['payout_request_id', 'type']);
            $table->index(['retailer_id', 'currency', 'available_at']);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['retailer_approved', 'retailer_rejected', 'retailer_suspended', 'inventory_approved', 'inventory_rejected', 'inventory_published', 'payout_paid', 'payout_rejected']);
            $table->morphs('subject');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamps();
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

    }

    public function down(): void
    {

        foreach (['audit_logs', 'wallet_transactions', 'payout_requests', 'sale_items', 'sales', 'integration_events'] as $table) {
            Schema::dropIfExists($table);
        }

    }

    private function currency(Blueprint $table): void
    {
        $column = $table->char('currency', 3)->default('EUR');
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }

    private function identifier(Blueprint $table, string $name, bool $nullable = false): void
    {
        $column = $table->string($name, 191)->nullable($nullable);
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }
};
