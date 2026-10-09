<?php

namespace App\Http\Controllers;

use App\Enums\AlertOutcome;
use App\Http\Requests\ChooseAlertOutcomeRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The three ways out of an alert (ClickUp 86e3jpzdp; documento vivo screen
 * 11): "me ocupo yo", "se la paso a otro rol", "la dejo en observación".
 * Each one records a responsible person and a deadline, appends a step to the
 * alert's thread, and closes any open "escalated, deadline passed" alert for
 * it — the circuit moved again.
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

        DB::transaction(function () use ($request, $alert, $user, $outcome, $assigneeId): void {
            $alert->actions()->create([
                'actor_id' => $user->id,
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
                'escalated_at' => null,
            ]);

            // Whoever chose and whoever is now responsible both keep seeing it.
            $alert->people()->syncWithoutDetaching(array_unique([$user->id, $assigneeId]));

            $alert->escalations()->where('resolved', false)->update([
                'resolved' => true,
                'resolved_by_id' => $user->id,
                'resolved_at' => now(),
            ]);
        });

        return new AlertResource($alert->fresh()->load(AlertResource::RELATIONS));
    }

    /**
     * GET /api/v1/alerts/{alert}/handoff-candidates — the active staff members
     * of the school the alert can be handed to (everyone but the caller), with
     * their roles so the UI can group them by role.
     */
    public function candidates(Request $request, Alert $alert): JsonResponse
    {
        $this->authorize('act', $alert);

        $candidates = User::query()
            ->where('school_id', $request->user()->school_id)
            ->whereKeyNot($request->user()->id)
            ->whereNull('disabled_at')
            ->whereNotNull('password')
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'roles' => $candidate->roles->pluck('name')->values(),
            ]);

        return response()->json(['data' => $candidates]);
    }
}
