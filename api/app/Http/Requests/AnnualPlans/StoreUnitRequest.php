<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\AnnualPlan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a unit to an annual plan: only the plan's owning teacher
 * (AnnualPlanPolicy::update). Overlapping units are allowed on purpose — the
 * screen warns, it never blocks (screen 12, «Cronograma»).
 */
class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AnnualPlan $plan */
        $plan = $this->route('annualPlan');

        return $this->user()->can('update', $plan);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
