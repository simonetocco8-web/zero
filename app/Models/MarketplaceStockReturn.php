<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class MarketplaceStockReturn extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_milliunits' => ExactIntegerCast::class];
    }
}
