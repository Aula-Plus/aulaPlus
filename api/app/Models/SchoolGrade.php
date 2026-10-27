<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grade ("grado": 8°, 1° de Bachillerato…). Global data like EducationLevel.
 * `education_level_id` is only the level it suggests when a group is created
 * with this grade: a help, never a rule — the group's level is stored apart and
 * direction may pick another one.
 */
class SchoolGrade extends Model
{
    protected $table = 'school_grades';

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }
}
