<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\SchoolPeriod;
use App\Models\SchoolYear;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A period of an open year for one program (ClickUp 1dfd3mc4bh7, decision 3):
 * name and start date, within the year. On create the program must be one the
 * school has active; on update the program does not change. Independent from
 * the grades-visibility cutoff (PA-040).
 */
class SchoolPeriodRequest extends FormRequest
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
        $creating = $this->period() === null;

        return [
            'curricular_framework_id' => $creating
                ? [
                    'required', 'integer',
                    Rule::exists('school_curricular_framework', 'curricular_framework_id')
                        ->where('school_id', Tenancy::schoolId())
                        ->where('active', true),
                ]
                : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'starts_on' => [
                $creating ? 'required' : 'sometimes', 'date_format:Y-m-d',
                'after_or_equal:'.$year.'-01-01',
                'before_or_equal:'.$year.'-12-31',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->schoolYear()->isOpen()) {
                    $validator->errors()->add('starts_on', 'The periods of a closed school year cannot change.');
                }
            },
        ];
    }

    public function period(): ?SchoolPeriod
    {
        $period = $this->route('period');

        return $period instanceof SchoolPeriod ? $period : null;
    }

    public function schoolYear(): SchoolYear
    {
        return $this->period()?->schoolYear ?? $this->route('schoolYear');
    }
}
