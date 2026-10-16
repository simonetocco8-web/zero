<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->index(['status', 'published_at', 'id'], 'marketplace_recent');
            $t->index(['status', 'zero_price_cents', 'id'], 'marketplace_price');
            $t->index(['category_id', 'status', 'zero_price_cents', 'id'], 'marketplace_category_price');
            $t->index(['brand', 'status'], 'marketplace_brand');
            $t->index('ean', 'marketplace_ean');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            foreach (['marketplace_recent', 'marketplace_price', 'marketplace_category_price', 'marketplace_brand', 'marketplace_ean'] as $name) {
                $t->dropIndex($name);
            }
        });
    }
};
