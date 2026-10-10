<?php

namespace App\Http\Requests;

use App\Models\Alert;
use App\Models\User;
use App\Support\AlertCirculation;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Share an alert with a co-responsible (ClickUp 86e3jpzdp follow-up: "una
 * alerta puede quedar a cargo de dos roles a la vez"). Authorization is
 * AlertPolicy::share (whoever is in charge, once a way out was chosen). The
 * person it is shared with follows the same rules as a hand-off
 * ({@see AlertCirculation}: already sees the student, never
 * teacher-to-teacher) and must not already be in charge of it.
 */
class ShareAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Alert $alert */
        $alert = $this->route('alert');

        return $this->user()->can('share', $alert);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'integer',
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Alert $alert */
            $alert = $this->route('alert');
            $person = User::query()->find((int) $this->input('user_id'));

            if ($person === null || ! app(AlertCirculation::class)->canReceive($this->user(), $person, $alert)) {
                $validator->errors()->add('user_id', 'The selected person cannot receive this alert.');

                return;
            }

            if ($alert->isInChargeBy($person)) {
                $validator->errors()->add('user_id', 'The selected person is already in charge of this alert.');
            }
        }];
    }
}
