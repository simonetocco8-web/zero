<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exchange extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'payment_pending', 'delivery_method' => 'shipping'];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'ready_for_pickup_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function retailer()
    {
        return $this->belongsTo(Retailer::class);
    }
}
