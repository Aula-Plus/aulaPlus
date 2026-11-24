<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Models\School;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Ver ejemplo» (screen 24, P24-JUI-02; ClickUp 1dfd3mc4bh6): Direction picks
 * a group of its school and one of the subjects taught in it. Only the
 * subject's name and the group's unit names reach the AI — never a student.
 */
class StoreJudgmentStylePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::query()->find($this->user()->school_id);

        return $school !== null && $this->user()->can('manageAIInstructions', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $taught = Group::query()->whereKey($this->integer('group_id'))->first()
                    ?->teachers()->wherePivot('subject_id', $this->integer('subject_id'))->exists();

                if (! $taught) {
                    $validator->errors()->add('subject_id', 'The subject is not taught in this group.');
                }
            },
        ];
    }
}
