<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Subject;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/class-sessions/suggested-slot — the time and room «Mis
 * horarios» proposes for a class of a group-subject on a date. Teachers only,
 * for a subject they teach in the group; it reads the caller's own timetable
 * and nobody else's.
 */
class SuggestedSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(Role::Teacher->value);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = Tenancy::schoolId();

        return [
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $group = Group::find($this->input('group_id'));
                    $subject = Subject::find($value);
                    if ($group === null || $subject === null || ! $this->user()->teachesSubjectInGroup($group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                }],
            'date' => ['required', 'date_format:Y-m-d'],
            // The class being edited, so it does not count as taking its own slot.
            'class_session_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $session = ClassSession::find($value);
                if ($session === null || ! $this->user()->can('update', $session)) {
                    $fail('The selected class session is invalid.');
                }
            }],
        ];
    }

    public function classDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('date'));
    }
}
