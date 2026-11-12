<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimetableSlotRequest;
use App\Http\Resources\TimetableSlotResource;
use App\Models\Subject;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * «Mis horarios» (ClickUp 1dfd3mc4bgh): the caller's own weekly timetable.
 * Private: every query is pinned to the caller, and TimetableSlotPolicy only
 * lets the owner act on a slot. Saving or changing it never touches a class.
 */
class TimetableSlotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TimetableSlot::class);

        $user = $request->user();

        $slots = TimetableSlot::query()
            ->with(['group:id,name', 'subject:id,name'])
            ->where('teacher_id', $user->id)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        // The group-subjects the teacher can add a slot for.
        // One row per group_teacher assignment (a group taught in two
        // subjects appears twice).
        $assignments = $user->groups()
            ->get(['groups.id', 'groups.name'])
            ->map(fn ($group) => ['group_id' => $group->id, 'group_name' => $group->name, 'subject_id' => $group->pivot->subject_id])
            ->filter(fn (array $row) => $row['subject_id'] !== null)
            ->values();

        $subjectNames = Subject::query()
            ->whereIn('id', $assignments->pluck('subject_id')->unique())
            ->pluck('name', 'id');

        return TimetableSlotResource::collection($slots)->additional([
            'meta' => [
                'assignments' => $assignments
                    ->map(fn (array $row) => [
                        'group' => ['id' => $row['group_id'], 'name' => $row['group_name']],
                        'subject' => ['id' => $row['subject_id'], 'name' => $subjectNames[$row['subject_id']] ?? null],
                    ])
                    ->sortBy(fn (array $row) => $row['group']['name'].' '.$row['subject']['name'])
                    ->values(),
            ],
        ]);
    }

    public function store(TimetableSlotRequest $request): JsonResponse
    {
        $slot = TimetableSlot::create([
            ...$request->validated(),
            'teacher_id' => $request->user()->id,
        ]);

        return (new TimetableSlotResource($slot->load(['group:id,name', 'subject:id,name'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(TimetableSlotRequest $request, TimetableSlot $timetableSlot): TimetableSlotResource
    {
        $timetableSlot->update($request->validated());

        return new TimetableSlotResource($timetableSlot->load(['group:id,name', 'subject:id,name']));
    }

    public function destroy(TimetableSlot $timetableSlot): Response
    {
        $this->authorize('delete', $timetableSlot);

        $timetableSlot->delete();

        return response()->noContent();
    }
}
