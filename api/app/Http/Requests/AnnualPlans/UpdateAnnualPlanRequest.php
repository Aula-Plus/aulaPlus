<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\AnnualPlan;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Only the owning teacher edits the plan's data (AnnualPlanPolicy::update). */
class UpdateAnnualPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AnnualPlan $plan */
        $plan = $this->route('annualPlan');

        return $this->user()->can('update', $plan);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'curricular_framework_id' => [
                'sometimes', 'integer',
                Rule::exists('school_curricular_framework', 'curricular_framework_id')
                    ->where('school_id', Tenancy::schoolId())
                    ->where('active', true),
            ],
        ];
    }
}
