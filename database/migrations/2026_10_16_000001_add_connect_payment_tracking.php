<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_connected_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('retailer_id')->unique()->constrained()->restrictOnDelete();
            $t->string('stripe_account_id')->unique();
            $t->boolean('charges_enabled')->default(false);
            $t->boolean('payouts_enabled')->default(false);
            $t->boolean('transfers_active')->default(false);
            $t->boolean('details_submitted')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        foreach (['marketplace_payments', 'exchange_difference_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('checkout_url')->nullable();
                $t->string('stripe_payment_intent_id')->nullable()->unique();
                $t->string('stripe_charge_id')->nullable()->index();
            });
        }
        Schema::create('marketplace_stripe_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->string('event_type');
            $t->string('object_id');
            $t->char('payload_hash', 64);
            $t->string('outcome');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Additive migration intentionally retains payment audit and existing data.
    }
};
