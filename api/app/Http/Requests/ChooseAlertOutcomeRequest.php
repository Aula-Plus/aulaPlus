<?php

namespace App\Http\Requests;

use App\Enums\AlertOutcome;
use App\Enums\Role;
use App\Models\Alert;
use App\Models\User;
use App\Support\AlertRouting;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choose one of the three ways out of an alert (ClickUp 86e3jpzdp). Every way
 * out names a deadline; handing it off also names the staff member who
 * becomes responsible (same school, active, not the actor). The note is
 * optional and bounded: it is an operational note for whoever receives the
 * case, not a place for clinical detail.
 *
 * An alert type that never reaches teachers (the «Señal de dificultad» alert,
 * ClickUp 86e3qkwpn) cannot be handed to a teacher either: the assignee must
 * hold a school-wide role.
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

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Alert $alert */
            $alert = $this->route('alert');
            $assigneeId = $this->input('assignee_id');

            if ($validator->errors()->has('assignee_id') || $assigneeId === null || AlertRouting::allowsTeachers($alert->type)) {
                return;
            }

            $assignee = User::query()->where('school_id', Tenancy::schoolId())->find((int) $assigneeId);

            if ($assignee === null || ! $assignee->hasAnyRole(Role::schoolWideValues())) {
                $validator->errors()->add('assignee_id', 'This alert can only be handed to psychopedagogy or direction.');
            }
        }];
    }
}
