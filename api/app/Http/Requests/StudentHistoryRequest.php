<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use App\Enums\StudentFileSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/students/{student}/history — filters of the student-file change
 * history. Authorization: StudentPolicy::viewChangeHistory (psychopedagogy
 * and direction of the student's school only).
 */
class StudentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewChangeHistory', $this->route('student'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'section' => ['sometimes', 'nullable', Rule::in(StudentFileSection::values())],
            'action' => ['sometimes', 'nullable', Rule::enum(AuditAction::class)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
