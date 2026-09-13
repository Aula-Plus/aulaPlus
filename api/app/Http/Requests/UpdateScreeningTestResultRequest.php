<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /screening-test-results/{result} (loading a score).
 * Authorization delegates to ScreeningTestResultPolicy::update (psychopedagogy
 * only, same school).
 */
class UpdateScreeningTestResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('result'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'numeric'],
        ];
    }
}
