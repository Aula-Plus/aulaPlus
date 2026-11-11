<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ClassSession;
use App\Models\User;

/**
 * ClassSession has no `teacher_id`/creator column of its own (see
 * docs/prompts/01-modelo-dominio-multitenancy.md — the field isn't in the
 * table). Assumption (documented in the PR): "the owning teacher" for a
 * class session is the teacher who leads its Group (group_teacher pivot),
 * consistent with how Group ownership is checked everywhere else in this
 * session. Director/psychopedagogue get read-only, school-wide visibility.
 *
 * Since ClickUp 1dfd3mc4bgh a class may carry its `subject_id`: then only the
 * teacher assigned that subject in the group owns it (a group with several
 * teachers no longer lets one edit another's class). A class without a
 * subject keeps the previous rule (any teacher who leads the group).
 */
class ClassSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClassSession $classSession): bool
    {
        if (! $this->sharesSchool($user, $classSession)) {
            return false;
        }

        return $this->isOwner($user, $classSession)
            || $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function update(User $user, ClassSession $classSession): bool
    {
        return $this->sharesSchool($user, $classSession) && $this->isOwner($user, $classSession);
    }

    public function delete(User $user, ClassSession $classSession): bool
    {
        return $this->sharesSchool($user, $classSession) && $this->isOwner($user, $classSession);
    }

    protected function isOwner(User $user, ClassSession $classSession): bool
    {
        $classSession->loadMissing(['group', 'subject']);

        if ($classSession->subject !== null) {
            return $user->teachesSubjectInGroup($classSession->group, $classSession->subject);
        }

        return $user->teachesGroup($classSession->group);
    }

    protected function sharesSchool(User $user, ClassSession $classSession): bool
    {
        return $user->school_id === $classSession->school_id;
    }
}
