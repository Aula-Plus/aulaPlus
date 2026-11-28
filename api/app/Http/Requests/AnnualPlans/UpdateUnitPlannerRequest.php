<?php

namespace App\Http\Requests\AnnualPlans;

use App\Enums\PlannerFieldType;
use App\Models\Unit;
use App\Services\Units\UnitPlanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Saving fields of a unit's planner (screen 13). Only the plan's owning
 * teacher writes (UnitPolicy::update). `values` maps a resolved field key to
 * its value; only keys of the planner this unit resolves are accepted, and
 * each value is checked against its field type. Sending a key with an empty
 * value clears it; keys not sent are left as they are.
 */
class UpdateUnitPlannerRequest extends FormRequest
{
    /** @var array<string, mixed> */
    protected array $normalized = [];

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
            'values' => ['required', 'array', 'max:60'],
        ];
    }

    /**
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
                $planner = app(UnitPlanner::class);
                $fields = collect($planner->resolved($unit)['fields'])->keyBy('key');

                foreach ((array) $this->input('values') as $key => $value) {
                    $field = $fields->get((string) $key);
                    if ($field === null) {
                        $validator->errors()->add("values.$key", 'This field is not part of the unit planner.');

                        continue;
                    }
                    // The only automatic field that takes a value: the
                    // group's resources unchecked for this unit (PA-118).
                    $result = $field['key'] === UnitPlanner::RESOURCES_KEY && ($field['type'] ?? null) === PlannerFieldType::Automatic->value
                        ? $planner->normalizeResources($unit, $value)
                        : $planner->normalize($field, $value);
                    if (! $result['ok']) {
                        $validator->errors()->add("values.$key", $result['error']);

                        continue;
                    }
                    $this->normalized[(string) $key] = $result['value'];
                }
            },
        ];
    }

    /** @return array<string, mixed> key => value to store (null = clear) */
    public function normalizedValues(): array
    {
        return $this->normalized;
    }
}
