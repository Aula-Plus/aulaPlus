<?php

namespace App\Http\Controllers;

use App\Enums\AlertActionKind;
use App\Http\Requests\StoreAlertNoteRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;

/**
 * Notes in an alert's thread (ClickUp 86e3jpzdp follow-up). A note is not a
 * way out: the person responsible, the outcome and the deadline stay as they
 * are, and so does a «Llegó a su fecha sin salida» mark.
 *
 * Working a case together with several people is not done on the alert (the
 * review of 14/10 removed sharing it): it is done on a follow-up linked to it
 * — see StoreScheduledFollowUpRequest (`alert_id`).
 */
class AlertThreadController extends Controller
{
    /**
     * POST /api/v1/alerts/{alert}/notes — a note from whoever has the alert
     * (once a way out was chosen).
     */
    public function note(StoreAlertNoteRequest $request, Alert $alert): AlertResource
    {
        $alert->actions()->create([
            'actor_id' => $request->user()->id,
            'kind' => AlertActionKind::Note,
            'note' => $request->validated('note'),
        ]);

        return new AlertResource($alert->fresh()->load(AlertResource::RELATIONS));
    }
}
