<?php

namespace App\Http\Controllers;

use App\Http\Requests\MeetingSheetTypeRequest;
use App\Models\MeetingSheetType;
use Illuminate\Http\JsonResponse;

/**
 * The school's sheet types (P10-DEC-01): read by psychopedagogy and
 * direction, renamed and added by direction from «Colegio y estructura ›
 * Institución» (P23-DEC-61). No delete: past sheets keep their type.
 */
class MeetingSheetTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MeetingSheetType::class);

        return response()->json([
            'data' => MeetingSheetType::query()->orderBy('position')->orderBy('id')->get(['id', 'name', 'position']),
        ]);
    }

    public function store(MeetingSheetTypeRequest $request): JsonResponse
    {
        $type = MeetingSheetType::create([
            'name' => $request->validated('name'),
            'position' => (int) MeetingSheetType::query()->max('position') + 1,
        ]);

        return response()->json(['data' => $type->only(['id', 'name', 'position'])], 201);
    }

    public function update(MeetingSheetTypeRequest $request, MeetingSheetType $meetingSheetType): JsonResponse
    {
        $meetingSheetType->update(['name' => $request->validated('name')]);

        return response()->json(['data' => $meetingSheetType->only(['id', 'name', 'position'])]);
    }
}
