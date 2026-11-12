<?php

namespace App\Http\Requests;

use App\Models\OfficialScale;
use App\Models\School;
use App\Services\ResultScales;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Direction sets the numeric-grade switch and, optionally, the school's own
 * equivalence for its Primaria scale (ClickUp 1dfd3mc4bgg). Director only
 * (SchoolPolicy::updateNumericGradeSettings).
 *
 * The equivalence is all or nothing: either empty (no equivalence) or one
 * whole number 1..10 for every level of the school's Primaria scale — a level
 * of any other scale, a repeated level or a missing one is a 422.
 */
class UpdateNumericGradeSettingsRequest extends FormRequest
{
    protected ?School $school = null;

    protected ?OfficialScale $primaryScale = null;

    protected bool $primaryScaleResolved = false;

    public function authorize(): bool
    {
        $school = $this->school();

        return $school !== null && $this->user()->can('updateNumericGradeSettings', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $levelIds = $this->primaryScale()?->levels->pluck('id')->all() ?? [];

        return [
            'show_numeric_grade' => ['required', 'boolean'],
            'equivalences' => ['present', 'array'],
            'equivalences.*.level_id' => ['required', 'integer', 'distinct', Rule::in($levelIds)],
            'equivalences.*.numeric_grade' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rows = $this->input('equivalences');
                $scale = $this->primaryScale();

                if (! is_array($rows) || $rows === [] || $scale === null) {
                    return;
                }

                if (count($rows) !== $scale->levels->count()) {
                    $validator->errors()->add(
                        'equivalences',
                        'The equivalence must give a number to every level of the scale.',
                    );
                }
            },
        ];
    }

    public function school(): ?School
    {
        return $this->school ??= School::query()->find($this->user()->school_id);
    }

    protected function primaryScale(): ?OfficialScale
    {
        if (! $this->primaryScaleResolved) {
            $this->primaryScaleResolved = true;
            $school = $this->school();
            $this->primaryScale = $school ? app(ResultScales::class)->primaryScaleForSchool($school) : null;
        }

        return $this->primaryScale;
    }
}
