<?php

namespace App\Http\Controllers;

use App\Enums\PsychopedagogyMeetingKind;
use App\Enums\PsychopedagogyMeetingStatus;
use App\Http\Requests\CompletePsychopedagogyMeetingRequest;
use App\Http\Requests\PsychopedagogyMeetingRequest;
use App\Http\Resources\StaffMemberResource;
use App\Models\Comment;
use App\Models\MeetingSheetType;
use App\Models\PsychopedagogyMeeting;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Screen 10 › Agenda (ClickUp 1dfd3mc4bge).
 *
 * - `overview`: the header counts and «Carga de la semana» — how many
 *   meetings of each type this week, never with whom. Psychopedagogy and
 *   direction (P10-AGE-03).
 * - `index` / `store` / `update` / `complete`: the team's agenda itself,
 *   psychopedagogy only. Completing an individual session may leave its note
 *   in the student's file as an observation, «solo Psicopedagogía» by
 *   default (P10-DEC-10).
 * - `staff`: the school's active people, to pick who was there and action
 *   owners. Psychopedagogy only.
 *
 * Teachers get 403 on every endpoint: no teacher enters the space.
 */
class PsychopedagogyMeetingController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewWorkload', PsychopedagogyMeeting::class);
        [$start, $end] = $this->week($request);

        $meetings = PsychopedagogyMeeting::query()
            ->whereBetween('scheduled_on', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'kind', 'meeting_sheet_type_id']);

        $byType = MeetingSheetType::query()->orderBy('position')->orderBy('id')->get(['id', 'name'])
            ->map(fn (MeetingSheetType $type) => [
                'key' => "type-{$type->id}",
                'meeting_sheet_type_id' => $type->id,
                'name' => $type->name,
                'count' => $meetings->where('meeting_sheet_type_id', $type->id)->count(),
            ]);

        return response()->json(['data' => [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'meetings_this_week' => $meetings->count(),
            'active_companions' => TherapeuticCompanion::query()->whereHas('student')->count(),
            'workload' => [
                [
                    'key' => PsychopedagogyMeetingKind::IndividualSession->value,
                    'meeting_sheet_type_id' => null,
                    'name' => null,
                    'count' => $meetings->where('kind', PsychopedagogyMeetingKind::IndividualSession)->count(),
                ],
                ...$byType->all(),
            ],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PsychopedagogyMeeting::class);
        [$start, $end] = $this->week($request);

        $meetings = PsychopedagogyMeeting::query()
            ->with(['sheetType:id,name', 'student:id,full_name', 'group:id,name', 'attendees:id,name', 'sheet:id,psychopedagogy_meeting_id,status'])
            ->whereBetween('scheduled_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('scheduled_on')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'data' => $meetings->map(fn (PsychopedagogyMeeting $meeting) => $this->present($meeting))->values(),
            'meta' => ['week_start' => $start->toDateString(), 'week_end' => $end->toDateString()],
        ]);
    }

    public function store(PsychopedagogyMeetingRequest $request): JsonResponse
    {
        $meeting = DB::transaction(function () use ($request): PsychopedagogyMeeting {
            $meeting = PsychopedagogyMeeting::create([
                ...$this->attributes($request),
                'created_by_id' => $request->user()->id,
            ]);
            $meeting->attendees()->sync($this->attendeeIds($request));

            return $meeting;
        });

        return response()->json(['data' => $this->present($this->reload($meeting))], 201);
    }

    public function update(PsychopedagogyMeetingRequest $request, PsychopedagogyMeeting $meeting): JsonResponse
    {
        DB::transaction(function () use ($request, $meeting): void {
            $meeting->update($this->attributes($request));
            $meeting->attendees()->sync($this->attendeeIds($request));
        });

        return response()->json(['data' => $this->present($this->reload($meeting))]);
    }

    public function complete(CompletePsychopedagogyMeetingRequest $request, PsychopedagogyMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        if ($meeting->status === PsychopedagogyMeetingStatus::Done) {
            return response()->json(['message' => 'This meeting is already done.'], 422);
        }

        $note = trim((string) $request->validated('note'));
        if ($note !== '' && (! $meeting->isIndividualSession() || $meeting->student_id === null)) {
            return response()->json(['message' => 'Only an individual session leaves a note; a meeting leaves a sheet.'], 422);
        }

        DB::transaction(function () use ($request, $meeting, $note): void {
            $commentId = null;

            if ($note !== '') {
                $comment = new Comment([
                    'author_id' => $request->user()->id,
                    'commentable_type' => Student::class,
                    'commentable_id' => $meeting->student_id,
                    'content' => $note,
                    ...$request->scope()->commentAttributes(),
                ]);
                $comment->save();
                $commentId = $comment->id;
            }

            $meeting->forceFill([
                'status' => PsychopedagogyMeetingStatus::Done,
                'completed_at' => now(),
                'note_comment_id' => $commentId,
            ])->save();
        });

        if ($meeting->student_id !== null) {
            Cache::forget(StudentTrackingController::cacheKey($meeting->student_id));
        }

        return response()->json(['data' => $this->present($this->reload($meeting))]);
    }

    public function staff(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PsychopedagogyMeeting::class);

        $users = User::query()
            ->where('school_id', $request->user()->school_id)
            ->whereNull('disabled_at')
            ->with('roles')
            ->orderBy('name')
            ->get();

        return StaffMemberResource::collection($users);
    }

    /**
     * Monday–Sunday of the week holding `week` (a date), this week by default.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function week(Request $request): array
    {
        $validated = $request->validate(['week' => ['sometimes', 'date_format:Y-m-d']]);
        $day = isset($validated['week'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['week'], SchoolTime::timezone())
            : SchoolTime::today();
        $start = $day->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        return [$start, $start->addDays(6)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(PsychopedagogyMeetingRequest $request): array
    {
        $individual = $request->validated('kind') === PsychopedagogyMeetingKind::IndividualSession->value;

        return [
            'kind' => $request->validated('kind'),
            'meeting_sheet_type_id' => $individual ? null : $request->validated('meeting_sheet_type_id'),
            'scheduled_on' => $request->validated('scheduled_on'),
            'start_time' => $request->validated('start_time'),
            'student_id' => $request->validated('student_id'),
            'group_id' => $individual ? null : $request->validated('group_id'),
            'external_attendees' => $request->validated('external_attendees'),
        ];
    }

    /**
     * @return list<int>
     */
    protected function attendeeIds(PsychopedagogyMeetingRequest $request): array
    {
        return array_values(array_map('intval', $request->validated('attendee_ids', [])));
    }

    protected function reload(PsychopedagogyMeeting $meeting): PsychopedagogyMeeting
    {
        return $meeting->fresh(['sheetType:id,name', 'student:id,full_name', 'group:id,name', 'attendees:id,name', 'sheet:id,psychopedagogy_meeting_id,status']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(PsychopedagogyMeeting $meeting): array
    {
        return [
            'id' => $meeting->id,
            'kind' => $meeting->kind->value,
            'sheet_type' => $meeting->sheetType ? ['id' => $meeting->sheetType->id, 'name' => $meeting->sheetType->name] : null,
            'scheduled_on' => $meeting->scheduled_on->toDateString(),
            'start_time' => $meeting->start_time,
            'student' => $meeting->student ? ['id' => $meeting->student->id, 'name' => $meeting->student->full_name] : null,
            'group' => $meeting->group ? ['id' => $meeting->group->id, 'name' => $meeting->group->name] : null,
            'attendees' => $meeting->attendees->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'external_attendees' => $meeting->external_attendees,
            'status' => $meeting->status->value,
            'has_note' => $meeting->note_comment_id !== null,
            'sheet' => $meeting->sheet ? ['id' => $meeting->sheet->id, 'status' => $meeting->sheet->status->value] : null,
        ];
    }
}
