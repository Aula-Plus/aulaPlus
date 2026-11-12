<?php

namespace App\Http\Requests;

use App\Models\ClassSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/class-sessions/{classSession} — the time and room of a class
 * (ClickUp 1dfd3mc4bgh). Both optional and editable at any moment, also after
 * the class is confirmed: they are what the teacher wrote. Only the owning
 * teacher (ClassSessionPolicy::update).
 */
class UpdateClassSessionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ClassSession $classSession */
        $classSession = $this->route('classSession');

        return $this->user()->can('update', $classSession);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
