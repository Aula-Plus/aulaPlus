<?php

namespace App\Http\Requests;

use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Once chosen, a level can be changed but not emptied again.
            'education_level_id' => ['sometimes', 'required', 'integer', Rule::exists('education_levels', 'id')],
            'school_grade_id' => ['sometimes', 'nullable', 'integer', Rule::exists('school_grades', 'id')],
            'school_year' => ['sometimes', 'integer'],
            'group_profile' => ['sometimes', 'nullable', 'array'],
            'related_documents' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Screen 26: a group of a closed year stays in its year (even while
     * loading is enabled in it), and no group is moved into a closed year.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $group = $this->route('group');
                if (! $this->has('school_year') || $validator->errors()->has('school_year')
                    || $this->integer('school_year') === (int) $group->school_year) {
                    return;
                }
                $calendar = app(SchoolYearCalendar::class);
                $lastClosed = $calendar->lastClosedYear((int) $group->school_id);
                if ($calendar->isInClosedYear($group)) {
                    $validator->errors()->add('school_year', 'A group of a closed school year stays in its year.');
                } elseif ($lastClosed !== null && $this->integer('school_year') <= $lastClosed) {
                    $validator->errors()->add('school_year', 'The school year is closed: groups cannot be moved into it.');
                }
            },
        ];
    }
}
