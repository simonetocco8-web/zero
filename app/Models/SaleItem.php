<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Models\Concerns\HasExactQuantity;
use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasExactQuantity;

    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    protected $attributes = ['unit' => 'piece', 'currency' => 'EUR'];

    protected $fillable = ['sale_id', 'inventory_item_id', 'retailer_id', 'external_line_id', 'name', 'sku', 'unit', 'quantity', 'quantity_milliunits', 'currency', 'unit_price_cents', 'line_total_cents', 'commission_basis_points', 'commission_cents'];

    protected function casts(): array
    {
        return [
            'quantity_milliunits' => ExactIntegerCast::class,
            'currency' => CurrencyCode::class,
            'unit_price_cents' => ExactIntegerCast::class,
            'line_total_cents' => ExactIntegerCast::class,
            'commission_basis_points' => ExactIntegerCast::class,
            'commission_cents' => ExactIntegerCast::class,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
