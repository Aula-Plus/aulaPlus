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
 * Who an alert can be handed to (ClickUp 86e3jpzdp, Eitán's follow-up; kept
 * by the review of 14/10). The single source of truth for both the picker
 * endpoint and ChooseAlertOutcomeRequest, so the list the UI offers and what
 * the server accepts can never drift. Handing it off is a transfer to exactly
 * one person.
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
 * - never teacher-to-teacher: "un docente solo puede pasarla a
 *   Psicopedagogía o Dirección" and "a un docente solo le pueden llegar
 *   alertas de Psicopedagogía o de Dirección". So unless the actor is
 *   psychopedagogy/direction, the receiver must be;
 * - for a type that never reaches teachers (the «Señal de dificultad» alert,
 *   ClickUp 86e3qkwpn, {@see AlertRouting::allowsTeachers()}), never a
 *   teacher at all: only psychopedagogy or direction.
 *
 * An alert whose student was removed (soft-deleted) reaches nobody new: there
 * is no student left to see, so nobody can receive it. Whoever has it can
 * still take it, leave it in observation, note it or close it.
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
            && (AlertRouting::allowsTeachers($alert->type) || $this->isSchoolWide($candidate))
            && $this->studentAccess->canFollow($candidate, $student);
    }

    /**
     * Everyone `$actor` may hand the alert to, by name.
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

        // A teacher can receive it only from psychopedagogy/direction, and
        // never an alert type that doesn't reach teachers.
        $teachersQualify = $this->isSchoolWide($actor) && AlertRouting::allowsTeachers($alert->type);

        return User::query()
            ->where('school_id', $alert->school_id)
            ->whereKeyNot($actor->id)
            ->whereNull('disabled_at')
            ->whereNotNull('password')
            ->where(function (Builder $query) use ($teachersQualify, $student): void {
                $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', Role::schoolWideValues()));

                if ($teachersQualify) {
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
