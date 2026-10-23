<?php

namespace App\Http\Requests\AccommodationProposals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Psychopedagogy returns a proposal to direction for corrections, with a
 * comment or counter-proposal (ClickUp 86e3qkwkk). The note may hold
 * sensitive content about a minor: stored, never logged (CLAUDE.md rule 11).
 */
class ReturnAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('returnProposal', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
