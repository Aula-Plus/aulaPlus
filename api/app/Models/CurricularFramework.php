<?php

namespace App\Models;

use Database\Factories\CurricularFrameworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global catalog shared by every school (e.g. "ANEP EBI", "IB DP"). NOT
 * tenant-scoped — does not use BelongsToSchool / school_id.
 */
#[Fillable(['name'])]
class CurricularFramework extends Model
{
    /** @use HasFactory<CurricularFrameworkFactory> */
    use HasFactory;

    public function catalogs(): HasMany
    {
        return $this->hasMany(CurricularCatalog::class);
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_curricular_framework')
            ->withPivot(['level_from', 'level_to', 'active', 'configuration'])
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
