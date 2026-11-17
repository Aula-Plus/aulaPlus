<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's value of an external datum for a school year — always the
 * original number (P30-DAT-02). Sensitive data about a minor (CLAUDE.md rule
 * 11): only Psicopedagogía and Dirección read or write it, never logged.
 */
#[Fillable(['external_measure_id', 'student_id', 'school_year', 'value', 'loaded_by_id'])]
class ExternalMeasureValue extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return [
            'school_year' => 'integer',
            'value' => 'float',
        ];
    }

    public function measure(): BelongsTo
    {
        return $this->belongsTo(ExternalMeasure::class, 'external_measure_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
