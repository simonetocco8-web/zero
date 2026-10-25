<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_bank_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->text('iban');
            $table->string('iban_fingerprint', 64);
            $table->string('iban_last4', 4);
            $table->text('company_name_checked');
            $table->string('status', 24)->default('pending');
            $table->string('iban_validation_status', 24)->default('pending');
            foreach (['advanced', 'vop'] as $phase) {
                $table->string($phase.'_request_id', 128)->nullable();
                $table->string($phase.'_state', 24)->default('PENDING');
                $table->unsignedSmallInteger($phase.'_attempts')->default(0);
            }
            $table->json('bank_data')->nullable();
            $table->json('sepa_data')->nullable();
            $table->string('vop_result', 24)->nullable();
            $table->string('vop_match_type', 8)->nullable();
            $table->text('vop_owner_name')->nullable();
            $table->string('error_code', 40)->nullable();
            foreach (['started_at', 'iban_verified_at', 'vop_verified_at', 'failed_at', 'processing_at', 'next_attempt_at', 'expires_at'] as $date) {
                $table->timestamp($date)->nullable();
            }
            $table->timestamps();
            $table->index(['retailer_id', 'id']);
            $table->index(['retailer_id', 'iban_fingerprint', 'iban_validation_status'], 'bank_verification_account_index');
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('retailer_bank_verification_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('retailer_bank_verification_id');
            $table->foreign('retailer_bank_verification_id', 'bank_verification_event_fk')->references('id')->on('retailer_bank_verifications')->restrictOnDelete();
            $table->string('event', 40);
            $table->json('context');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retailer_bank_verification_events');
        Schema::dropIfExists('retailer_bank_verifications');
    }
};
