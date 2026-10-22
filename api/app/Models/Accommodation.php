<?php

namespace App\Models;

use App\Enums\AccommodationCategory;
use App\Enums\AssessmentType;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\TracksAuthorship;
use App\Support\SchoolTime;
use Carbon\CarbonInterface;
use Database\Factories\AccommodationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A pedagogical accommodation for a student. Holds sensitive data about a
 * minor's learning profile — see CLAUDE.md security rule 11. `description`
 * is excluded from the audit diff for the same reason (never log full
 * sensitive content); `llm_rule` will feed the session-5 AI assistant and is
 * excluded too.
 *
 * Scope (ClickUp 86e3dpbwy follow-up): `starts_on` is the date it is in force
 * from; `subjects` restricts it to some subjects (none = a general
 * accommodation, every subject); `assessment_types` restricts it to some
 * assessment types (null = every type). A teacher only sees the accommodations
 * that apply to a subject they teach the student — see {@see self::scopeVisibleTo()}.
 *
 * Dates in force (PO, ClickUp 86e3d1bwa, option A): `starts_on` («Vigente
 * desde») fills itself with the day the accommodation becomes effective and
 * stays editable; there is no end date a user types in. When it stops being
 * effective (switched off), the system records that day in `deactivated_on` —
 * never fillable, never in a Form Request. See {@see self::booted()} and
 * {@see self::isInForceOn()}.
 */
#[Fillable([
    'student_id',
    'type',
    'category',
    'starts_on',
    'assessment_types',
    'active',
    'description',
    'focus_area',
    'llm_rule',
    'requires_external_approval',
    'approved',
    'created_by_id',
    'deleted_by_id',
])]
class Accommodation extends Model
{
    /** @use HasFactory<AccommodationFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes, TracksAuthorship;

    public static array $auditableExcludeFromDiff = ['description', 'llm_rule'];

    protected function casts(): array
    {
        return [
            'category' => AccommodationCategory::class,
            'starts_on' => 'date',
            'deactivated_on' => 'date',
            'assessment_types' => 'array',
            'active' => 'boolean',
            'llm_rule' => 'array',
            'requires_external_approval' => 'boolean',
            'approved' => 'boolean',
        ];
    }

    /**
     * Keeps `starts_on`/`deactivated_on` in step with whether the accommodation
     * is effective (school-local calendar days, App\Support\SchoolTime):
     *
     * - Creating: `starts_on` defaults to today (the form sends today unless the
     *   user picks another day).
     * - Becoming effective (switched back on, or its external approval granted):
     *   `starts_on` moves up to today unless this same write sets it explicitly
     *   — it was not in force before — and `deactivated_on` is cleared. A start
     *   date already in the future is kept. Only one interval is stored: after
     *   a re-activation the earlier one lives in the audit log only.
     * - Stopping being effective (switched off): `deactivated_on` = today.
     */
    protected static function booted(): void
    {
        static::creating(function (self $accommodation): void {
            $accommodation->starts_on ??= SchoolTime::today()->toDateString();
        });

        static::updating(function (self $accommodation): void {
            $wasEffective = (bool) $accommodation->getOriginal('active')
                && (! $accommodation->getOriginal('requires_external_approval')
                    || $accommodation->getOriginal('approved') === true);
            $isEffective = $accommodation->isEffective();

            if ($wasEffective === $isEffective) {
                return;
            }

            $today = SchoolTime::today()->toDateString();

            if (! $isEffective) {
                $accommodation->deactivated_on = $today;

                return;
            }

            $accommodation->deactivated_on = null;

            if (! $accommodation->isDirty('starts_on')
                && ($accommodation->starts_on === null || $accommodation->starts_on->toDateString() < $today)) {
                $accommodation->starts_on = $today;
            }
        });
    }

    /**
     * Whether this accommodation actually applies in practice: it must be
     * active, and if it requires external approval, that approval must have
     * been granted (approved === true — pending `null` or rejected `false`
     * are not effective). Use this everywhere "does this accommodation apply
     * right now" matters, rather than reading `active` alone.
     */
    public function isEffective(): bool
    {
        return $this->active && (! $this->requires_external_approval || $this->approved === true);
    }

    /**
     * Query equivalent of {@see self::isEffective()} — keep the two in sync.
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('accommodations.active', true)
            ->where(fn (Builder $approval) => $approval
                ->where('accommodations.requires_external_approval', false)
                ->orWhere('accommodations.approved', true));
    }

    /**
     * Accommodations whose start date has been reached (`starts_on` <= the
     * given date, today in school time by default).
     */
    public function scopeStarted(Builder $query, ?CarbonInterface $on = null): Builder
    {
        return $query->whereDate('accommodations.starts_on', '<=', ($on ?? SchoolTime::today())->format('Y-m-d'));
    }

    /**
     * Whether it was in force on the given school-local day: started by then,
     * and either still effective or switched off after that day (the day it
     * was switched off no longer counts). A pending or rejected one, or one
     * created inactive and never switched on, was never in force. In-memory
     * twin of {@see self::scopeInForceOn()} — keep the two in sync.
     */
    public function isInForceOn(CarbonInterface|string $day): bool
    {
        $day = $day instanceof CarbonInterface ? $day->format('Y-m-d') : substr($day, 0, 10);

        return $this->starts_on !== null
            && $this->starts_on->toDateString() <= $day
            && ($this->isEffective()
                || ($this->deactivated_on !== null && $this->deactivated_on->toDateString() > $day));
    }

