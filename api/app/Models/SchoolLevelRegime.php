<?php

namespace App\Models;

use App\Enums\LevelRegime;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The ANEP regime of one level of a school (P29-DEC-01), loaded by Aula+. */
#[Fillable(['education_level_id', 'regime', 'note'])]
class SchoolLevelRegime extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return ['regime' => LevelRegime::class];
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }
}
