<?php

namespace App\Http\Requests;

use App\Models\FollowUpStage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Close a pending stage with what was done (ClickUp 86e3qkwna).
 * Authorization: FollowUpStagePolicy::close — its responsible or a general
 * responsible.
 */
class CloseFollowUpStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var FollowUpStage $stage */
        $stage = $this->route('stage');

        return $this->user()->can('close', $stage);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'conclusion' => ['required', 'string', 'max:5000'],
        ];
    }
}
