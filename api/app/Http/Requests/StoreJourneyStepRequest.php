<?php

namespace App\Http\Requests;

use App\Enums\JourneyContentType;
use App\Enums\JourneyStep;
use App\Models\Group;
use App\Models\UsageEvent;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /usage/journey-steps (ClickUp 86e3qkxtc). A strict
 * whitelist: only the browser-observable steps (form opened, filling
 * started) for the content types that have a create form today. Anything
 * else — a server-side step such as `creation_confirmed`, an unknown step or
 * content type, or extra fields — is rejected or never read: the request
 * carries no form content at all.
 */
class StoreJourneyStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordJourneyStep', UsageEvent::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'journey_id' => ['required', 'uuid'],
            'step' => ['required', 'string', Rule::in(JourneyStep::clientReportableValues())],
            'content_type' => ['required', 'string', Rule::in(JourneyContentType::clientReportableValues())],
            'group_id' => [
                'nullable', 'integer',
                Rule::exists('groups', 'id')->where('school_id', Tenancy::schoolId()),
                // Only a group the teacher actually leads (same rule as
                // creating an assessment for it).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $group = Group::find($value);
                    if ($group === null || ! $this->user()->teachesGroup($group)) {
                        $fail('You do not teach this group.');
                    }
                },
            ],
        ];
    }
}
