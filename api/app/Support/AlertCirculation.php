<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Who an alert can be handed to or shared with (ClickUp 86e3jpzdp, Eitán's
 * follow-up). The single source of truth for both the picker endpoint and the
 * FormRequests that validate a hand-off or a share, so the list the UI offers
 * and what the server accepts can never drift.
 *
 * A person can receive an alert from `$actor` when they are:
 *
 * - someone else, of the alert's school, active (not deactivated, invitation
 *   accepted — a person who can't log in would never pick it up);
 * - someone who already sees the student — the exact rule scheduled
 *   follow-ups use for their responsible/co-followers
 *   ({@see ScheduledFollowUpPolicy::canFollow()}): teaches the student, or is
 *   psychopedagogy/direction of that school. An alert never opens a student
 *   to someone who had no access to them;
 * - never teacher-to-teacher: "un docente solo puede pasarla o compartirla
 *   con Psicopedagogía o Dirección" and "a un docente solo le pueden llegar
 *   alertas de Psicopedagogía o de Dirección". So unless the actor is
 *   psychopedagogy/direction, the receiver must be.
 *
 * An alert whose student was removed (soft-deleted) reaches nobody new: there
 * is no student left to see, so nobody can receive it. Whoever is in charge
 * can still take it, leave it in observation, note it or close it.
 */
class AlertCirculation
{
    public function __construct(protected ScheduledFollowUpPolicy $studentAccess) {}

    public function canReceive(User $actor, User $candidate, Alert $alert): bool
    {
        $student = $alert->student;

        return $student !== null
            && $candidate->id !== $actor->id
            && $candidate->school_id === $alert->school_id
            && $candidate->disabled_at === null
            && $candidate->password !== null
            && ($this->isSchoolWide($actor) || $this->isSchoolWide($candidate))
            && $this->studentAccess->canFollow($candidate, $student);
    }

    /**
     * Everyone `$actor` may hand the alert to or share it with, by name.
     *
     * The same rule as {@see self::canReceive()}, resolved in a single query
     * instead of once per staff member: psychopedagogy/direction always see
     * the student, so they qualify on their role; a teacher qualifies only
     * when the actor is psychopedagogy/direction AND they teach the student
     * (the {@see User::teachesStudent()} join, done in SQL).
     *
     * @return Collection<int, User>
     */
    public function candidates(User $actor, Alert $alert): Collection
    {
        $student = $alert->student;

        if ($student === null || $student->school_id !== $alert->school_id) {
            return collect();
        }

        $actorIsSchoolWide = $this->isSchoolWide($actor);

        return User::query()
            ->where('school_id', $alert->school_id)
            ->whereKeyNot($actor->id)
            ->whereNull('disabled_at')
            ->whereNotNull('password')
            ->where(function (Builder $query) use ($actorIsSchoolWide, $student): void {
                $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', Role::schoolWideValues()));

                if ($actorIsSchoolWide) {
                    $query->orWhereExists(fn (QueryBuilder $exists) => $exists
                        ->selectRaw('1')
                        ->from('group_teacher')
                        ->join('group_student', 'group_student.group_id', '=', 'group_teacher.group_id')
                        ->whereColumn('group_teacher.teacher_id', 'users.id')
                        ->where('group_student.student_id', $student->id));
                }
            })
            ->with('roles:id,name')
            ->orderBy('name')
            ->get();
    }

    protected function isSchoolWide(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }
}
