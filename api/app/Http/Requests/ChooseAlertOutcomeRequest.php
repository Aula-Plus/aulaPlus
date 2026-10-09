<?php

namespace App\Http\Requests;

use App\Enums\AlertOutcome;
use App\Models\Alert;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choose one of the three ways out of an alert (ClickUp 86e3jpzdp). Every way
 * out names a deadline; handing it off also names the staff member who
 * becomes responsible (same school, active, not the actor). The note is
 * optional and bounded: it is an operational note for whoever receives the
 * case, not a place for clinical detail.
 */
class ChooseAlertOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Alert $alert */
        $alert = $this->route('alert');

        return $this->user()->can('act', $alert);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(AlertOutcome::class)],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'assignee_id' => [
                Rule::requiredIf($this->input('outcome') === AlertOutcome::HandedOff->value),
                Rule::prohibitedIf($this->input('outcome') !== AlertOutcome::HandedOff->value),
                'nullable', 'integer',
                Rule::notIn([$this->user()->id]),
                Rule::exists('users', 'id')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('disabled_at')
                    ->whereNotNull('password'),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
