<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFollowUpPeople;
use App\Models\ScheduledFollowUp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a participant or a general responsible to a follow-up (ClickUp
 * 86e3qkwna). Which one is decided by the route: participants can be added by
 * any responsible (ScheduledFollowUpPolicy::addParticipant), general
 * responsibles only by general responsibles (::addGeneralResponsible). The
 * person must already see the student.
 */
class AddFollowUpMemberRequest extends FormRequest
{
    use ValidatesFollowUpPeople;

    public function authorize(): bool
    {
        $ability = $this->routeIs('follow-ups.general-responsibles.store') ? 'addGeneralResponsible' : 'addParticipant';

        return $this->user()->can($ability, $this->followUp());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', $this->followerRule($this->followUp()->student)],
        ];
    }

    protected function followUp(): ScheduledFollowUp
    {
        return $this->route('followUp');
    }
}
