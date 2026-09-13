<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveScheduledFollowUpRequest;
use App\Http\Requests\StoreScheduledFollowUpRequest;
use App\Http\Resources\ScheduledFollowUpResource;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Scheduled follow-ups on a student (docs/prompts/17-seguimiento-
 * programado.md §3). Every action is authorized in code — store/resolve via
 * their FormRequests, index via ScheduledFollowUpPolicy::viewForStudent.
 */
class ScheduledFollowUpController extends Controller
{
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewForStudent', [ScheduledFollowUp::class, $student]);

        $query = $student->scheduledFollowUps()->latest();

        // The endpoint returns every follow-up unless the caller narrows it
        // with ?resolved=true|false — the default filter is a client concern,
        // not decided here (spec §3).
        if ($request->has('resolved')) {
            $query->where('resolved', $request->boolean('resolved'));
        }

        return ScheduledFollowUpResource::collection($query->get());
    }

    public function store(StoreScheduledFollowUpRequest $request, Student $student): JsonResponse
    {
        $followUp = ScheduledFollowUp::create([
            'student_id' => $student->id,
            'created_by_id' => $request->user()->id,
            'description' => $request->validated('description'),
            'due_date' => $request->validated('due_date'),
        ]);

        return (new ScheduledFollowUpResource($followUp))->response()->setStatusCode(201);
    }

    public function resolve(ResolveScheduledFollowUpRequest $request, ScheduledFollowUp $followUp): ScheduledFollowUpResource
    {
        $followUp->update([
            'resolved' => true,
            'resolved_by_id' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_note' => $request->validated('resolution_note'),
        ]);

        return new ScheduledFollowUpResource($followUp);
    }
}
