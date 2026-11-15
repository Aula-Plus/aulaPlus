<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/**
 * «Revisión anual» (screen 06, ClickUp 1dfd3mc4bgc): only psychopedagogy
 * enters «Ajustes y PTP», so only psychopedagogy lists the reviews and marks
 * one (P06-DEC-07). The list is a roster of students with accommodations —
 * exactly what the screen's red line keeps away from teachers.
 */
class StudentAnnualReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    public function create(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }
}
