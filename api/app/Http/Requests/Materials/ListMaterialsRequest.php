<?php

namespace App\Http\Requests\Materials;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The hard prefilter of screen 19 (P19-DEC-02): subject and grade are chosen
 * before any result. `grade_id` = "none" is the «Sin grado» folder.
 */
class ListMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'tab' => ['required', 'in:generated,catalog'],
            'subject_id' => ['required', 'integer'],
            'grade_id' => ['required', 'regex:/^(none|\d+)$/'],
        ];
    }

    public function gradeId(): ?int
    {
        $grade = (string) $this->validated('grade_id');

        return $grade === 'none' ? null : (int) $grade;
    }
}
