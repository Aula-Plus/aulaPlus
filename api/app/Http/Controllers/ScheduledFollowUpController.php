<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupScheduledFollowUpsRequest;
use App\Http\Requests\ResolveScheduledFollowUpRequest;
use App\Http\Requests\StoreScheduledFollowUpRequest;
use App\Http\Resources\ScheduledFollowUpResource;
use App\Models\Group;
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

    /**
     * GET /api/v1/groups/{group}/scheduled-follow-ups?overdue=true — the
     * follow-ups of a group's students (docs/prompts/21-perfil-de-grupo.md
     * §4). Endpoint access is GroupPolicy::view (enforced by the FormRequest:
     * a teacher who does not lead the group gets 403).
     *
     * Per-follow-up authorization is not repeated per row: ScheduledFollowUpPolicy
     * ::view grants access to a student iff the caller shares the school AND
     * (teaches the student OR is school-wide). Every student here belongs to
     * this group and the caller already passed GroupPolicy::view, so a teacher
     * leading the group teaches all of them and a school-wide role covers them
     * anyway — the per-row check was always true and only cost an N+1
     * (teachesStudent fires one query per follow-up). Optional ?overdue=true
     * narrows to overdue ones (is_overdue computed server-side, app timezone —
     * never trusting a client-computed flag).
     */
    public function indexForGroup(GroupScheduledFollowUpsRequest $request, Group $group): AnonymousResourceCollection
    {
        $studentIds = $group->students()->pluck('students.id');

        $followUps = ScheduledFollowUp::query()
            ->whereIn('student_id', $studentIds)
            ->latest()
            ->get();

        if ($request->boolean('overdue')) {
            $followUps = $followUps->filter->isOverdue();
        }

        return ScheduledFollowUpResource::collection($followUps->values());
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
