<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $t->string('billing_interval', 16);
            $t->string('price_id', 191);
            $t->bigInteger('price_cents');
            $t->string('status', 20)->default('creating');
            $identifier = $t->string('checkout_session_id', 191)->nullable()->unique();
            if (DB::getDriverName() === 'mysql') {
                $identifier->collation('utf8mb4_bin');
            }
            $t->text('checkout_url')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('completed_at')->nullable();
            $t->string('error_code', 100)->nullable();
            $t->unsignedBigInteger('open_retailer_id')->nullable()->storedAs("CASE WHEN status IN ('creating','open') THEN retailer_id ELSE NULL END")->unique();
            $t->timestamps();
            $t->index(['retailer_id', 'created_at']);
        });
        Schema::create('billing_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('billing_checkout_id')->constrained('billing_checkouts')->restrictOnDelete();
            $t->string('provider', 32)->default('stripe');
            $identifier = $t->string('external_subscription_id', 191);
            if (DB::getDriverName() === 'mysql') {
                $identifier->collation('utf8mb4_bin');
            }$t->string('external_customer_id', 191);
            $t->string('status', 32);
            $t->boolean('invoice_paid')->default(false);
            $t->string('price_id', 191);
            $t->string('billing_interval', 16);
            $t->bigInteger('price_cents');
            $t->timestamp('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->unsignedBigInteger('last_event_created')->default(0);
            $t->timestamps();
            $t->unique(['provider', 'external_subscription_id']);
            $t->index(['retailer_id', 'status']);
        });
        Schema::table('fake_store_products', fn (Blueprint $t) => $t->boolean('archived')->default(false));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->foreignId('billing_subscription_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('integration_events', function (Blueprint $t) {
            $t->timestamp('processing_at')->nullable();
            $t->index(['status', 'processing_at']);
        });
    }

    public function down(): void
    {
        Schema::table('fake_store_products', fn (Blueprint $t) => $t->dropColumn('archived'));
        Schema::table('integration_events', function (Blueprint $t) {
            $t->dropIndex(['status', 'processing_at']);
            $t->dropColumn('processing_at');
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropForeign(['billing_subscription_id']);
            $t->dropColumn('billing_subscription_id');
        });
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_checkouts');
    }
};
