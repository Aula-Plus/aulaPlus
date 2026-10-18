<?php

namespace App\Http\Requests;

use App\Models\ScheduledFollowUp;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Resolve a scheduled follow-up: an explicit action with a general conclusion
 * (`resolution_note`, required), distinct from reading the assignment notice.
 * Authorization delegates to ScheduledFollowUpPolicy::resolve — only a general
 * responsible, and only once no stage is pending (ClickUp 86e3qkwna); the
 * denial carries the reason.
 */
class ResolveScheduledFollowUpRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var ScheduledFollowUp $followUp */
        $followUp = $this->route('followUp');

        return Gate::forUser($this->user())->inspect('resolve', $followUp);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];
    }
}
