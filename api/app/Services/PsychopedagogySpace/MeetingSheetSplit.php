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
use Illuminate\Http\Exceptions\HttpResponseException;
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
 * The fragments are written by hand: an AI proposal step waits until family
 * data exists (product answer C, ClickUp 1dfd3mc4bge). Confirmation stays the
 * only way into a file.
 *
 * A rejected confirmation answers 422 with a stable `reason`
 * (`already_confirmed`, `no_fragments`, `student_removed`) so the SPA can say
 * what to do; the `message` is already the Spanish text to show.
 */
class MeetingSheetSplit
{
    public function confirm(MeetingSheet $sheet, User $confirmer): MeetingSheet
    {
        DB::transaction(function () use ($sheet, $confirmer): void {
            // Lock the sheet and read it again under the lock: a second
            // confirmation (another tab, another psychopedagogue) or an
            // «Editar antes» racing this one must not land the fragments twice
            // or swap them while they are being copied into the files.
            $locked = MeetingSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($locked->isConfirmed()) {
                static::reject('already_confirmed');
            }

            $sheet->setRawAttributes($locked->getAttributes(), true);
            $sheet->load(['sheetType', 'fragments.student', 'attendees', 'actions.owner']);

            if ($sheet->fragments->isEmpty()) {
                static::reject('no_fragments');
            }
            // A discussed student removed while the sheet was a draft.
            if ($sheet->fragments->contains(fn (MeetingSheetFragment $fragment) => $fragment->student === null)) {
                static::reject('student_removed');
            }

            foreach ($sheet->fragments as $fragment) {
                $comment = new Comment([
                    'author_id' => $confirmer->id,
                    'commentable_type' => Student::class,
                    'commentable_id' => $fragment->student_id,
                    // Where it comes from, in the file's own words: the
                    // school's type name (data) and the date, written as the
                    // app writes dates («20 nov 2026»). `held_on` is a calendar
                    // date (the school's local day), so no timezone shift.
                    'content' => $sheet->sheetType->name.' · '.SchoolTime::shortDate($sheet->held_on).' — '.$fragment->content,
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

    /**
     * Ends the request with a 422 the SPA can tell apart (rolls back the
     * transaction it is thrown from).
     */
    public static function reject(string $reason): never
    {
        $messages = [
            'already_confirmed' => 'Esta planilla ya se confirmó.',
            'no_fragments' => 'Escribí al menos un fragmento antes de confirmar.',
            'student_removed' => 'Uno de los alumnos ya no está inscripto. Usá «Editar antes» para sacarlo del reparto.',
        ];

        throw new HttpResponseException(response()->json([
            'message' => $messages[$reason],
            'reason' => $reason,
        ], 422));
    }
}
