<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_import_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->json('settings');
            $table->timestamps();
            $table->index(['retailer_id', 'name']);
        });
        Schema::create('inventory_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->unsignedInteger('size');
            $table->char('file_hash', 64);
            $table->char('operation_hash', 64)->nullable();
            $table->char('preview_token', 64)->nullable();
            $table->string('status', 32)->default('uploaded');
            $table->json('settings');
            $table->json('headers');
            $table->json('summary')->nullable();
            $table->string('intent', 16)->nullable();
            $table->unsignedInteger('total_rows');
            $table->timestamp('heartbeat_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->unique(['retailer_id', 'operation_hash']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('inventory_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('source_line');
            $table->json('cells')->nullable();
            $table->json('payload')->nullable();
            $table->string('sku', 191)->nullable();
            $table->string('name')->nullable();
            $table->string('status', 24)->default('unvalidated');
            $table->json('errors')->nullable();
            $table->foreignId('target_id')->nullable()->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['inventory_import_id', 'position']);
            $table->index(['inventory_import_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_import_rows');
        Schema::dropIfExists('inventory_imports');
        Schema::dropIfExists('inventory_import_templates');
    }
};
