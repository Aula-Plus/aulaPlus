<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\SchoolYear;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Opening the next year (ClickUp 1dfd3mc4bh7, decision 5): for each period of
 * each program of the closed year, whoever opens it chooses to carry it to the
 * same date next year (`same_date`), to another date of the next year
 * (`other_date` + `starts_on`) or not to carry it (`skip`). Every period gets
 * exactly one decision, so nothing is carried (or dropped) by omission.
 */
class OpenNextSchoolYearRequest extends FormRequest
{
    public const ACTIONS = ['same_date', 'other_date', 'skip'];

    public function authorize(): bool
    {
        return $this->user()->can('openNext', $this->schoolYear());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $next = $this->schoolYear()->year + 1;

        return [
            'periods' => ['present', 'array'],
            'periods.*.id' => ['required', 'integer', 'distinct'],
            'periods.*.action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'periods.*.starts_on' => [
                'nullable', 'required_if:periods.*.action,other_date', 'date_format:Y-m-d',
                'after_or_equal:'.$next.'-01-01', 'before_or_equal:'.$next.'-12-31',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $year = $this->schoolYear();
                if (! $year->isClosed()) {
                    $validator->errors()->add('year', 'Close the school year before opening the next one.');

                    return;
                }
                if (app(SchoolYearCalendar::class)->current($year->school_id)->id !== $year->id) {
                    $validator->errors()->add('year', 'The next school year is already open.');

                    return;
                }

                $expected = $year->periods()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $given = collect($this->input('periods', []))
                    ->map(fn ($row) => (int) ($row['id'] ?? 0))->sort()->values()->all();
                if ($expected !== $given) {
                    $validator->errors()->add('periods', 'Choose what happens with every period of the closed year.');
                }
            },
        ];
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
