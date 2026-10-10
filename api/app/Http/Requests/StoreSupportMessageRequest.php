<?php

namespace App\Http\Requests;

use App\Models\SupportMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SupportMessage::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(SupportMessage::kinds())],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            // Route path of the screen the user was on (stored).
            'screen' => ['nullable', 'string', 'max:255'],
            // Generic name of that screen ("Perfil del alumno"), only used to
            // make the notification email readable; not stored.
            'screen_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
