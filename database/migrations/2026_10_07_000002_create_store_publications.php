<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', fn (Blueprint $t) => $t->timestamp('submitted_at')->nullable()->index());
        Schema::create('store_publications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('operation', 20);
            $t->string('status', 20)->default('pending');
            $t->json('payload');
            $t->json('result')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamp('processing_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('fake_store_products', function (Blueprint $t) {
            $t->string('id', 100)->primary();
            $t->unsignedBigInteger('revision');
            $t->json('payload');
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_store_products');
        Schema::dropIfExists('store_publications');
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->dropIndex(['submitted_at']);
            $t->dropColumn('submitted_at');
        });
    }
};
