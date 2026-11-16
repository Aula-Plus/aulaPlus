<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of a school year for one program (e.g. «Trimestre 3» of ANEP
 * Bachillerato from 14/09). Each program has its own scheme (P26-DEC-04); a
 * period lasts until the program's next one starts. Independent from the
 * grades-visibility cutoff (PA-040).
 */
#[Fillable(['school_year_id', 'curricular_framework_id', 'name', 'starts_on'])]
class SchoolPeriod extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return ['starts_on' => 'date'];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function curricularFramework(): BelongsTo
    {
        return $this->belongsTo(CurricularFramework::class);
    }
}
