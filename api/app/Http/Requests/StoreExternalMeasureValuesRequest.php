<?php

namespace App\Http\Requests;

use App\Models\ExternalMeasure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Loading values of an external datum (screen 30 «Datos»: the Aula+
 * template, a CSV or by hand). Each row is a student and the original number;
 * a null value clears that student's value for the year. Rows whose student
 * is not of this school, or whose value is outside the datum's scale, are not
 * saved and are counted back (like the screening CSV, P07-APL-02).
 */
class StoreExternalMeasureValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExternalMeasure $measure */
        $measure = $this->route('externalMeasure');

        return $this->user()->can('update', $measure);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'school_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'values' => ['required', 'array', 'min:1', 'max:2000'],
            'values.*' => ['required', 'array:student_id,value'],
            'values.*.student_id' => ['required', 'integer', 'distinct'],
            'values.*.value' => ['present', 'nullable', 'numeric'],
        ];
    }
}
