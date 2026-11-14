<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Editing a unit: only its plan's owning teacher (UnitPolicy::update). */
class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Unit $unit */
        $unit = $this->route('unit');

        return $this->user()->can('update', $unit);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ];
    }

    /**
     * The end date can't fall before the start date, comparing against the
     * stored value when only one of the two is sent.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                /** @var Unit $unit */
                $unit = $this->route('unit');
                $start = $this->input('start_date', $unit->start_date?->toDateString());
                $end = $this->input('end_date', $unit->end_date?->toDateString());
                if ($start !== null && $end !== null && strtotime($end) < strtotime($start)) {
                    $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
                }
            },
        ];
    }
}
