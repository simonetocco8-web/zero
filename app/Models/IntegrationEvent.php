<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Enums\IntegrationEventStatus;
use Database\Factories\IntegrationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationEvent extends Model
{
    /** @use HasFactory<IntegrationEventFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected $fillable = ['provider', 'external_event_id', 'event_type', 'payload_hash', 'payload', 'status', 'attempts', 'received_at', 'processed_at', 'next_attempt_at', 'error_code'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'status' => IntegrationEventStatus::class,
            'attempts' => ExactIntegerCast::class,
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
