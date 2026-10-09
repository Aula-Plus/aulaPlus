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
            // Free label of the screen the user was on (route path or title).
            'screen' => ['nullable', 'string', 'max:255'],
        ];
    }
}
