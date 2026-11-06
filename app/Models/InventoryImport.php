<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'headers' => 'array', 'summary' => 'array', 'heartbeat_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(InventoryImportRow::class);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['uploaded', 'mapped', 'configured', 'validated']) && ! $this->purged_at;
    }
}
