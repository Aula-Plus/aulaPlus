<?php

namespace App\Services\PsychopedagogySpace;

use App\Enums\MeetingSheetStatus;
use App\Http\Controllers\StudentTrackingController;
use App\Models\Comment;
use App\Models\MeetingSheet;
use App\Models\MeetingSheetFragment;
use App\Models\Student;
use App\Models\User;
use App\Notifications\MeetingActionAssignedNotification;
use App\Notifications\MeetingSheetFragmentNotification;
use App\Support\SchoolTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * «Confirmar el reparto» of a meeting sheet (screen 10, P10-DEC-06/07).
 *
 * Each fragment becomes an observation in its student's file, with the scope
 * psychopedagogy chose for it (an observation is the same thing as a session
 * note: who reads it is what the scope decides, P10-DEC-10). Only that exact
 * fragment leaves the sheet; the rest stays psychopedagogy's (red line).
 *
 * Then it notifies whoever can read what landed (P10-DEC-07): the people who
 * were there and the current teachers of each discussed student, filtered by
 * the observation's own visibility rule — so a «solo Psicopedagogía»
 * fragment reaches no teacher. Each action owner is notified and gets the
 * action as a task in their Inicio.
 *
 * The fragments are written by hand for now. An AI proposal step (pending a
 * product answer about adults' names, ClickUp 1dfd3mc4bge) would fill the
 * draft fragments before this; confirmation stays the only way into a file.
 */
class MeetingSheetSplit
{
    public function confirm(MeetingSheet $sheet, User $confirmer): MeetingSheet
    {
        $sheet->loadMissing(['sheetType', 'fragments.student', 'attendees', 'actions.owner']);

        DB::transaction(function () use ($sheet, $confirmer): void {
            foreach ($sheet->fragments as $fragment) {
                $comment = new Comment([
                    'author_id' => $confirmer->id,
                    'commentable_type' => Student::class,
                    'commentable_id' => $fragment->student_id,
                    // Where it comes from, in the file's own words: the
                    // school's type name (data) and the date.
                    'content' => $sheet->sheetType->name.' · '.$sheet->held_on->format('d/m/Y').' — '.$fragment->content,
                    ...$fragment->scope->commentAttributes(),
                ]);
                $comment->save();
                $fragment->forceFill(['comment_id' => $comment->id])->save();
                $fragment->setRelation('comment', $comment);
            }

            $sheet->forceFill([
                'status' => MeetingSheetStatus::Confirmed,
                'confirmed_by_id' => $confirmer->id,
                'confirmed_at' => now(),
            ])->save();
        });

        foreach ($sheet->fragments as $fragment) {
            Cache::forget(StudentTrackingController::cacheKey($fragment->student_id));
            $this->recipientsOf($fragment, $sheet->attendees, $confirmer)
                ->each(fn (User $user) => $user->notify(new MeetingSheetFragmentNotification($fragment)));
        }

        foreach ($sheet->actions as $action) {
            if ($action->owner_id !== $confirmer->id && $action->owner?->disabled_at === null) {
                $action->owner?->notify(new MeetingActionAssignedNotification($action));
            }
        }

        return $sheet;
    }

    /**
     * The attendees and the student's current teachers who can read the
     * observation the fragment became.
     *
     * @param  Collection<int, User>  $attendees
     * @return Collection<int, User>
     */
    public function recipientsOf(MeetingSheetFragment $fragment, Collection $attendees, User $confirmer): Collection
    {
        $student = $fragment->student;
        $year = SchoolTime::today()->year;

        $teachers = User::query()
            ->whereHas('groups', fn ($groups) => $groups->whereHas(
                'students',
                fn ($students) => $students->whereKey($student->id)->where('group_student.school_year', $year),
            ))
            ->get();

        return $attendees->concat($teachers)
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $confirmer->id || $user->disabled_at !== null)
            ->filter(fn (User $user) => $user->school_id === $student->school_id
                && $user->can('view', $student)
                && $fragment->comment->isVisibleTo($user))
            ->values();
    }
}
