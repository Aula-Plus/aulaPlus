<?php

namespace App\Models;

use App\Enums\AccommodationCategory;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\TracksAuthorship;
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
            'assessment_types' => 'array',
            'active' => 'boolean',
            'llm_rule' => 'array',
            'requires_external_approval' => 'boolean',
            'approved' => 'boolean',
        ];
    }

    /**
     * Every accommodation has a start date (NOT NULL). An HTTP write always
     * sends it (StoreAccommodationRequest requires it); any other creation path
     * that doesn't say otherwise means "in force from today".
     */
    protected static function booted(): void
    {
        static::creating(function (self $accommodation): void {
            $accommodation->starts_on ??= now()->toDateString();
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
        $subjectIds = $this->subjects()->pluck('subjects.id');

        $subjectApplies = $subjectIds->isEmpty() || $subjectIds->contains($assessment->subject_id);
        $typeApplies = empty($this->assessment_types)
            || in_array($assessment->type?->value, $this->assessment_types, true);

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
        return $user->school_id === $this->school_id
            && static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    public function barriers(): BelongsToMany
    {
        return $this->belongsToMany(Barrier::class, 'barrier_accommodation')
            ->withPivot(['proposed_by_id', 'validated', 'validated_by_id'])
            ->withTimestamps();
    }
}
