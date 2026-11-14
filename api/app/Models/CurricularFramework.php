<?php

namespace App\Models;

use App\Enums\PlannerFamily;
use Database\Factories\CurricularFrameworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global catalog shared by every school (e.g. "ANEP EBI", "IB DP"). NOT
 * tenant-scoped — does not use BelongsToSchool / school_id. `planner_family`
 * says which unit-planner fields it brings (null = only the common ones).
 */
#[Fillable(['name', 'planner_family', 'education_levels'])]
class CurricularFramework extends Model
{
    /** @use HasFactory<CurricularFrameworkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['planner_family' => PlannerFamily::class, 'education_levels' => 'array'];
    }

    public function isAnep(): bool
    {
        return $this->planner_family === PlannerFamily::Anep;
    }

    /** Whether the program applies to an education level (null levels = any). */
    public function appliesToLevel(?string $levelKey): bool
    {
        return $levelKey === null || $this->education_levels === null
            || in_array($levelKey, $this->education_levels, true);
    }

    public function catalogs(): HasMany
    {
        return $this->hasMany(CurricularCatalog::class);
    }

    public function officialScales(): HasMany
    {
        return $this->hasMany(OfficialScale::class);
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_curricular_framework')
            ->withPivot(['level_from', 'level_to', 'active', 'mandatory', 'configuration'])
            ->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_curricular_framework')
            ->withTimestamps();
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_curricular_framework')
            ->withTimestamps();
    }
}
