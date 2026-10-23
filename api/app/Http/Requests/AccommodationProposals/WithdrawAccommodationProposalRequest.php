<?php

namespace App\Http\Requests\AccommodationProposals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Direction withdraws its proposal while under review, with a brief reason
 * (ClickUp 86e3qkwkk).
 */
class WithdrawAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('withdraw', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
