<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An adjustment the AI missed, added by psychopedagogy while reviewing a
 * report. It enters as a pending proposal (basis `manual`) and is approved
 * like any other. Authorization happens in the controller
 * (ReportDocumentPolicy::manage).
 */
class StoreAccommodationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(AccommodationCategory::values())],
            'description' => ['required', 'string', 'max:5000'],
            'focus_area' => ['required', 'string', 'max:255'],
            'source_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'source_fragment' => ['nullable', 'string', 'max:600'],
        ];
    }
}
