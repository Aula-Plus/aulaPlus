<?php

namespace App\Http\Controllers;

use App\Enums\AlertActionKind;
use App\Enums\AlertOutcome;
use App\Http\Requests\ChooseAlertOutcomeRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\User;
use App\Support\AlertCirculation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The three ways out of an alert (ClickUp 86e3jpzdp; documento vivo screen
 * 11): "me ocupo yo", "se la paso a otro rol", "la dejo en observación".
 * Each one records a responsible person and a deadline, appends a step to the
 * alert's thread and clears a «Llegó a su fecha sin salida» mark (there is a
 * new deadline). It also closes any open legacy "escalated" alert for it —
 * none is created any more, but existing ones close when the circuit moves.
 *
 * Handing it off transfers it to exactly one person (review of 14/10): the
 * receiver is the only one who has it from then on; whoever handed it off
 * keeps reading it — no actions, no new notices — while they still see the
 * student. A follow-up linked to the alert is a separate record and is never
 * touched here.
 */
class AlertOutcomeController extends Controller
{
    public function store(ChooseAlertOutcomeRequest $request, Alert $alert): AlertResource
    {
        $user = $request->user();
        $outcome = AlertOutcome::from($request->validated('outcome'));
        $assigneeId = $outcome === AlertOutcome::HandedOff
            ? (int) $request->validated('assignee_id')
            : $user->id;

        $alert = DB::transaction(function () use ($request, $alert, $user, $outcome, $assigneeId): Alert {
            // Lock the row and work on what it holds now, so two
            // simultaneous moves can't both act on a stale person in charge.
            // Re-check under the lock: someone else may have just handed it
            // off or closed it.
            $alert = Alert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if (! $alert->canBeActedOnBy($user)) {
                throw new AuthorizationException;
            }

            $alert->actions()->create([
                'actor_id' => $user->id,
                'kind' => AlertActionKind::Outcome,
                'outcome' => $outcome,
                'assignee_id' => $assigneeId,
                'due_on' => $request->validated('due_on'),
                'note' => $request->validated('note'),
            ]);

            $alert->update([
                'outcome' => $outcome,
                'assignee_id' => $assigneeId,
                'due_on' => $request->validated('due_on'),
                'outcome_by_id' => $user->id,
                'outcome_at' => now(),
                'overdue_at' => null,
            ]);

            // Whoever chose and whoever is now responsible both keep seeing it
            // (the former read-only, once it is handed off).
            $alert->people()->syncWithoutDetaching(array_unique([$user->id, $assigneeId]));

            $alert->escalations()->where('resolved', false)->update([
                'resolved' => true,
                'resolved_by_id' => $user->id,
                'resolved_at' => now(),
            ]);

            return $alert;
        });

        return new AlertResource($alert->fresh()->load(AlertResource::RELATIONS));
    }

    /**
     * GET /api/v1/alerts/{alert}/handoff-candidates — the people the caller
     * may hand the alert to ({@see AlertCirculation}: active
     * colleagues who already see the student, never teacher-to-teacher, and
     * never a teacher for a «Señal de dificultad» alert), with their roles so
     * the UI can show them. Names and roles only.
     */
    public function candidates(Request $request, Alert $alert, AlertCirculation $circulation): JsonResponse
    {
        $this->authorize('act', $alert);

        $candidates = $circulation->candidates($request->user(), $alert)
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'roles' => $candidate->roles->pluck('name')->values(),
            ]);

        return response()->json(['data' => $candidates]);
    }
}
