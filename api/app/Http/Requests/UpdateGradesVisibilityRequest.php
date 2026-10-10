<?php

namespace App\Http\Requests;

use App\Enums\GradesVisibility;
use App\Models\School;
use App\Support\SchoolTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Direction chooses, for the whole school, what a teacher sees of grades in
 * subjects they do not teach. Director only, via
 * SchoolPolicy::updateGradesVisibility.
 */
class UpdateGradesVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::query()->find($this->user()->school_id);

        return $school !== null && $this->user()->can('updateGradesVisibility', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(GradesVisibility::class)],
            'cutoff_months' => [
                Rule::requiredIf(fn () => $this->input('mode') === GradesVisibility::AllPeriodic->value),
                'nullable', 'integer', 'min:1', 'max:60',
            ],
            // A sane range: cutoffs are counted from here, and a date in the
            // future would mean "no cutoff yet" indefinitely.
            'cutoff_anchor' => [
                'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01',
                'before_or_equal:'.SchoolTime::today()->toDateString(),
            ],
        ];
    }
}
