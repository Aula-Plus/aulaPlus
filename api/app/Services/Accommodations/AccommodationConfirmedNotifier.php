<?php

namespace App\Services\Accommodations;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\User;
use App\Notifications\AccommodationConfirmedNotification;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the teachers an accommodation reaches that it is now in force (screen
 * 22, ClickUp 1dfd3mc4bgw): when psychopedagogy confirms a direction proposal,
 * or when an accommodation is registered already in force.
 *
 * Recipients are the teachers who teach the student's groups AND may see this
 * accommodation (Accommodation::isVisibleTo: a general one reaches every
 * teacher of the student; one scoped to subjects only those who teach them).
 * The person who made it effective is never notified about their own action.
 * Runs after the commit, so a rolled-back confirmation never notifies anyone.
 */
class AccommodationConfirmedNotifier
{
    public function notify(Accommodation $accommodation, User $actor): void
    {
        DB::afterCommit(function () use ($accommodation, $actor): void {
            $accommodation->refresh();

            if (! $accommodation->active || ! $accommodation->isConfirmed()) {
                return;
            }

            $this->recipients($accommodation, $actor)
                ->each(fn (User $teacher) => $teacher->notify(new AccommodationConfirmedNotification($accommodation)));
        });
    }

    /**
     * @return Collection<int, User>
     */
    protected function recipients(Accommodation $accommodation, User $actor): Collection
    {
        return User::query()
            ->where('school_id', $accommodation->school_id)
            ->whereKeyNot($actor->getKey())
            ->role(Role::Teacher->value)
            ->whereExists(fn (QueryBuilder $teaches) => $teaches
                ->selectRaw('1')
                ->from('group_teacher')
                ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                ->whereColumn('group_teacher.teacher_id', 'users.id')
                ->where('group_student.student_id', $accommodation->student_id))
            ->get()
            ->filter(fn (User $teacher) => $accommodation->isVisibleTo($teacher))
            ->values();
    }
}
