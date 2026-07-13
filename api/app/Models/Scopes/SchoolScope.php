<?php

namespace App\Models\Scopes;

use App\Models\Concerns\BelongsToSchool;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-scoped model to the
 * current school (see {@see Tenancy}).
 *
 * This is the enforcement point for multi-tenant isolation: it is applied
 * automatically by the {@see BelongsToSchool} trait so no
 * individual query has to remember to add a `where school_id = ?` clause.
 *
 * When no tenant is resolved (e.g. an unauthenticated context), the scope adds
 * no filter. Business models are always behind `auth:sanctum`, so an
 * unauthenticated request never reaches them; console/Job contexts must call
 * {@see Tenancy::useSchool()} first. This scope is defence-in-depth layered on
 * top of Policies — authorization decisions still live in server-side code.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = Tenancy::schoolId();

        if ($schoolId !== null) {
            $builder->where($model->qualifyColumn('school_id'), $schoolId);

            return;
        }

        // No tenant resolved. Fail CLOSED for authenticated requests: if someone
        // is logged in but we can't determine their school, expose nothing rather
        // than everything (this should be unreachable since school_id is
        // mandatory — it's defence in depth against a future auth/guard change).
        // Console/system contexts have no authenticated user; they opt in
        // explicitly via Tenancy::useSchool(), so they are left unfiltered.
        if (Tenancy::hasAuthenticatedUser()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