    /**
     * Query equivalent of {@see self::isInForceOn()}.
     */
    public function scopeInForceOn(Builder $query, CarbonInterface|string $day): Builder
    {
        $day = $day instanceof CarbonInterface ? $day->format('Y-m-d') : substr($day, 0, 10);

        return $query
            ->whereDate('accommodations.starts_on', '<=', $day)
            ->where(fn (Builder $inForce) => $inForce
                ->where(fn (Builder $effective) => $effective->effective())
                ->orWhereDate('accommodations.deactivated_on', '>', $day));
    }

    /**
     * Accommodations that apply in the given subject: the general ones (no
     * accommodation_subject rows) plus those scoped to it. Query side of the
     * subject half of {@see self::appliesToAssessment()}.
     */
    public function scopeAppliesToSubject(Builder $query, int $subjectId): Builder
    {
        return $query->where(fn (Builder $scope) => $scope
            ->whereNotExists(fn (QueryBuilder $general) => $general
                ->selectRaw('1')
                ->from('accommodation_subject')
                ->whereColumn('accommodation_subject.accommodation_id', 'accommodations.id'))
            ->orWhereExists(fn (QueryBuilder $scoped) => $scoped
                ->selectRaw('1')
                ->from('accommodation_subject')
                ->whereColumn('accommodation_subject.accommodation_id', 'accommodations.id')
                ->where('accommodation_subject.subject_id', $subjectId)));
    }

    /**
     * Accommodations that apply to the given assessment type: no type
     * restriction, or the type is in `assessment_types`. Query side of the type
     * half of {@see self::appliesToAssessment()}.
     */
    public function scopeAppliesToAssessmentType(Builder $query, AssessmentType $type): Builder
    {
        return $query->where(fn (Builder $scope) => $scope
            ->whereNull('accommodations.assessment_types')
            ->orWhereJsonLength('accommodations.assessment_types', 0)
            ->orWhereJsonContains('accommodations.assessment_types', $type->value));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    public function instanceOverrides(): HasMany
    {
        return $this->hasMany(AccommodationInstanceOverride::class);
    }

    /**
     * The subjects this accommodation is restricted to. Empty = general (it
     * applies in every subject). Trashed subjects are kept in the list: a
     * soft-deleted subject must never silently turn a scoped accommodation
     * into a general one.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'accommodation_subject')
            ->withTrashed()
            ->withTimestamps()
            ->orderBy('subjects.name');
    }

    /**
     * Whether it applies to the given assessment instance: its subject is in
     * scope (or the accommodation is general) and its type is in scope (or no
     * type restriction is set).
     */
    public function appliesToAssessment(Assessment $assessment): bool
    {
        return $this->appliesIn($assessment->subject_id, $assessment->type);
    }

    /**
     * Whether it applies in the given subject and assessment type. Uses the
     * `subjects` relation when it is already loaded (no query per call when
     * matching a list in memory), else queries the subject ids.
     */
    public function appliesIn(?int $subjectId, AssessmentType|string|null $type): bool
    {
        $subjectIds = $this->relationLoaded('subjects')
            ? $this->subjects->modelKeys()
            : $this->subjects()->pluck('subjects.id')->all();
        $type = $type instanceof AssessmentType ? $type->value : $type;

        $subjectApplies = $subjectIds === []
            || ($subjectId !== null && in_array((int) $subjectId, array_map('intval', $subjectIds), true));
        $typeApplies = empty($this->assessment_types)
            || in_array($type, $this->assessment_types, true);

        return $subjectApplies && $typeApplies;
    }

    /**
     * Scope to the accommodations a user may see. The single source of truth
     * for accommodation visibility: AccommodationPolicy::view delegates here
     * and the student profile filters through it, so the two can't drift.
     *
     * - Psychopedagogue/director: every accommodation of their school.
     * - Anyone else (a teacher): only for students they teach (a group_teacher
     *   row in one of the student's groups), and only the general ones plus
     *   those scoped to a subject they teach in one of those groups. A subject-
     *   specific accommodation never reaches the teachers of other subjects.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('accommodations.school_id', $user->school_id);

        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return $query;
        }

        return $query
            ->whereExists(fn (QueryBuilder $teaches) => $teaches
                ->selectRaw('1')
                ->from('group_teacher')
                ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                ->whereColumn('group_student.student_id', 'accommodations.student_id')
                ->where('group_teacher.teacher_id', $user->id))
            ->where(fn (Builder $scope) => $scope
                ->whereNotExists(fn (QueryBuilder $general) => $general
                    ->selectRaw('1')
                    ->from('accommodation_subject')
                    ->whereColumn('accommodation_subject.accommodation_id', 'accommodations.id'))
                ->orWhereExists(fn (QueryBuilder $taught) => $taught
                    ->selectRaw('1')
                    ->from('accommodation_subject')
                    ->join('group_teacher', 'group_teacher.subject_id', '=', 'accommodation_subject.subject_id')
                    ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                    ->whereColumn('accommodation_subject.accommodation_id', 'accommodations.id')
                    ->whereColumn('group_student.student_id', 'accommodations.student_id')
                    ->where('group_teacher.teacher_id', $user->id)));
    }

    public function isVisibleTo(User $user): bool
    {
        // A hard-deleted record rebuilt from its audit snapshot (the student
        // file history) can't be checked against the DB, and its subject
        // scope went with it: only school-wide roles may still see it.
        if (! $this->exists) {
            return $user->school_id === $this->school_id
                && $user->hasAnyRole(Role::schoolWideValues());
        }

        // withTrashed: visibility is about who may see the record, not whether
        // it's still in force — the file history checks soft-deleted ones too.
        return $user->school_id === $this->school_id
            && static::query()->withTrashed()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    public function barriers(): BelongsToMany
    {
        return $this->belongsToMany(Barrier::class, 'barrier_accommodation')
            ->withPivot(['proposed_by_id', 'validated', 'validated_by_id'])
            ->withTimestamps();
    }
}
