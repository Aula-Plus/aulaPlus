<?php

namespace App\Http\Controllers;

use App\Models\MeetingSheetAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The actions agreed in a meeting are tasks in their owner's Inicio
 * (P10-DEC-08, P10-PLA-01): `mine` lists the caller's open ones from
 * confirmed sheets — description, deadline and the meeting's type and date,
 * never the rest of the sheet — and `complete` marks one done (owner only,
 * MeetingSheetActionPolicy). Open to every role: a teacher who never enters
 * the space still gets the actions left to them.
 */
class MeetingSheetActionController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $actions = MeetingSheetAction::query()
            ->with('sheet.sheetType:id,name')
            ->where('owner_id', $request->user()->id)
            ->whereNull('done_at')
            ->whereHas('sheet', fn ($sheet) => $sheet->where('status', 'confirmed'))
            ->orderBy('due_on')
            ->get();

        return response()->json([
            'data' => $actions->map(fn (MeetingSheetAction $action) => $this->present($action))->values(),
        ]);
    }

    public function complete(MeetingSheetAction $action): JsonResponse
    {
        $this->authorize('complete', $action);

        if ($action->done_at === null) {
            $action->forceFill(['done_at' => now()])->save();
        }

        return response()->json(['data' => $this->present($action->load('sheet.sheetType:id,name'))]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(MeetingSheetAction $action): array
    {
        return [
            'id' => $action->id,
            'description' => $action->description,
            'due_on' => $action->due_on->toDateString(),
            'done' => $action->done_at !== null,
            'meeting' => [
                'sheet_type_name' => $action->sheet->sheetType->name,
                'held_on' => $action->sheet->held_on->toDateString(),
            ],
        ];
    }
}
