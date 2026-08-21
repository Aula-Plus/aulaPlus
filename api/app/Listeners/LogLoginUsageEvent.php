<?php

namespace App\Listeners;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Records a `login` usage event for the adoption dashboard
 * (docs/prompts/04-seguimiento-institucional.md §5) whenever a user
 * authenticates through Sanctum's session guard (the SPA login flow — see
 * AuthenticatedSessionController::store()). Registered against Laravel's
 * built-in `Illuminate\Auth\Events\Login`, fired by `Auth::attempt()`.
 */
class LogLoginUsageEvent
{
    public function handle(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        UsageEvent::create([
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'event_type' => 'login',
            'metadata' => null,
        ]);
    }
}
