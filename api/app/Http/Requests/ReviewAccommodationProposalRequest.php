<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Http\Requests\Concerns\ValidatesAccommodationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Psychopedagogy's decision on a proposed accommodation: approve it as is,
 * approve it modified (any of the editable fields), or reject it. Fields are
 * optional so "approve as is" sends none; the scope fields are the same as when
 * creating an accommodation. Authorization happens in the controller
 * (ReportDocumentPolicy::manage).
 */
class ReviewAccommodationProposalRequest extends FormRequest
{
    use ValidatesAccommodationScope;

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
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', Rule::in(AccommodationCategory::values())],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'focus_area' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->accommodationScopeRules(),
        ];
    }
}
