<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\SchoolYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Direction picks the day from which closing the year is enabled (ClickUp
 * 1dfd3mc4bh7, decision 4: not a fixed 01/12). Any day of the year itself or
 * of the next one (a school may close in February, after the exams). Only
 * while the year is open.
 */
class UpdateSchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->schoolYear());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $year = $this->schoolYear()->year;

        return [
            'closing_enabled_from' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$year.'-01-01',
                'before_or_equal:'.($year + 1).'-12-31',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->schoolYear()->isOpen()) {
                    $validator->errors()->add('closing_enabled_from', 'The school year is already closed.');
                }
            },
        ];
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
