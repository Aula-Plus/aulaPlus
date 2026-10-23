<?php

namespace App\Http\Requests\AccommodationProposals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Psychopedagogy confirms a direction proposal (ClickUp 86e3qkwkk). It may
 * set «Vigente desde» on the way; absent, the accommodation is in force from
 * the day it is confirmed, and «Origen» (the client prefills it with who
 * proposed it). Content edits go through the ordinary PATCH first.
 */
class ConfirmAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('confirm', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
