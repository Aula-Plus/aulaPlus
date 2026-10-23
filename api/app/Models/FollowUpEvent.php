<?php

namespace App\Models;

use App\Enums\FollowUpEventType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a follow-up's history (ClickUp 86e3qkwna): who did what, when.
 * Append-only and not Auditable itself — it *is* the trail. `data` holds ids
 * and dates only (e.g. old/new general deadline); free text such as a reason
 * or a previous conclusion goes in `note`, which is sensitive and never
 * logged.
 */
#[Fillable([
    // Always copied from the parent follow-up server-side, never from input.
    'school_id',
    'scheduled_follow_up_id',
    'follow_up_stage_id',
    'actor_id',
    'subject_user_id',
    'type',
    'data',
    'note',
    'created_at',
])]
class FollowUpEvent extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => FollowUpEventType::class,
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(ScheduledFollowUp::class, 'scheduled_follow_up_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
