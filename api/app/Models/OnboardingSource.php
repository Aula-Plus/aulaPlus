<?php

namespace App\Models;

use App\Enums\OnboardingStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One row of «Qué se recibió» (P29-IMP-02): what arrived, in what format, and its status. */
#[Fillable(['source', 'content', 'format', 'status', 'position'])]
class OnboardingSource extends Model
{
    use BelongsToSchool;

    protected function casts(): array
    {
        return ['status' => OnboardingStatus::class, 'position' => 'integer'];
    }
}
