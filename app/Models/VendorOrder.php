<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'ready_for_pickup_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function retailer()
    {
        return $this->belongsTo(Retailer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }
}
