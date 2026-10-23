<?php

namespace App\Http\Requests;

use App\Models\ScheduledFollowUp;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a plain participant, with an optional reason (ClickUp 86e3qkwna).
 * Authorization: ScheduledFollowUpPolicy::removeParticipant.
 */
class RemoveFollowUpParticipantRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var ScheduledFollowUp $followUp */
        $followUp = $this->route('followUp');
        /** @var User $target */
        $target = $this->route('user');

        return Gate::forUser($this->user())->inspect('removeParticipant', [$followUp, $target]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
