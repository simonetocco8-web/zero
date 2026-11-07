<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingQuote extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['request_snapshot'];

    protected function casts(): array
    {
        return ['request_snapshot' => 'encrypted:array', 'rate' => 'array', 'expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }
}
