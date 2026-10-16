<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;

class MarketplaceReturnCase extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function ($m) {
            foreach (array_keys($m->getDirty()) as $field) {
                if (! in_array($field, ['status', 'approved_amount_cents', 'notes', 'goods_return_snapshot', 'updated_at'], true)) {
                    throw new \LogicException('Return origin and financial snapshots are immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Return history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['requested_amount_cents' => ExactIntegerCast::class, 'approved_amount_cents' => ExactIntegerCast::class, 'goods_return_snapshot' => 'array', 'financial_snapshot' => 'array'];
    }

    public function events()
    {
        return $this->hasMany(MarketplaceReturnEvent::class);
    }
}
