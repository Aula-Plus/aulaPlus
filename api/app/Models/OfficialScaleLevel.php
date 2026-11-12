<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One level of an OfficialScale (e.g. «Avance destacado»), with the official
 * descriptor. `position` is the order in the official document: 1 = highest.
 * `official_band` is the grade band the official table prints next to it
 * (e.g. «9–10»): reference only, never used to compute or show a grade.
 * Global catalog data — NOT tenant-scoped.
 */
#[Fillable(['official_scale_id', 'position', 'name', 'description', 'official_band'])]
class OfficialScaleLevel extends Model
{
    public function scale(): BelongsTo
    {
        return $this->belongsTo(OfficialScale::class, 'official_scale_id');
    }
}
