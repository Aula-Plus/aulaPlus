<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFollowUpPeople;
use App\Models\FollowUpStage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Subdivide a pending stage into two or more (ClickUp 86e3qkwna). Same
 * authorization as editing it (FollowUpStagePolicy::split). Each new stage
 * has its own description, responsible (who must see the student) and date.
 */
class SplitFollowUpStageRequest extends FormRequest
{
    use ValidatesFollowUpPeople;

    public function authorize(): bool
    {
        return $this->user()->can('split', $this->stage());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stages' => ['required', 'array', 'min:2', 'max:10'],
            'stages.*.description' => ['required', 'string', 'max:5000'],
            'stages.*.responsible_id' => ['required', 'integer', $this->followerRule($this->stage()->followUp->student)],
            'stages.*.due_date' => ['required', 'date'],
        ];
    }

    protected function stage(): FollowUpStage
    {
        return $this->route('stage');
    }
}
