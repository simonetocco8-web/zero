<?php

namespace App\Models;

use App\Enums\AdministrativeAction;
use App\Models\Concerns\AppendOnly;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use AppendOnly;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected $fillable = ['actor_user_id', 'action', 'subject_type', 'subject_id', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return [
            'action' => AdministrativeAction::class,
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
