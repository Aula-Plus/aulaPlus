<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\SchoolYear;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Deshacer el cierre» (ClickUp 1dfd3mc4bh7, Eitán 17/11): asks for
 * confirmation and is only possible for a closed year that is still the
 * school's current one. Once the next year is open, its groups, periods and
 * loading may already exist on top of the closing, so undoing it is blocked
 * instead of guessing what to do with them.
 */
class ReopenSchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reopen', $this->schoolYear());
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
                if (! $year->isClosed()) {
                    $validator->errors()->add('year', 'The school year is not closed.');

                    return;
                }
                if (app(SchoolYearCalendar::class)->current($year->school_id)->id !== $year->id) {
                    $validator->errors()->add('year', 'The next school year is already open: closing cannot be undone.');
                }
            },
        ];
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
