<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning (or removing) a teacher-subject pair in a group. Director-only:
 * mirrors GroupPolicy::update (the director owns the group's staffing). The
 * teacher and subject must both belong to the current school.
 */
class StoreGroupTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Group $group */
        $group = $this->route('group');

        return $this->user()->can('update', $group);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = Tenancy::schoolId();

        return [
            'teacher_id' => [
                'required', 'integer',
                // Must be a user in this school. (Role is enforced by product
                // convention; only teachers are assigned, but any staff row is
                // schema-valid — the UI only offers teachers.)
                Rule::exists('users', 'id')->where('school_id', $schoolId),
            ],
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ],
        ];
    }
}
