<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class MarketplaceReturnEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
