<?php

namespace App\Services\Calendar;

use App\Models\ClassSession;
use App\Models\TimetableSlot;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Proposes the time and room of a class from the teacher's own «Mis horarios»
 * (ClickUp 1dfd3mc4bgh). A default value, never a generator: it creates no
 * class and is always editable where it is shown.
 *
 * Rule: among the teacher's slots for that group-subject on the class's
 * weekday (earliest first), propose the first one that has no class of that
 * group-subject already created that day at that time («the free one»); when
 * every slot is taken, the first of the day. No slot that weekday → null.
 */
class TimetableSlotSuggester
{
    public function suggest(
        User $teacher,
        int $groupId,
        int $subjectId,
        CarbonInterface $date,
        ?int $ignoreClassSessionId = null,
    ): ?TimetableSlot {
        $slots = TimetableSlot::query()
            ->where('teacher_id', $teacher->id)
            ->where('group_id', $groupId)
            ->where('subject_id', $subjectId)
            ->where('weekday', $date->isoWeekday())
            ->orderBy('start_time')
            ->get();

        if ($slots->isEmpty()) {
            return null;
        }

        $taken = ClassSession::query()
            ->where('group_id', $groupId)
            ->where('subject_id', $subjectId)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('start_time')
            ->when($ignoreClassSessionId, fn ($query) => $query->whereKeyNot($ignoreClassSessionId))
            ->get()
            ->pluck('start_time')
            ->all();

        return $slots->first(fn (TimetableSlot $slot): bool => ! in_array($slot->start_time, $taken, true))
            ?? $slots->first();
    }

    /**
     * Fill the time and room of a class that has none from the teacher's
     * timetable. Only fills what is empty; never overwrites what the teacher
     * wrote.
     */
    public function prefill(ClassSession $classSession, User $teacher): void
    {
        if ($classSession->start_time !== null || $classSession->subject_id === null) {
            return;
        }

        $slot = $this->suggest($teacher, $classSession->group_id, $classSession->subject_id, $classSession->date, $classSession->id);

        if ($slot === null) {
            return;
        }

        $classSession->start_time = $slot->start_time;
        $classSession->room ??= $slot->room;
        $classSession->save();
    }
}
