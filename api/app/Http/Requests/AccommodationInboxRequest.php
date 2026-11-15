<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Http\Controllers\AccommodationInboxController;
use App\Models\Accommodation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the «Ajustes y PTP» inbox (screen 06, P06-AJU-01): status, group
 * (of the current school year) and accommodation type, where `none` is the
 * «Sin tipo» of accommodations loaded before the type existed. Psychopedagogy
 * only (AccommodationPolicy::viewInbox).
 */
class AccommodationInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewInbox', Accommodation::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(AccommodationInboxController::STATUSES)],
            'group_id' => [
                'sometimes',
                'integer',
                Rule::exists('groups', 'id')->where('school_id', $this->user()->school_id),
            ],
            'category' => ['sometimes', 'string', Rule::in([...AccommodationCategory::values(), 'none'])],
        ];
    }
}
