<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFollowUpPeople;
use App\Models\ScheduledFollowUp;
use App\Policies\ScheduledFollowUpPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Withdraw from a follow-up (ClickUp 86e3qkwna). Authorization:
 * ScheduledFollowUpPolicy::withdraw (an active member with no pending stage).
 * The reason is required. The only general responsible must designate who
 * takes over (`new_general_responsible_id`), someone else who sees the
 * student; they are designated directly, without an acceptance step.
 */
class WithdrawFromFollowUpRequest extends FormRequest
{
    use ValidatesFollowUpPeople;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('withdraw', $this->followUp());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'new_general_responsible_id' => [
                Rule::requiredIf($this->isOnlyGeneralResponsible()),
                'nullable',
                'integer',
                Rule::notIn([$this->user()->id]),
                $this->followerRule($this->followUp()->student),
            ],
        ];
    }

    public function isOnlyGeneralResponsible(): bool
    {
        $followUp = $this->followUp()->loadMissing('members');
        $generals = $followUp->members->filter->isGeneralResponsible();

        return $generals->count() === 1
            && app(ScheduledFollowUpPolicy::class)->isGeneralResponsible($this->user(), $followUp);
    }

    protected function followUp(): ScheduledFollowUp
    {
        return $this->route('followUp');
    }
}
