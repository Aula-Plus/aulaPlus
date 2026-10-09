<?php

namespace App\Models;

use App\Enums\AlertOutcome;
use App\Enums\AlertRecipient;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * An early-warning alert for a student (docs/prompts/04-seguimiento-
 * institucional.md §4). Generated automatically by `alerts:generate` today;
 * `description` holds a short, non-PII summary and is excluded from the
 * audit diff (CLAUDE.md security rule 11), same pattern as Accommodation/
 * Barrier.
 *
 * Sustained-low-performance alerts (ClickUp 86e3jpzcv, `alerts:performance`)
 * also carry the subject they were met in, the rule that produced them and a
 * `recipients` snapshot of who they reach first — see
 * {@see self::scopeVisibleTo()}.
 */
#[Fillable([
    'student_id',
    'subject_id',
    'alert_rule_id',
    'source_alert_id',
    'type',
    'severity',
    'description',
    'condition_met_on',
    'recipients',
    'outcome',
    'assignee_id',
    'due_on',
    'outcome_by_id',
    'outcome_at',
    'escalated_at',
    'resolved',
    'resolved_by_id',
    'resolved_at',
])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['description'];

    /**
     * Users this instance is already known to be visible to — see
     * {@see self::markVisibleTo()}. Per instance, never persisted.
     *
     * @var array<int, true>
     */
    protected array $knownVisibleTo = [];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'condition_met_on' => 'date',
            'recipients' => 'array',
            'outcome' => AlertOutcome::class,
            'due_on' => 'date',
            'outcome_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** The person currently responsible (ClickUp 86e3jpzdp). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function outcomeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_by_id');
    }

    /** For an "escalated, deadline passed" alert: the alert it escalates. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_alert_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(self::class, 'source_alert_id');
    }

    /** The thread: every way out chosen, oldest first. */
    public function actions(): HasMany
    {
        return $this->hasMany(AlertAction::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * People the alert reaches individually, on top of the role-based
     * `recipients` snapshot: whoever it was handed to and whoever handed it.
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_user')->withTimestamps();
    }

    /**
     * Whether the user may choose one of the three ways out now: someone it
     * reaches while nobody has chosen yet, and afterwards only the person
     * currently responsible. An escalated alert takes no way out of its own —
     * it closes when the escalated alert moves.
     */
    public function canBeActedOnBy(User $user): bool
    {
        return ! $this->resolved
            && $this->type !== AlertType::EscalatedOverdue
            && ($this->outcome === null || $this->assignee_id === $user->id)
            && $this->isVisibleTo($user);
    }

    /**
     * Whether the user may mark the alert resolved. Legacy alerts (no
     * recipients snapshot) keep the original rule — any school-wide role that
     * sees them. Any other alert closes only after a way out was chosen, and
     * only by the person responsible: never with a bare "ya me encargué".
     */
    public function canBeResolvedBy(User $user): bool
    {
        if ($this->resolved || $this->type === AlertType::EscalatedOverdue) {
            return false;
        }

        if ($this->recipients === null) {
            return $this->isVisibleTo($user);
        }

        return $this->outcome !== null
            && $this->assignee_id === $user->id
            && $this->isVisibleTo($user);
    }

    /**
     * Scope to the alerts a user may see. The single source of truth for alert
     * visibility: AlertPolicy::view delegates here, and list endpoints call it
     * directly, so the two can never drift.
     *
     * - Legacy alerts (no `recipients` snapshot, created before ClickUp
     *   86e3jpzcv) keep their original rule: school-wide clinical roles only.
     * - Otherwise a psychopedagogue/director sees it only when their role is in
     *   the snapshot ("dirección no la ve al principio"), and a teacher only
     *   when `teacher` is in the snapshot AND they teach the alert's subject in
     *   one of the student's groups (any subject, for an alert with none).
     * - Anyone listed in `alert_user` sees it too: the person it was handed
     *   to "la ve desde ese momento" (ClickUp 86e3jpzdp), and whoever handed
     *   it keeps seeing it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            if ($user->hasAnyRole(Role::schoolWideValues())) {
                $query->orWhereNull('alerts.recipients');
            }

            if ($user->hasRole(Role::Psychopedagogue->value)) {
                $query->orWhereJsonContains('alerts.recipients', AlertRecipient::Psychopedagogue->value);
            }

            if ($user->hasRole(Role::Director->value)) {
                $query->orWhereJsonContains('alerts.recipients', AlertRecipient::Director->value);
            }

            // Reached individually (handed to them, handed by them, or an
            // escalated alert addressed to them) — ClickUp 86e3jpzdp.
            $query->orWhereExists(fn (QueryBuilder $exists) => $exists
                ->selectRaw('1')
                ->from('alert_user')
                ->whereColumn('alert_user.alert_id', 'alerts.id')
                ->where('alert_user.user_id', $user->id));

            $query->orWhere(fn (Builder $query) => $query
                ->whereJsonContains('alerts.recipients', AlertRecipient::Teacher->value)
                ->whereExists(fn (QueryBuilder $exists) => $exists
                    ->selectRaw('1')
                    ->from('group_teacher')
                    ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                    ->whereColumn('group_student.student_id', 'alerts.student_id')
                    ->where('group_teacher.teacher_id', $user->id)
                    ->where(fn (QueryBuilder $subject) => $subject
                        ->whereNull('alerts.subject_id')
                        ->orWhereColumn('group_teacher.subject_id', 'alerts.subject_id'))));
        });
    }

    /**
     * Scope to the alerts that count in a user's "open alerts" counters: the
     * ones they can see, plus legacy alerts — which have always been counted
     * (never shown) for a teacher, see docs/prompts/04 §2. A new alert that
     * doesn't reach the user yet is neither shown nor counted.
     */
    public function scopeCountableFor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('alerts.recipients')
            ->orWhere(fn (Builder $query) => $query->visibleTo($user)));
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->school_id !== $this->school_id) {
            return false;
        }

        return isset($this->knownVisibleTo[$user->id])
            || static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * Record that this instance was loaded through {@see self::scopeVisibleTo()}
     * for the user, so the per-alert `can` checks in AlertResource don't
     * re-run the visibility query once per alert (N+1) on list endpoints.
     * Only list endpoints that filtered with visibleTo($user) may call it.
     */
    public function markVisibleTo(User $user): static
    {
        $this->knownVisibleTo[$user->id] = true;

        return $this;
    }
}
