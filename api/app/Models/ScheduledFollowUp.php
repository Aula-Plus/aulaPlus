<?php

namespace App\Models;

use App\Enums\FollowUpMemberRole;
use App\Enums\FollowUpStageStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ScheduledFollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A follow-up someone scheduled on a student for a given date
 * (docs/prompts/17-seguimiento-programado.md). It is scheduled by a person and
 * nothing expires on its own — this is the only entity that models a person
 * asking to revisit a case on a date. Deliberately generic (student + free
 * description + date), with no structural link to Accommodation/Barrier yet.
 *
 * Since ClickUp 86e3qkwna it has a `title`, one or more stages
 * (FollowUpStage), several people (FollowUpMember: general responsibles and
 * participants) and a history (FollowUpEvent). `due_date` is the *general
 * deadline*: the latest due date among its stages, kept in sync by
 * FollowUpWorkflow so the overdue lists keep reading one column.
 * `assigned_to_id` mirrors the first general responsible for the same reason.
 *
 * `title`, `description` and `resolution_note` may hold sensitive
 * observations about a minor, so they are excluded from the audit diff
 * (CLAUDE.md security rule 11), same pattern as Comment/Accommodation/Alert.
 */
#[Fillable([
    'student_id',
    'title',
    'description',
    'due_date',
    'created_by_id',
    'assigned_to_id',
    'resolved',
    'resolved_by_id',
    'resolved_at',
    'resolution_note',
])]
class ScheduledFollowUp extends Model
{
    /** @use HasFactory<ScheduledFollowUpFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['title', 'description', 'resolution_note'];

    /**
     * Populate the DB default in memory too, so a freshly created model (and
     * its audit diff / `is_overdue`) reflects `resolved = false` without a
     * round-trip to reload it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'resolved' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The person responsible for it (the creator unless they picked someone
     * else). Decides whose pending list it appears in, not who can see it.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * Active participants (not general responsibles), i.e. the people who
     * also follow it. Read-only view over `follow_up_members`: membership is
     * written through FollowUpMember so `school_id` is always set.
     *
     * @return BelongsToMany<User, $this>
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follow_up_members')
            ->wherePivot('role', FollowUpMemberRole::Participant->value)
            ->wherePivotNull('withdrawn_at')
            ->wherePivotNull('removed_at');
    }

    /**
     * Everyone who is or was involved, including those who withdrew or were
     * removed (their rows keep the history).
     *
     * @return HasMany<FollowUpMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(FollowUpMember::class)->orderBy('id');
    }

    /**
     * @return HasMany<FollowUpStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(FollowUpStage::class)->orderBy('id');
    }

    /**
     * @return HasMany<FollowUpEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FollowUpEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function memberFor(User $user): ?FollowUpMember
    {
        return $this->members->firstWhere('user_id', $user->id);
    }

    public function hasPendingStages(): bool
    {
        return $this->stages->contains(fn (FollowUpStage $stage) => $stage->status === FollowUpStageStatus::Pending);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Whether this follow-up's due date has passed without being resolved.
     * The due date is the last valid day, so a follow-up becomes overdue the
     * day *after* it (`due_date < today`), not on the due date itself.
     * Computed server-side in the app timezone (config `app.timezone`) so
     * every consumer gets one consistent answer instead of each client
     * re-implementing the date/timezone arithmetic — see the spec §3.
     */
    public function isOverdue(): bool
    {
        return ! $this->resolved && $this->due_date->lessThan(Carbon::today());
    }
}
