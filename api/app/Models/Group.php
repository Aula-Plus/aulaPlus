<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class group (e.g. "3° A"). Owned by the school; teachers and students are
 * linked via the group_teacher / group_student pivots (M:N, historical per
 * school year for students).
 */
#[Fillable(['name', 'level', 'school_year', 'group_profile', 'related_documents'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected $table = 'groups';

    protected function casts(): array
    {
        return [
            'group_profile' => 'array',
            'related_documents' => 'array',
        ];
    }

    /**
     * Teachers leading this group. Used by GroupPolicy to grant a teacher
     * access to the groups they lead.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_teacher', 'group_id', 'teacher_id')
            ->withPivot('details')
            ->withTimestamps();
    }

    /**
     * Students currently or historically enrolled in this group, per school year.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'group_student')
            ->withPivot(['school_year', 'details'])
            ->withTimestamps();
    }

    public function curricularFrameworks(): BelongsToMany
    {
        return $this->belongsToMany(CurricularFramework::class, 'group_curricular_framework')
            ->withTimestamps();
    }

    public function annualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    /**
     * Whether the given user leads this group (has a group_teacher membership).
     */
    public function isLedBy(User $user): bool
    {
        return $this->teachers()->where('teacher_id', $user->id)->exists();
    }

    /**
     * Scope a query to the groups a user is allowed to see: every group for
     * a school-wide role (director/psychopedagogue), or only the groups they
     * lead for a teacher. Mirrors GroupPolicy::view — keeps that role check
     * out of controllers, which only call this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return $query;
        }

        return $query->whereHas('teachers', fn ($q) => $q->where('users.id', $user->id));
    }
}
