<?php

namespace App\Models;

use App\Enums\CalendarBody;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of a school year for one calendar row (e.g. «Trimestre 3» of
 * ANEP Bachillerato from 14/09): a body (`calendar_body`: ANEP Bachillerato,
 * ANEP Ciclo Básico y Primaria, Cambridge, IB) or, once Cambridge or IB is
 * split by program — or for a program with no body —, one program
 * (`curricular_framework_id`). Exactly one of the two is set. Each row has
 * its own scheme (P26-DEC-04); a period lasts until the row's next one
 * starts. Independent from the grades-visibility cutoff (PA-040).
 */
#[Fillable(['school_year_id', 'calendar_body', 'curricular_framework_id', 'name', 'starts_on'])]
class SchoolPeriod extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'calendar_body' => CalendarBody::class];
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
