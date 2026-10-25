<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class RetailerBankVerificationEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
