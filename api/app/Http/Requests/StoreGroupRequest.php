<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Group::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            // ClickUp 86e3t4qjh: the level is mandatory (it places the group in
            // a screening's population); the grade is optional (a workshop or a
            // mixed group doesn't fit the list). Two separate data: the grade
            // only suggests a level in the UI, it never forces one.
            'education_level_id' => ['required', 'integer', Rule::exists('education_levels', 'id')],
            'school_grade_id' => ['nullable', 'integer', Rule::exists('school_grades', 'id')],
            'school_year' => ['required', 'integer'],
            'group_profile' => ['nullable', 'array'],
            'related_documents' => ['nullable', 'array'],
        ];
    }

    /**
     * Screen 26: no group is added to a closed year (its groups are history).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('school_year')) {
                    return;
                }
                $lastClosed = app(SchoolYearCalendar::class)->lastClosedYear((int) $this->user()->school_id);
                if ($lastClosed !== null && $this->integer('school_year') <= $lastClosed) {
                    $validator->errors()->add('school_year', 'The school year is closed: groups cannot be added to it.');
                }
            },
        ];
    }
}
