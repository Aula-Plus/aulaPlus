<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource the school has (screen 23 «Recursos disponibles», P23-REC-02):
 * the garden, the science lab, computers… Loaded by Dirección; each group
 * unchecks what it does not have at hand.
 */
#[Fillable(['name', 'position'])]
class SchoolResource extends Model
{
    use Auditable, BelongsToSchool;

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
