<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student record. NOTE: students are NOT platform users — they never log in.
 * They are records managed by the school staff (rosters, profiles, tracking).
 *
 * Some fields on this model (learning_profile, individual_profile,
 * tracking_notes, related_documents) hold sensitive data about a minor's
 * learning/clinical profile — see CLAUDE.md security rule 11: field-level
 * access by role, never logged in full, never sent to a third-party API
 * un-anonymized.
 */
#[Fillable([
    'full_name',
    'photo_url',
    'birth_date',
    'enrollment_year',
    'has_therapeutic_companion',
    'learning_profile',
    'tracking_notes',
    'individual_profile',
    'related_documents',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes;

    public static array $auditableExcludeFromDiff = [
        'learning_profile',
        'individual_profile',
        'tracking_notes',
        'related_documents',
    ];

    protected $table = 'students';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'has_therapeutic_companion' => 'boolean',
            'learning_profile' => 'array',
            'individual_profile' => 'array',
            'related_documents' => 'array',
        ];
    }

    /**
     * Groups this student has belonged to, per school year (M:N via group_student).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_student')
            ->withPivot(['school_year', 'details'])
            ->withTimestamps();
    }

    public function curricularFrameworks(): BelongsToMany
    {
        return $this->belongsToMany(CurricularFramework::class, 'student_curricular_framework')
            ->withTimestamps();
    }

    public function annualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class);
    }

    public function accommodations(): HasMany
    {
        return $this->hasMany(Accommodation::class);
    }

    public function barriers(): HasMany
    {
        return $this->hasMany(Barrier::class);
    }

    public function technicalReports(): HasMany
    {
        return $this->hasMany(TechnicalReport::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * The group this student belongs to for a given school year (defaults to
     * the current year). Convenience accessor over the group_student pivot.
     */
    public function groupForYear(?int $schoolYear = null): ?Group
    {
        return $this->groups()
            ->wherePivot('school_year', $schoolYear ?? now()->year)
            ->first();
    }

    /**
     * Scope a query to the students a user is allowed to see: every student
     * for a school-wide role (director/psychopedagogue), or only students
     * enrolled in a group they lead for a teacher. Mirrors StudentPolicy::view
     * — keeps that role check out of controllers, which only call this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return $query;
        }

        return $query->whereHas(
            'groups',
            fn ($q) => $q->whereHas('teachers', fn ($qq) => $qq->where('users.id', $user->id))
        );
    }
}
