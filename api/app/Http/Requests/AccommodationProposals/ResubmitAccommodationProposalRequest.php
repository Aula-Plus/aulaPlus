<?php

namespace App\Http\Requests\AccommodationProposals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Direction sends a returned proposal back to review, optionally answering
 * psychopedagogy's comment (ClickUp 86e3qkwkk).
 */
class ResubmitAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resubmit', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
