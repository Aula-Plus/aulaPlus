<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group's level ("nivel": Inicial, Primaria, Ciclo Básico, Bachillerato).
 * Global — the same list for every school, so NOT tenant-scoped — and kept as
 * data (seeded by migration), not in code. `key` is the stable identifier;
 * `label` is only what is shown, so renaming it leaves every group and record
 * untouched. `screening_population` is the screening population (screen 08,
 * App\Enums\SchoolLevel value) the level corresponds to; null = none.
 */
class EducationLevel extends Model
{
    protected $table = 'education_levels';

    public function grades(): HasMany
    {
        return $this->hasMany(SchoolGrade::class)->orderBy('position');
    }
}
