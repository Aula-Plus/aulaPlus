<?php

namespace App\Models;

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
 */
#[Fillable([
    'student_id',
    'subject_id',
    'alert_rule_id',
    'type',
    'severity',
    'description',
    'condition_met_on',
    'recipients',
    'resolved',
    'resolved_by_id',
    'resolved_at',
])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    public static array $auditableExcludeFromDiff = ['description'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'condition_met_on' => 'date',
            'recipients' => 'array',
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
        return $user->school_id === $this->school_id
            && static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }
}
