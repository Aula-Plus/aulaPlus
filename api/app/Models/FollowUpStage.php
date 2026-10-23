<?php

namespace App\Models;

use App\Enums\FollowUpStageStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stage ("etapa") of a scheduled follow-up (ClickUp 86e3qkwna): what to
 * do, who is responsible, by when, and — once closed — what was done.
 *
 * Subdividing a stage marks it `split` and creates children pointing at it
 * through `parent_stage_id`; the original keeps its notes and history.
 *
 * `description` and `conclusion` may hold sensitive observations about a
 * minor, so they are excluded from the audit diff (CLAUDE.md security rule 11).
 */
#[Fillable([
    // Always copied from the parent follow-up server-side, never from input.
    'school_id',
    'scheduled_follow_up_id',
    'parent_stage_id',
    'description',
    'responsible_id',
    'due_date',
    'status',
    'conclusion',
    'closed_by_id',
    'closed_at',
    'created_by_id',
])]
class FollowUpStage extends Model
{
    use Auditable, BelongsToSchool;

    public static array $auditableExcludeFromDiff = ['description', 'conclusion'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => FollowUpStageStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(ScheduledFollowUp::class, 'scheduled_follow_up_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_stage_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /**
     * @return HasMany<FollowUpStageNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(FollowUpStageNote::class)->oldest()->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === FollowUpStageStatus::Pending;
    }
}
