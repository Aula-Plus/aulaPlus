<?php

namespace App\Http\Controllers;

use App\Enums\JourneyStep;
use App\Http\Requests\StoreJourneyStepRequest;
use App\Models\UsageEvent;
use App\Support\UsageJourney;
use Illuminate\Http\Response;

/**
 * POST /api/v1/usage/journey-steps — the browser reports the creation-journey
 * steps only it can observe: the create form was opened, or filling started
 * (first change in any field). Throttled per user. Authorization and the
 * step/content-type whitelist live in StoreJourneyStepRequest.
 *
 * Idempotent per (user, journey, step): each step is recorded once per
 * journey, so a repeated or replayed report never inflates anything.
 */
class UsageJourneyStepController extends Controller
{
    public function store(StoreJourneyStepRequest $request): Response
    {
        $userId = $request->user()->id;
        $journeyId = $request->string('journey_id')->toString();
        $step = JourneyStep::from($request->string('step')->toString());

        $exists = UsageEvent::query()
            ->where('user_id', $userId)
            ->where('journey_id', $journeyId)
            ->where('step', $step->value)
            ->exists();

        if (! $exists) {
            UsageJourney::record(
                $userId,
                $journeyId,
                $step,
                $request->string('content_type')->toString(),
                $request->integer('group_id') ?: null,
            );
        }

        return response()->noContent();
    }
}
