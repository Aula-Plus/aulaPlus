<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\CurricularItem;
use App\Models\Unit;
use App\Services\Units\UnitCurriculum;
use Illuminate\Validation\Validator;

/**
 * Adding an objective to a unit (screen 13). Either the teacher writes it
 * (`description`), or — Eitán in ClickUp 1dfd3mc4bga — picks it straight
 * from the program catalog (`curricular_item_id`, e.g. an ANEP progression):
 * the objective then takes the element's text, unless one is sent, and stays
 * linked to that element, which carries its citation. Only items the unit's
 * picker offers (App\Services\Units\UnitCurriculum) may be picked, and not
 * one an objective of the unit is already linked to.
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
     * The catalog element validated as offered, with its catalog loaded: the
     * one the controller links (a single source of truth for «it is offered»
     * and «what gets linked»). Null for a written objective.
     */
    private ?CurricularItem $offered = null;

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
                $id = (int) $this->input('curricular_item_id');
                $offered = app(UnitCurriculum::class)->itemsFor($unit)->firstWhere('id', $id);
                if ($offered === null) {
                    $validator->errors()->add('curricular_item_id', 'The item is not part of this unit\'s program catalog.');

                    return;
                }
                // Picking an element some objective of the unit already works
                // would duplicate it (two objectives with their own status).
                $alreadyAnObjective = $unit->objectives()
                    ->whereHas('curricularItems', fn ($query) => $query->whereKey($id))
                    ->exists();
                if ($alreadyAnObjective) {
                    $validator->errors()->add('curricular_item_id', 'The item is already an objective of this unit.');

                    return;
                }
                $this->offered = $offered;
            },
        ];
    }

    public function offeredItem(): ?CurricularItem
    {
        return $this->offered;
    }
}
