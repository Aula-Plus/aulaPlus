<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\ExternalMeasureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A datum the school brings from outside Aula+ — a test it takes with another
 * tool (screen 30 «Datos», P30-DAT-02) — loaded with the Aula+ template, from
 * a CSV or by hand. Its original scale is declared by whoever creates it; one
 * value is kept per student and school year.
 */
#[Fillable(['name', 'scale_min', 'scale_max', 'taken_every_year', 'created_by_id'])]
class ExternalMeasure extends Model
{
    /** @use HasFactory<ExternalMeasureFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'scale_min' => 'float',
            'scale_max' => 'float',
            'taken_every_year' => 'boolean',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ExternalMeasureValue::class);
    }
}
