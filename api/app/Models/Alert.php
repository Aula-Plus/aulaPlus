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
 * institucional.md §4). `description` holds a short, non-PII summary and is excluded from the
 * audit diff (CLAUDE.md security rule 11), same pattern as Accommodation/
 * Barrier.
 *
 * Sustained-low-performance alerts (ClickUp 86e3jpzcv, `alerts:performance`)
 * also carry the subject they were met in, the rule that produced them and a
 * `recipients` snapshot of who they reach first — see
 * {@see self::scopeVisibleTo()}.
 *
 * «Señal de dificultad» alerts (ClickUp 86e3qkwpn, `alerts:difficulty-signals`)
 * carry the comment category the signals were counted in
 * (`comment_category_id`) and only the count in `description` — never any
 * observation text, author or id. They never reach a teacher.
 */
#[Fillable([
    'student_id',
    'subject_id',
    'comment_category_id',
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
    'overdue_at',
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
            'overdue_at' => 'datetime',
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

    /** «Señal de dificultad» alerts: the category the signals were counted in. */
    public function commentCategory(): BelongsTo
    {
        return $this->belongsTo(CommentCategory::class);
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

    /**
     * For a legacy "escalated, deadline passed" alert (no longer created —
     * review of 14/10): the alert it escalates.
     */
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
     * `recipients` snapshot: whoever has it now and whoever had it before
     * (they keep read access — see {@see self::scopeVisibleTo()}).
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_user')->withTimestamps();
    }

    /**
     * Scheduled follow-ups created from this alert ("Crear seguimiento
     * vinculado", review of 14/10). Separate records: creating, sharing or
     * resolving one never changes the alert's state or deadline, and vice
     * versa.
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(ScheduledFollowUp::class);
    }

    /**
     * Whether the user has the alert now: the one person responsible once a
     * way out was chosen. Handing it off transfers it to exactly one person;
     * whoever had it keeps reading it, with no actions.
     */
    public function isInChargeBy(User $user): bool
    {
        return $this->assignee_id !== null && $this->assignee_id === $user->id;
    }

    /**
     * Whether the user may choose one of the three ways out now: someone it
     * reaches while nobody has chosen yet, and afterwards only whoever has
     * it. An escalated alert (legacy, no longer created) takes no way out of
     * its own — it closes when the escalated alert moves.
     */
    public function canBeActedOnBy(User $user): bool
    {
        return ! $this->resolved
            && $this->type !== AlertType::EscalatedOverdue
            && ($this->outcome === null || $this->isInChargeBy($user))
            && $this->isVisibleTo($user);
    }

    /**
     * Whether the user may leave a note in the alert's thread: only whoever
     * has it, so only once a way out was chosen. Before that the alert
     * reaches people but nobody has it yet; the first step is choosing a way
     * out (which takes its own note). Never on a closed or an escalated alert.
     */
    public function canBeNotedBy(User $user): bool
    {
        return ! $this->resolved
            && $this->type !== AlertType::EscalatedOverdue
            && $this->outcome !== null
            && $this->isInChargeBy($user)
            && $this->isVisibleTo($user);
    }

    /**
     * Whether the user may create a follow-up linked to this alert ("Crear
     * seguimiento vinculado"): whoever may act on it — so not whoever handed
     * it off, nor anyone on a closed alert. Seeing the student is checked as
     * well by ScheduledFollowUpPolicy::create on the follow-up itself.
     */
    public function canLinkFollowUpBy(User $user): bool
    {
        return $this->canBeActedOnBy($user);
    }

    /**
     * Whether the user may mark the alert resolved. Legacy alerts (no
     * recipients snapshot) keep the original rule — any school-wide role that
     * sees them. Any other alert closes only after a way out was chosen, and
     * only by whoever has it: never with a bare "ya me encargué".
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
            && $this->isInChargeBy($user)
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
     *   Never for a «Señal de dificultad» alert (ClickUp 86e3qkwpn), whatever
     *   its snapshot says — defence in depth over AlertRouting.
     * - Anyone listed in `alert_user` sees it too, while they still see the
     *   student (always, for psychopedagogy/direction; a teacher only while
     *   they teach the student): the person it was handed to "la ve desde ese
     *   momento" (ClickUp 86e3jpzdp), and whoever handed it keeps reading it
     *   (review of 14/10) — read only, see {@see self::isInChargeBy()}.
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

            // Reached individually (has it, had it, or a legacy escalated
            // alert addressed to them) — ClickUp 86e3jpzdp — but only while
            // they still see the student: an alert never outlives the
            // access to the student it is about.
            $individually = fn (QueryBuilder $exists) => $exists
                ->selectRaw('1')
                ->from('alert_user')
                ->whereColumn('alert_user.alert_id', 'alerts.id')
                ->where('alert_user.user_id', $user->id);

            if ($user->hasAnyRole(Role::schoolWideValues())) {
                $query->orWhereExists($individually);
            } else {
                $query->orWhere(fn (Builder $query) => $query
                    ->whereExists($individually)
                    ->whereExists(fn (QueryBuilder $exists) => $exists
                        ->selectRaw('1')
                        ->from('group_teacher')
                        ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                        ->whereColumn('group_student.student_id', 'alerts.student_id')
                        ->where('group_teacher.teacher_id', $user->id)));
            }

            $query->orWhere(fn (Builder $query) => $query
                ->where('alerts.type', '!=', AlertType::DifficultySignal->value)
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
