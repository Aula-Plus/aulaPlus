<?php

namespace App\Http\Requests;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the caller's own school's comment trend threshold ("at least
 * `min_count` comments of the same category in `days` days"). Director only,
 * via SchoolPolicy::updateCommentTrendSettings.
 */
class UpdateCommentTrendSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::query()->find($this->user()->school_id);

        return $school !== null && $this->user()->can('updateCommentTrendSettings', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'min_count' => ['required', 'integer', 'min:2', 'max:50'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
