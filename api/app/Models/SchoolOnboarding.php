<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The header of screen 29 «Puesta en marcha» for one school: import, regime and
 * opening dates, the offering confirmation and the Aula+ person assigned to
 * the school. Written only by `app:onboarding-status`.
 */
#[Fillable(['import_completed_on', 'regime_configured_on', 'opened_on', 'offering_confirmed_on', 'contact_name', 'contact_channel'])]
class SchoolOnboarding extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return [
            'import_completed_on' => 'date',
            'regime_configured_on' => 'date',
            'opened_on' => 'date',
            'offering_confirmed_on' => 'date',
        ];
    }
}
