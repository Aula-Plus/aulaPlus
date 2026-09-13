<?php

namespace App\Http\Requests;

use App\Models\ScheduledFollowUp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Resolve a scheduled follow-up. Authorization delegates to
 * ScheduledFollowUpPolicy::resolve (same rule as create — resolving is not a
 * four-eyes approval). `resolution_note` is optional free text describing
 * what happened.
 */
class ResolveScheduledFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ScheduledFollowUp $followUp */
        $followUp = $this->route('followUp');

        return $this->user()->can('resolve', $followUp);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'resolution_note' => ['nullable', 'string'],
        ];
    }
}
