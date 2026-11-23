<?php

namespace App\Http\Requests;

use App\Enums\ObservationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Marcar realizada». For an individual session it may carry the session
 * note, which lands in the student's file as an observation with its scope,
 * «solo Psicopedagogía» unless chosen otherwise (P10-DEC-10). Authorization
 * in the controller (PsychopedagogyMeetingPolicy::update).
 */
class CompletePsychopedagogyMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scope' => ['sometimes', Rule::enum(ObservationScope::class)],
        ];
    }

    public function scope(): ObservationScope
    {
        return ObservationScope::tryFrom((string) $this->validated('scope')) ?? ObservationScope::PsychopedagogyOnly;
    }
}
