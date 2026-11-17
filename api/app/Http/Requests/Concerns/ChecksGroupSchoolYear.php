<?php

namespace App\Http\Requests\Concerns;

use App\Models\Group;
use Illuminate\Validation\Validator;

/**
 * A student's enrolment carries the school year of the pivot row. It must be
 * the group's own year: otherwise a group of a closed year could be attached
 * under another year's row and dodge the closed-year guard (screen 26).
 */
trait ChecksGroupSchoolYear
{
    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('group_id')) {
                    return;
                }

                $group = Group::query()->find((int) $this->input('group_id'));
                if ($group !== null && (int) $group->school_year !== (int) $this->input('school_year')) {
                    $validator->errors()->add('school_year', 'The school year must be the group\'s own school year.');
                }
            },
        ];
    }
}
