<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An objective of a Unit, written by the teacher (screens 12–13). Classes and
 * assessments point at the objectives they work; the unit's status and the
 * annual plan's coverage are derived from those links
 * (App\Services\AnnualPlans\AnnualPlanProgress).
 */
#[Fillable(['unit_id', 'description', 'position'])]
class UnitObjective extends Model
{
    use BelongsToSchool;

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function classSessions(): BelongsToMany
    {
        return $this->belongsToMany(ClassSession::class, 'class_session_unit_objective')
            ->withPivot('coverage')
            ->withTimestamps();
    }

    public function assessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'assessment_unit_objective')
            ->withTimestamps();
    }
}
