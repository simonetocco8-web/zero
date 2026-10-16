<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_provider_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_order_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('marketplace_return_case_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key')->unique();
            $t->string('stripe_refund_id')->nullable()->unique();
            $t->string('payment_intent_id');
            $t->string('status')->default('requested')->index();
            $t->unsignedBigInteger('card_cents');
            $t->unsignedBigInteger('bonus_cents');
            $t->string('currency', 3);
            $t->timestamps();
        });
    }

    public function down(): void {}
};
