<?php

namespace App\Models;

use App\Enums\FollowUpMemberRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person involved in a follow-up — general responsible or participant
 * (ClickUp 86e3qkwna). Rows are never deleted: withdrawing or being removed is
 * recorded on the row (when, by whom, reason) so the history keeps that the
 * person took part. `hidden_at` is the person's own choice to drop it from
 * their inbox after leaving.
 *
 * The reasons are free text and may mention the student: excluded from the
 * audit diff (CLAUDE.md security rule 11).
 */
#[Fillable([
    // Always copied from the parent follow-up server-side, never from input.
    'school_id',
    'scheduled_follow_up_id',
    'user_id',
    'role',
    'withdrawn_at',
    'withdrawal_reason',
    'removed_at',
    'removed_by_id',
    'removal_reason',
    'hidden_at',
])]
class FollowUpMember extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['withdrawal_reason', 'removal_reason'];

    protected function casts(): array
    {
        return [
            'role' => FollowUpMemberRole::class,
            'withdrawn_at' => 'datetime',
            'removed_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(ScheduledFollowUp::class, 'scheduled_follow_up_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null && $this->removed_at === null;
    }

    public function isGeneralResponsible(): bool
    {
        return $this->isActive() && $this->role === FollowUpMemberRole::GeneralResponsible;
    }

    /**
     * When the person stopped taking part (withdrew or was removed), or null
     * while active. Their view of the follow-up is cut at this moment.
     */
    public function leftAt(): ?Carbon
    {
        return $this->withdrawn_at ?? $this->removed_at;
    }
}
