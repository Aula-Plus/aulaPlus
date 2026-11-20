<?php

namespace App\Models;

use App\Enums\OnboardingStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One step of the «Bitácora de la puesta en marcha» (P29-ARR-01). */
#[Fillable(['happened_on', 'step', 'status'])]
class OnboardingLogEntry extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return ['happened_on' => 'date', 'status' => OnboardingStatus::class];
    }
}
