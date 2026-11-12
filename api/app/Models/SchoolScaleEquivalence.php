<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The school's own equivalence for one level of its Primaria scale: which
 * number 1..10 goes with it (ClickUp 1dfd3mc4bgg). Declared by the school and
 * shown as the school's — Aula+ never converts between scales (P24-DEC-03).
 * Tenant-scoped.
 */
#[Fillable(['school_id', 'official_scale_level_id', 'numeric_grade'])]
class SchoolScaleEquivalence extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return [
            'numeric_grade' => 'integer',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(OfficialScaleLevel::class, 'official_scale_level_id');
    }
}
