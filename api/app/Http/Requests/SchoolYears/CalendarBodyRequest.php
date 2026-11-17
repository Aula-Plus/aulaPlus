<?php

namespace App\Http\Requests\SchoolYears;

use App\Enums\CalendarBody;
use App\Models\SchoolYear;
use App\Services\SchoolYears\PeriodCalendars;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Separar por programa» and its way back (ClickUp 1dfd3mc4bh7, Eitán 17/11),
 * only in Cambridge and IB and only while the year is open:
 *
 * - split: the body is not split yet and the school has at least two of its
 *   programs in its offer (with one there is nothing to separate);
 * - join: the body is split and every program that has periods has the same
 *   ones, so joining loses nothing anyone set.
 */
class CalendarBodyRequest extends FormRequest
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
        return [];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $year = $this->schoolYear();
                $body = $this->body();
                $calendars = app(PeriodCalendars::class);

                if (! $year->isOpen()) {
                    $validator->errors()->add('body', 'The periods of a closed school year cannot change.');

                    return;
                }
                if (! $body->canSplitByProgram()) {
                    $validator->errors()->add('body', 'Only Cambridge and IB can be split by program.');

                    return;
                }

                if ($this->joining()) {
                    if (! $year->isSplit($body)) {
                        $validator->errors()->add('body', 'This body is not split by program.');
                    } elseif ($calendars->programSchemes($year, $body)->unique(fn (array $scheme) => json_encode($scheme))->count() > 1) {
                        $validator->errors()->add('body', 'The programs have different periods: make them the same before joining.');
                    }

                    return;
                }

                if ($year->isSplit($body)) {
                    $validator->errors()->add('body', 'This body is already split by program.');
                } elseif ($calendars->activeProgramsOf($year, $body)->count() < 2) {
                    $validator->errors()->add('body', 'The school has only one program of this body.');
                }
            },
        ];
    }

    public function joining(): bool
    {
        return str_ends_with($this->path(), '/join');
    }

    public function body(): CalendarBody
    {
        return CalendarBody::from((string) $this->route('body'));
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
