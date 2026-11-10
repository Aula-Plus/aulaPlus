<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\Unit;
use App\Models\UnitObjective;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing or editing a unit objective. The objective is the teacher's text
 * (P13-OBJ-05: the AI never proposes objectives); only the owning teacher of
 * the unit's plan writes it (UnitPolicy::update).
 */
class UnitObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');
        if (! $unit instanceof Unit) {
            /** @var UnitObjective $objective */
            $objective = $this->route('objective');
            $unit = $objective->unit;
        }

        return $this->user()->can('update', $unit);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
