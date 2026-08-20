<?php

namespace App\Models;

use App\Enums\AnepPrimaryBody;
use App\Enums\AnepSecondaryBody;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root. Every user and every business record belongs to exactly one
 * school. Schools themselves are NOT tenant-scoped (there is no "current school"
 * filter on them); they are administered outside the per-school context.
 */
#[Fillable([
    'name',
    'slug',
    'logo_url',
    'anep_authorization_type',
    'anep_primary_body',
    'anep_secondary_body',
    'levels_offered',
    'instruction_languages',
])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'anep_primary_body' => AnepPrimaryBody::class,
            'anep_secondary_body' => AnepSecondaryBody::class,
            'levels_offered' => 'array',
            'instruction_languages' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function curricularFrameworks(): BelongsToMany
    {
        return $this->belongsToMany(CurricularFramework::class, 'school_curricular_framework')
            ->withPivot(['level_from', 'level_to', 'active', 'configuration'])
            ->withTimestamps();
    }
}
