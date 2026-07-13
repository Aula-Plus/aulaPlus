<?php

namespace App\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root. Every user and every business record belongs to exactly one
 * school. Schools themselves are NOT tenant-scoped (there is no "current school"
 * filter on them); they are administered outside the per-school context.
 */
#[Fillable(['name', 'slug'])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

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
}
