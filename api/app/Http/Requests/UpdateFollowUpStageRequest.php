<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFollowUpPeople;
use App\Models\FollowUpStage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a pending stage (ClickUp 86e3qkwna). Authorization:
 * FollowUpStagePolicy::update — the stage's responsible or a general
 * responsible; a plain participant cannot modify someone else's work.
 */
class UpdateFollowUpStageRequest extends FormRequest
{
    use ValidatesFollowUpPeople;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->stage());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'responsible_id' => ['sometimes', 'required', 'integer', $this->followerRule($this->stage()->followUp->student)],
            'due_date' => ['sometimes', 'required', 'date'],
        ];
    }

    protected function stage(): FollowUpStage
    {
        return $this->route('stage');
    }
}
