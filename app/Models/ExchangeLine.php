<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Models\Concerns\ImmutableExchangeRecord;
use Illuminate\Database\Eloquent\Model;

class ExchangeLine extends Model
{
    use ImmutableExchangeRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_milliunits' => ExactIntegerCast::class, 'agreed_unit_value_cents' => ExactIntegerCast::class, 'goods_value_cents' => ExactIntegerCast::class, 'product_snapshot' => 'array'];
    }
}
