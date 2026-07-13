<?php

namespace App\Support;

use App\Models\Concerns\BelongsToSchool;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Central resolver for the "current school" (tenant).
 *
 * By default the tenant is derived from the authenticated user's school_id, so
 * every HTTP request is automatically scoped to the school of whoever is logged
 * in. Console commands, queued Jobs and tests have no authenticated user, so
 * they must set the tenant explicitly via {@see Tenancy::useSchool()} before
 * touching tenant-scoped models.
 *
 * This is the single source of truth consumed by {@see SchoolScope}
 * and the {@see BelongsToSchool} trait — individual queries
 * never have to remember to filter by school_id.
 */
class Tenancy
{
    protected static ?int $schoolId = null;

    protected static bool $overridden = false;

    /**
     * The school_id scoping the current context, or null when none is resolved.
     */
    public static function schoolId(): ?int
    {
        if (static::$overridden) {
            return static::$schoolId;
        }

        // Resolve across guards: the SPA authenticates on the "web" guard, but a
        // token-based client authenticates on the "sanctum" guard. Reading only
        // the default guard would return null for token requests and leave the
        // SchoolScope unfiltered (cross-tenant read).
        return static::authenticatedUser()?->school_id;
    }

    public static function hasSchool(): bool
    {
        return static::schoolId() !== null;
    }

    /**
     * Whether any guard has an authenticated user, regardless of school. Used by
     * the SchoolScope to fail closed instead of open when a tenant can't be
     * resolved for an authenticated request.
     */
    public static function hasAuthenticatedUser(): bool
    {
        return static::authenticatedUser() !== null;
    }

    protected static function authenticatedUser(): ?Authenticatable
    {
        return Auth::user() ?? Auth::guard('sanctum')->user();
    }

    /**
     * Force the current tenant (for console, Jobs, seeders and tests).
     */
    public static function useSchool(School|int $school): void
    {
        static::$schoolId = $school instanceof School ? $school->id : $school;
        static::$overridden = true;
    }

    /**
     * Run a callback scoped to a specific school, then restore the previous state.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public static function forSchool(School|int $school, callable $callback): mixed
    {
        $previousId = static::$schoolId;
        $previousOverridden = static::$overridden;

        static::useSchool($school);

        try {
            return $callback();
        } finally {
            static::$schoolId = $previousId;
            static::$overridden = $previousOverridden;
        }
    }

    /**
     * Clear any explicit override and fall back to the authenticated user.
     */
    public static function forget(): void
    {
        static::$schoolId = null;
        static::$overridden = false;
    }
}
