<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailerBankVerification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['iban', 'iban_fingerprint', 'company_name_checked', 'vop_owner_name'];

    protected function casts(): array
    {
        return ['iban' => 'encrypted', 'company_name_checked' => 'encrypted', 'vop_owner_name' => 'encrypted', 'bank_data' => 'array', 'sepa_data' => 'array', 'advanced_attempts' => 'integer', 'vop_attempts' => 'integer', 'started_at' => 'immutable_datetime', 'iban_verified_at' => 'immutable_datetime', 'vop_verified_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }
}
