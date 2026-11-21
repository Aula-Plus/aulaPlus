<?php

namespace App\Http\Controllers;

use App\Http\Requests\MeetingSheetRequest;
use App\Models\MeetingSheet;
use App\Models\MeetingSheetAction;
use App\Models\MeetingSheetFragment;
use App\Models\Student;
use App\Models\User;
use App\Services\PsychopedagogySpace\MeetingSheetSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Screen 10 › Planillas (ClickUp 1dfd3mc4bge).
 *
 * `index` serves both roles that may list sheets, with field-level gating:
 * psychopedagogy gets the summary of each sheet; direction gets only that the
 * meeting existed — date, type and group, or «Individual» (P10-PLA-06,
 * P10-DEC-04) — never who was there, who was discussed, the text nor the
 * actions. Everything else is psychopedagogy's: the full sheet, editing the
 * draft («Editar antes») and confirming the split, the only way anything
 * reaches a student's file (P10-DEC-06, MeetingSheetSplit). Teachers 403.
 */
class MeetingSheetController extends Controller
{
    public const PER_PAGE = 50;

    public function __construct(protected MeetingSheetSplit $split) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MeetingSheet::class);
        $full = $request->user()->can('viewContent', MeetingSheet::class);

        $sheets = MeetingSheet::query()
            ->with(['sheetType:id,name', 'group:id,name'])
            ->when($full, fn ($query) => $query->with(['students:id,full_name', 'attendees:id,name'])->withCount('actions'))
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            // Sheets pile up all year and are meant to be crossed years later
            // (P10-DEC-01): paged, «Ver más» in the SPA.
            ->paginate(self::PER_PAGE);

        return response()->json([
            'meta' => [
                'current_page' => $sheets->currentPage(),
                'last_page' => $sheets->lastPage(),
                'total' => $sheets->total(),
            ],
            'data' => $sheets->getCollection()->map(function (MeetingSheet $sheet) use ($full): array {
                $row = [
                    'id' => $sheet->id,
                    'held_on' => $sheet->held_on->toDateString(),
                    'sheet_type' => ['id' => $sheet->sheetType->id, 'name' => $sheet->sheetType->name],
                    'group' => $sheet->group ? ['id' => $sheet->group->id, 'name' => $sheet->group->name] : null,
                ];

                if (! $full) {
                    return $row;
                }

                return [
                    ...$row,
                    'status' => $sheet->status->value,
                    'students' => $sheet->students->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->full_name])->values(),
                    'attendees' => $sheet->attendees->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
                    'actions_count' => $sheet->actions_count,
                ];
            })->values(),
        ]);
    }

    public function show(MeetingSheet $sheet): JsonResponse
    {
        $this->authorize('view', $sheet);

        return response()->json(['data' => $this->present($sheet)]);
    }

    public function store(MeetingSheetRequest $request): JsonResponse
    {
        $sheet = DB::transaction(function () use ($request): MeetingSheet {
            $sheet = MeetingSheet::create([
                ...$this->attributes($request),
                'created_by_id' => $request->user()->id,
            ]);
            $this->syncParts($sheet, $request);

            return $sheet;
        });

        return response()->json(['data' => $this->present($sheet)], 201);
    }

    public function update(MeetingSheetRequest $request, MeetingSheet $sheet): JsonResponse
    {
        DB::transaction(function () use ($request, $sheet): void {
            // Under a lock, so a confirmation running at the same time either
            // lands before (and this answers 422) or sees this edit whole.
            $locked = MeetingSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->isConfirmed(), 422, 'A confirmed sheet can no longer be edited.');

            $sheet->update($this->attributes($request));
            $this->syncParts($sheet, $request);
        });

        return response()->json(['data' => $this->present($sheet)]);
    }

    /**
     * «Confirmar el reparto»: each fragment lands in its student's file with
     * its scope, and whoever can read it is notified.
     */
    public function confirm(Request $request, MeetingSheet $sheet): JsonResponse
    {
        $this->authorize('confirm', $sheet);

        // Already confirmed, no fragments, a removed student: answered with a
        // 422 by the split, which checks them under a lock.
        $this->split->confirm($sheet, $request->user());

        return response()->json(['data' => $this->present($sheet->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(MeetingSheetRequest $request): array
    {
        return [
            'meeting_sheet_type_id' => $request->validated('meeting_sheet_type_id'),
            'psychopedagogy_meeting_id' => $request->validated('psychopedagogy_meeting_id'),
            'held_on' => $request->validated('held_on'),
            'group_id' => $request->validated('group_id'),
            'external_attendees' => $request->validated('external_attendees'),
            'content' => $request->validated('content'),
        ];
    }

    /**
     * Attendees, discussed students, the proposed fragments and the actions
     * are replaced as a whole while the sheet is a draft (nothing of it has
     * reached anyone yet).
     */
    protected function syncParts(MeetingSheet $sheet, MeetingSheetRequest $request): void
    {
        $sheet->attendees()->sync(array_map('intval', $request->validated('attendee_ids', [])));
        $sheet->students()->sync(array_map('intval', $request->validated('student_ids')));

        $sheet->fragments()->get()->each->delete();
        foreach ($request->validated('fragments', []) as $fragment) {
            $sheet->fragments()->create([
                'student_id' => $fragment['student_id'],
                'content' => $fragment['content'],
                'scope' => $fragment['scope'],
            ]);
        }

        $sheet->actions()->get()->each->delete();
        foreach ($request->validated('actions', []) as $action) {
            $sheet->actions()->create([
                'description' => $action['description'],
                'owner_id' => $action['owner_id'],
                'due_on' => $action['due_on'],
            ]);
        }
    }

    /**
     * The full sheet: psychopedagogy only (checked by the callers).
     *
     * @return array<string, mixed>
     */
    protected function present(MeetingSheet $sheet): array
    {
        $sheet->load(['sheetType:id,name', 'group:id,name', 'attendees:id,name', 'students:id,full_name', 'fragments', 'actions.owner:id,name', 'confirmedBy:id,name']);

        return [
            'id' => $sheet->id,
            'held_on' => $sheet->held_on->toDateString(),
            'sheet_type' => ['id' => $sheet->sheetType->id, 'name' => $sheet->sheetType->name],
            'psychopedagogy_meeting_id' => $sheet->psychopedagogy_meeting_id,
            'group' => $sheet->group ? ['id' => $sheet->group->id, 'name' => $sheet->group->name] : null,
            'attendees' => $sheet->attendees->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'external_attendees' => $sheet->external_attendees,
            'students' => $sheet->students->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->full_name])->values(),
            'content' => $sheet->content,
            'fragments' => $sheet->fragments->map(fn (MeetingSheetFragment $fragment) => [
                'id' => $fragment->id,
                'student_id' => $fragment->student_id,
                'content' => $fragment->content,
                'scope' => $fragment->scope->value,
                'source' => $fragment->source,
                'landed' => $fragment->comment_id !== null,
            ])->values(),
            'actions' => $sheet->actions->map(fn (MeetingSheetAction $action) => [
                'id' => $action->id,
                'description' => $action->description,
                'owner' => $action->owner ? ['id' => $action->owner->id, 'name' => $action->owner->name] : null,
                'due_on' => $action->due_on->toDateString(),
                'done' => $action->done_at !== null,
            ])->values(),
            'status' => $sheet->status->value,
            'confirmed_at' => $sheet->confirmed_at?->toIso8601String(),
            'confirmed_by_name' => $sheet->confirmedBy?->name,
        ];
    }
}
