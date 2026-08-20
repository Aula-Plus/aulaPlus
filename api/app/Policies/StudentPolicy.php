<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * Reference policy for student records. Same two-layer pattern as GroupPolicy:
 * tenant isolation first, then role rules.
 *
 * Director and psychopedagogue: full read/write access, school-wide.
 * A teacher only sees students enrolled (group_student) in a group they lead
 * (group_teacher), and never through the full record — see
 * {@see self::viewClinicalProfile()} and the field-level filtering documented
 * on `StudentResource`: a teacher who passes `view` still only gets the basic
 * fields, never the clinical/learning profile.
 *
 * Out of scope (see CLAUDE.md / docs/prompts/02-roles-permisos.md §1): the
 * product brief also mentions `psychologist` and `at` (therapeutic companion)
 * roles with their own narrower clinical-profile access. Neither exists yet
 * (App\Enums\Role only has teacher/psychopedagogue/director). If/when they're
 * added, `viewClinicalProfile()` below is the method to revisit — it currently
 * assumes only director/psychopedagogue can see the full clinical profile.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Student $student): bool
    {
        if (! $this->sharesSchool($user, $student)) {
            return false;
        }

        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        return $user->teachesStudent($student);
    }

    /**
     * Field-level gate consumed by StudentResource (not an endpoint-level
     * check): whether the full clinical/learning profile (learning_profile,
     * individual_profile, tracking_notes, related_documents) may be included
     * in the response, as opposed to only the basic fields. A teacher who
     * passes `view` (because they teach the student) still fails this — they
     * see the roster fields only, never the clinical ones, even within their
     * own school.
     */
    public function viewClinicalProfile(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function update(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id;
    }
}
