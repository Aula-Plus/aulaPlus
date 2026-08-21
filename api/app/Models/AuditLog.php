<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditOrigin;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable entry in the audit trail: who did what, to which record, and
 * when. Written automatically by {@see Auditable} on
 * created/updated/deleted — application code never inserts into this table
 * directly.
 */
#[Fillable(['school_id', 'auditable_type', 'auditable_id', 'action', 'user_id', 'origin', 'changes', 'created_at'])]
class AuditLog extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'origin' => AuditOrigin::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
