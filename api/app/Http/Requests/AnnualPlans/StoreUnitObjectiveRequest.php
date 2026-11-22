<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\Unit;
use App\Services\Units\UnitCurriculum;
use Illuminate\Validation\Validator;

/**
 * Adding an objective to a unit (screen 13). Either the teacher writes it
 * (`description`), or — Eitán in ClickUp 1dfd3mc4bga — picks it straight
 * from the program catalog (`curricular_item_id`, e.g. an ANEP progression):
 * the objective then takes the element's text, unless one is sent, and stays
 * linked to that element, which carries its citation. Only items the unit's
 * picker offers (App\Services\Units\UnitCurriculum) may be picked.
 */
class StoreUnitObjectiveRequest extends UnitObjectiveRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required_without:curricular_item_id', 'nullable', 'string', 'max:2000'],
            'curricular_item_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('curricular_item_id')) {
                    return;
                }
                /** @var Unit $unit */
                $unit = $this->route('unit');
                $offered = app(UnitCurriculum::class)->itemsFor($unit)
                    ->firstWhere('id', (int) $this->input('curricular_item_id'));
                if ($offered === null) {
                    $validator->errors()->add('curricular_item_id', 'The item is not part of this unit\'s program catalog.');
                }
            },
        ];
    }
}
