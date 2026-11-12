<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An official grading scale («Escala oficial», screen 24): defined by an
 * external body (ANEP, IB…), loaded once per curricular framework by the Aula+
 * team (Database\Seeders\OfficialScaleSeeder) with its citation — document and
 * page. Global catalog like CurricularFramework: NOT tenant-scoped, and no
 * write endpoint exists (P24-ESC-01, P24-DEC-03: a scale is defined, never
 * configured or converted).
 */
#[Fillable([
    'curricular_framework_id',
    'education_level_id',
    'key',
    'name',
    'issuing_body',
    'citation_document',
    'citation_page',
    'source_url',
])]
class OfficialScale extends Model
{
    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    /**
     * The levels in the official document's order (position 1 = highest).
     */
    public function levels(): HasMany
    {
        return $this->hasMany(OfficialScaleLevel::class)->orderBy('position');
    }
}
