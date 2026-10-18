<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->softDeletes();
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            }
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE inventory_items ADD COLUMN deleted_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
        }
    }

    public function down(): void {}
};
