<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\SchoolYear;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\SchoolTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Closing the year asks for confirmation (P26-ANO-03) and is only possible
 * for the school's current, open year from the day direction chose on.
 */
class CloseSchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('close', $this->schoolYear());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $year = $this->schoolYear();
                if (! $year->isOpen()) {
                    $validator->errors()->add('year', 'The school year is already closed.');

                    return;
                }
                if (app(SchoolYearCalendar::class)->current($year->school_id)->id !== $year->id) {
                    $validator->errors()->add('year', 'Only the current school year can be closed.');

                    return;
                }
                if ($year->closing_enabled_from === null) {
                    $validator->errors()->add('year', 'Choose from which day closing is enabled first.');

                    return;
                }
                if (SchoolTime::today()->toDateString() < $year->closing_enabled_from->toDateString()) {
                    $validator->errors()->add('year', 'Closing is not enabled yet.');
                }
            },
        ];
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
