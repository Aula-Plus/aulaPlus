<?php

namespace App\Http\Controllers;

use App\Enums\AlertActionKind;
use App\Http\Requests\ShareAlertRequest;
use App\Http\Requests\StoreAlertNoteRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Following an alert together (ClickUp 86e3jpzdp follow-up): sharing it with
 * a co-responsible and leaving notes in its thread.
 *
 * Sharing is not a way out: the person responsible, the outcome and the
 * deadline stay as they are, so a pending escalation ("plazo vencido") is
 * neither cleared nor closed by it — only choosing a way out (or resolving)
 * moves the circuit. Neither is a note.
 */
class AlertThreadController extends Controller
{
    /**
     * POST /api/v1/alerts/{alert}/share — the person it is shared with is in
     * charge from then on: they see it, may leave notes, choose a way out and
     * close it.
     */
    public function share(ShareAlertRequest $request, Alert $alert): AlertResource
    {
        $user = $request->user();
        $collaboratorId = (int) $request->validated('user_id');

        DB::transaction(function () use ($request, $alert, $user, $collaboratorId): void {
            // Re-check the "two at a time" cap under a row lock, so two
            // simultaneous shares can't both get in.
            Alert::query()->whereKey($alert->id)->lockForUpdate()->first();
            if ($alert->collaborators()->count() >= Alert::MAX_COLLABORATORS) {
                throw ValidationException::withMessages(['user_id' => 'This alert is already shared.']);
            }

            $alert->actions()->create([
                'actor_id' => $user->id,
                'kind' => AlertActionKind::Share,
                'assignee_id' => $collaboratorId,
                'note' => $request->validated('note'),
            ]);

            $alert->collaborators()->syncWithoutDetaching([$collaboratorId => ['shared_by_id' => $user->id]]);
            $alert->people()->syncWithoutDetaching([$user->id, $collaboratorId]);
        });

        return new AlertResource($alert->fresh()->load(AlertResource::RELATIONS));
    }

    /**
     * POST /api/v1/alerts/{alert}/notes — a note from whoever is in charge
     * (the person responsible or a co-responsible, once a way out was chosen).
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
