<?php

namespace App\Http\Requests;

use App\Enums\Weekday;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST, with `student_id`) or edit (PATCH) a therapeutic companion
 * record (screen 10 › Acompañantes). Authorization lives in the controller
 * (TherapeuticCompanionPolicy), which needs the student first.
 */
class TherapeuticCompanionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'student_id' => $creating
                ? ['required', 'integer', Rule::exists('students', 'id')->where('school_id', Tenancy::schoolId())->whereNull('deleted_at')]
                : ['prohibited'],
            'name' => [$required, 'string', 'max:120'],
            'weekly_hours' => [$required, 'numeric', 'min:0.5', 'max:60', 'multiple_of:0.5'],
            'days' => [$required, 'array', 'min:1', 'max:6'],
            'days.*' => ['string', 'distinct', Rule::in(Weekday::values())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
