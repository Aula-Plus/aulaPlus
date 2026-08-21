<?php

namespace App\Models\Concerns;

use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Adopt this trait on any model whose creation counts as a tracked "usage"
 * signal for the adoption dashboard (docs/prompts/04-seguimiento-
 * institucional.md §5) — writes one `usage_events` row on `created`, with
 * `event_type` taken from the static `$usageEventType` the model declares:
 *
 *     public static string $usageEventType = 'annual_plan.created';
 *
 * (Declared per-model rather than on the trait itself for the same reason as
 * Auditable::$auditableExcludeFromDiff — PHP won't let a using class
 * override a trait's static property default.)
 *
 * Only fires when there's an authenticated actor (a real HTTP
 * request/console session with a logged-in user) — factories/seeders that
 * create these models without an authenticated user simply don't log
 * anything, since there is no one to attribute the event to.
 */
trait LogsUsageEvents
{
    public static function bootLogsUsageEvents(): void
    {
        static::created(function (Model $model): void {
            $userId = static::logsUsageEventsCurrentUserId();

            if ($userId === null) {
                return;
            }

            UsageEvent::create([
                'school_id' => $model->getAttribute('school_id'),
                'user_id' => $userId,
                // Only the created row's id reaches metadata — never the
                // model's content (CLAUDE.md security rules 2 and 11).
                'event_type' => static::$usageEventType,
                'metadata' => ['id' => $model->getKey()],
            ]);
        });
    }

    /**
     * Same multi-guard resolution as Auditable's — see
     * Auditable::auditableCurrentUser() for why this is duplicated rather
     * than shared.
     */
    protected static function logsUsageEventsCurrentUserId(): ?int
    {
        return (Auth::user() ?? Auth::guard('sanctum')->user())?->id;
    }
}
