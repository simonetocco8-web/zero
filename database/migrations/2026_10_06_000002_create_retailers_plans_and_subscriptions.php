<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('retailers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('company_name');
            $table->string('vat_number', 32)->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('city');
            $table->string('province', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->string('website', 2048)->nullable();
            $table->text('iban')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $this->currency($table);
            $table->bigInteger('monthly_price_cents');
            $table->bigInteger('annual_price_cents');
            $table->integer('max_items')->nullable();
            $table->bigInteger('max_inventory_value_cents')->nullable();
            $table->boolean('exchange_available');
            $table->integer('commission_basis_points');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'active', 'cancelled', 'expired'])->default('pending');
            $table->enum('billing_interval', ['monthly', 'yearly'])->default('monthly');
            $this->currency($table);
            $table->bigInteger('price_cents');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('active_retailer_id')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN retailer_id ELSE NULL END")->unique();
            $table->timestamps();
            $table->index(['retailer_id', 'status']);
        });

    }

    public function down(): void
    {

        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('retailers');

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
