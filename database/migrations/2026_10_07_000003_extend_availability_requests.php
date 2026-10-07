<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_requests', function (Blueprint $t) {
            $t->string('customer_company')->nullable();
            $t->unsignedBigInteger('quantity_milliunits')->nullable();
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->string('privacy_policy_version', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('availability_requests', fn (Blueprint $t) => $t->dropColumn(['customer_company', 'quantity_milliunits', 'privacy_accepted_at', 'privacy_policy_version']));
    }
};
