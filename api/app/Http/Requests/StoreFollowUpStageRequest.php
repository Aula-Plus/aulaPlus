<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFollowUpPeople;
use App\Models\ScheduledFollowUp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a stage to a follow-up (ClickUp 86e3qkwna). Authorization:
 * ScheduledFollowUpPolicy::addStage — any responsible (general or of some
 * stage). The stage responsible must already see the student.
 */
class StoreFollowUpStageRequest extends FormRequest
{
    use ValidatesFollowUpPeople;

    public function authorize(): bool
    {
        return $this->user()->can('addStage', $this->followUp());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:5000'],
            'responsible_id' => ['required', 'integer', $this->followerRule($this->followUp()->student)],
            'due_date' => ['required', 'date'],
        ];
    }

    protected function followUp(): ScheduledFollowUp
    {
        return $this->route('followUp');
    }
}
