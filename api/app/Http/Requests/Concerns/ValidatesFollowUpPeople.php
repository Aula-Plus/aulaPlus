<?php

namespace App\Http\Requests\Concerns;

use App\Models\Student;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use Closure;

/**
 * Shared rule for every person picked on a follow-up (stage responsible,
 * participant, general responsible): an existing, not deactivated user who
 * can already see the student — same rule as ScheduledFollowUpPolicy::
 * canFollow and the candidates endpoint. User is not tenant-scoped, so the
 * school match is part of the policy check.
 */
trait ValidatesFollowUpPeople
{
    protected function followerRule(Student $student): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($student): void {
            $user = is_numeric($value) ? User::query()->find((int) $value) : null;

            if ($user === null || $user->disabled_at !== null || ! app(ScheduledFollowUpPolicy::class)->canFollow($user, $student)) {
                $fail('The selected person cannot follow this student.');
            }
        };
    }
}
