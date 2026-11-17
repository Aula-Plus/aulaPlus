<?php

namespace App\Http\Requests\SchoolYears;

use App\Enums\CalendarBody;
use App\Models\CurricularFramework;
use App\Models\SchoolPeriod;
use App\Models\SchoolYear;
use App\Services\SchoolYears\PeriodCalendars;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A period of an open year for one calendar row (ClickUp 1dfd3mc4bh7): name
 * and start date, within the year. On create the row is either a body the
 * school has in its offer (`calendar_body`, e.g. Cambridge) or one active
 * program (`curricular_framework_id`) whose periods are its own — a program
 * with no body, or one of a body split by program. On update the row does not
 * change. Independent from the grades-visibility cutoff (PA-040).
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
            'calendar_body' => $creating
                ? ['required_without:curricular_framework_id', 'prohibits:curricular_framework_id', 'string', Rule::enum(CalendarBody::class)]
                : ['prohibited'],
            'curricular_framework_id' => $creating
                ? [
                    'required_without:calendar_body', 'integer',
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
                $year = $this->schoolYear();
                if (! $year->isOpen()) {
                    $validator->errors()->add('starts_on', 'The periods of a closed school year cannot change.');

                    return;
                }
                if ($this->period() !== null || $validator->errors()->hasAny(['calendar_body', 'curricular_framework_id'])) {
                    return;
                }

                $calendars = app(PeriodCalendars::class);
                $body = CalendarBody::tryFrom((string) $this->input('calendar_body'));
                if ($body !== null) {
                    if ($calendars->activeProgramsOf($year, $body)->isEmpty()) {
                        $validator->errors()->add('calendar_body', 'The school has no program of this body in its curricular offer.');
                    } elseif ($body->canSplitByProgram() && $year->isSplit($body)) {
                        $validator->errors()->add('calendar_body', 'This body is split by program: add the period to one of its programs.');
                    }

                    return;
                }

                $framework = CurricularFramework::query()->find($this->integer('curricular_framework_id'));
                if ($framework !== null && ! $calendars->rowIsPerProgram($year, $framework)) {
                    $validator->errors()->add('curricular_framework_id', 'This program follows its body\'s periods.');
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
