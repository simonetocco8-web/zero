<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('net_cents')->nullable();
        });
        DB::table('sale_items')->update(['net_cents' => DB::raw('line_total_cents - commission_cents')]);
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('pending_retailer_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN retailer_id ELSE NULL END");
            $table->unique(['pending_retailer_id', 'currency'], 'payout_one_pending_per_currency');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_financial_snapshot CHECK (discount_cents >= 0 AND (net_cents IS NULL OR net_cents = line_total_cents - commission_cents))');
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER sale_financial_snapshot_$operation BEFORE $operation ON sale_items WHEN NEW.discount_cents < 0 OR typeof(NEW.discount_cents) <> 'integer' OR (NEW.net_cents IS NOT NULL AND (typeof(NEW.net_cents) <> 'integer' OR NEW.net_cents <> NEW.line_total_cents - NEW.commission_cents)) BEGIN SELECT RAISE(ABORT, 'Invalid financial snapshot'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sale_items DROP CHECK sale_financial_snapshot');
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS sale_financial_snapshot_$operation");
            }
        }
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropUnique('payout_one_pending_per_currency');
            $table->dropColumn('pending_retailer_id');
        });
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['discount_cents', 'net_cents']));
    }
};
