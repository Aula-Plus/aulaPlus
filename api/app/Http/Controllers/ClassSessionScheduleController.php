<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuggestedSlotRequest;
use App\Http\Requests\UpdateClassSessionScheduleRequest;
use App\Models\ClassSession;
use App\Services\Calendar\TimetableSlotSuggester;
use Illuminate\Http\JsonResponse;

/**
 * Time and room of a class (ClickUp 1dfd3mc4bgh): editing them, and the
 * pre-fill «Mis horarios» proposes for a group-subject on a date. Creating
 * classes is still done by applying an AI proposal (ApplyProposal), which
 * uses the same pre-fill.
 */
class ClassSessionScheduleController extends Controller
{
    public function update(UpdateClassSessionScheduleRequest $request, ClassSession $classSession): JsonResponse
    {
        $classSession->update($request->validated());

        return response()->json(['data' => [
            'id' => $classSession->id,
            'date' => $classSession->date->toDateString(),
            'start_time' => $classSession->start_time,
            'room' => $classSession->room,
        ]]);
    }

    public function suggestedSlot(SuggestedSlotRequest $request, TimetableSlotSuggester $suggester): JsonResponse
    {
        $slot = $suggester->suggest(
            $request->user(),
            (int) $request->validated('group_id'),
            (int) $request->validated('subject_id'),
            $request->classDate(),
            $request->validated('class_session_id') !== null ? (int) $request->validated('class_session_id') : null,
        );

        return response()->json([
            'data' => $slot === null ? null : [
                'timetable_slot_id' => $slot->id,
                'start_time' => $slot->start_time,
                'room' => $slot->room,
            ],
        ]);
    }
}
