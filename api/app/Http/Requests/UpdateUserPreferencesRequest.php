<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The signed-in person's own display preferences (ClickUp 1dfd3mc4zpk). It
 * only ever writes the caller's own row, so any authenticated user may send
 * it; what each preference changes is decided where it is read.
 */
class UpdateUserPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'show_today_assessments_total' => ['required', 'boolean'],
        ];
    }
}
