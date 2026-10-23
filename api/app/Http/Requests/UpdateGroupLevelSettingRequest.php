<?php

namespace App\Http\Requests;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Direction decides whether psychopedagogy may also set group levels
 * (SchoolPolicy::updateGroupLevelDelegation).
 */
class UpdateGroupLevelSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::query()->find($this->user()->school_id);

        return $school !== null && $this->user()->can('updateGroupLevelDelegation', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'psychopedagogue_can_set_group_level' => ['required', 'boolean'],
        ];
    }
}
