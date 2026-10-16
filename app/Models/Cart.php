<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = ['user_id'];

    protected function casts(): array
    {
        return [];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
