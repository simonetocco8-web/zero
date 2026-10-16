<?php

namespace App\Models;

use App\Models\Concerns\ImmutableExchangeRecord;
use Illuminate\Database\Eloquent\Model;

class ExchangeEvent extends Model
{
    use ImmutableExchangeRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
