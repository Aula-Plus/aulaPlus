<?php

namespace App\Http\Requests;

use App\Models\TherapeuticCompanion;
use App\Support\SchoolTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A therapeutic companion stops accompanying (screen 10 › Acompañantes,
 * ClickUp 1dfd3mc4bge): they get an end date and stay in the student's
 * history. The date cannot be in the future (a companion with an end date is
 * no longer current) and a companion is ended only once. Authorized before
 * validating (TherapeuticCompanionPolicy::end), so a role without access
 * gets 403 whatever it sends.
 */
class EndTherapeuticCompanionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companion = $this->route('companion');

        return $companion instanceof TherapeuticCompanion
            && $this->user()?->can('end', $companion) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ended_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.SchoolTime::today()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ended_on.required' => 'Indicá la fecha de fin.',
            'ended_on.date_format' => 'La fecha de fin no es válida.',
            'ended_on.before_or_equal' => 'La fecha de fin no puede ser posterior a hoy.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $companion = $this->route('companion');
            if ($companion instanceof TherapeuticCompanion && ! $companion->isCurrent()) {
                $validator->errors()->add('ended_on', 'Este acompañante ya tiene fecha de fin.');
            }
        }];
    }
}
