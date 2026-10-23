<?php

namespace App\Http\Requests;

use App\Models\FollowUpStage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a progress note or clarification to a stage (ClickUp 86e3qkwna).
 * Authorization: FollowUpStagePolicy::addNote — its responsible or a general
 * responsible.
 */
class StoreFollowUpStageNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var FollowUpStage $stage */
        $stage = $this->route('stage');

        return $this->user()->can('addNote', $stage);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
