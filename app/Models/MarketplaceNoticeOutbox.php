<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceNoticeOutbox extends Model
{
    protected $table = 'marketplace_notice_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'database_enabled' => 'boolean', 'email_enabled' => 'boolean', 'database_delivered_at' => 'immutable_datetime', 'email_delivered_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'processing_at' => 'immutable_datetime', 'last_enqueued_at' => 'immutable_datetime'];
    }
}
