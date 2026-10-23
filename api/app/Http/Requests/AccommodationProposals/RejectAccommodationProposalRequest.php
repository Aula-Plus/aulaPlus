<?php

namespace App\Http\Requests\AccommodationProposals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Psychopedagogy rejects a proposal for good, with a mandatory reason that
 * direction can read (ClickUp 86e3qkwkk).
 */
class RejectAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
