<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\AnnualPlan;
use App\Models\Group;
use App\Models\Subject;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher creates the annual plan of a group-subject they teach (screen 12).
 * AnnualPlanPolicy::create checks the role; the "teaches this subject in this
 * group" relation is checked here, like StoreAssessmentRequest does.
 */
class StoreAnnualPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AnnualPlan::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = Tenancy::schoolId();

        return [
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->where('school_id', $schoolId)],
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $group = Group::find($this->integer('group_id'));
                    $subject = Subject::find($value);
                    if ($group === null || $subject === null || ! $this->user()->teachesSubjectInGroup($group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');

                        return;
                    }

                    $exists = AnnualPlan::query()
                        ->where('group_id', $group->id)
                        ->where('subject_id', $subject->id)
                        ->where('teacher_id', $this->user()->id)
                        ->where('year', $this->integer('year'))
                        ->whereNull('student_id')
                        ->exists();
                    if ($exists) {
                        $fail('There is already an annual plan for this group, subject and year.');
                    }
                },
            ],
            'curricular_framework_id' => [
                'required', 'integer',
                Rule::exists('school_curricular_framework', 'curricular_framework_id')
                    ->where('school_id', $schoolId)
                    ->where('active', true),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
