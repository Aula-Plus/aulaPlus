<?php

namespace App\Models\Concerns;

use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adopt this trait on any business model that has a `school_id` column to make
 * it multi-tenant aware. It does two things automatically:
 *
 *   1. Applies the {@see SchoolScope} global scope so reads are constrained to
 *      the current school.
 *   2. Fills `school_id` on create from the current tenant, so application code
 *      never has to set it by hand (and can't accidentally set the wrong one).
 *
 * Future domain models (planificaciones, evaluaciones, comunicaciones, …) only
 * need `use BelongsToSchool;` plus a `school_id` column to inherit isolation.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('school_id') === null && Tenancy::hasSchool()) {
                $model->setAttribute('school_id', Tenancy::schoolId());
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
