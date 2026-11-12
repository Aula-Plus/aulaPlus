<?php

namespace App\Http\Requests\CurricularStructure;

use App\Enums\PlannerFamily;
use App\Enums\PlannerFieldType;
use App\Models\CurricularOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/planner-settings/custom-fields and PUT …/{plannerField}: a
 * field of the school's own — name, type (never `automatic`), options for the
 * list types, and the framework it goes in (null = every framework).
 */
class SchoolPlannerFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CurricularOffering::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $type = PlannerFieldType::tryFrom((string) $this->input('type'));

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(PlannerFieldType::customValues())],
            'family' => ['nullable', Rule::enum(PlannerFamily::class)->except([PlannerFamily::Common])],
            'options' => $type?->hasOptions()
                ? ['required', 'array', 'min:2', 'max:30']
                : ['prohibited'],
            'options.*' => ['string', 'max:120', 'distinct'],
            'required_by_school' => ['sometimes', 'boolean'],
            'shown' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToSave(): array
    {
        $data = $this->validated();

        return [
            'name' => trim($data['name']),
            'type' => $data['type'],
            'family' => $data['family'] ?? null,
            'options' => isset($data['options']) ? array_values(array_map('trim', $data['options'])) : null,
            'required_by_school' => $data['required_by_school'] ?? false,
            'shown' => $data['shown'] ?? true,
        ];
    }
}
