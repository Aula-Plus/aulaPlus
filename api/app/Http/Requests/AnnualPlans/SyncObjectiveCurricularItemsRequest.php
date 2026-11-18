<?php

namespace App\Http\Requests\AnnualPlans;

use App\Models\UnitObjective;
use App\Services\Units\UnitCurriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The curricular items an objective works (screen 13, P13-DEC-05). Only the
 * plan's owning teacher chooses them; new ones only among the items the
 * unit's picker offers (App\Services\Units\UnitCurriculum).
 */
class SyncObjectiveCurricularItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var UnitObjective $objective */
        $objective = $this->route('objective');

        return $this->user()->can('update', $objective->unit);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'curricular_item_ids' => ['present', 'array', 'max:30'],
            'curricular_item_ids.*' => ['integer', 'distinct'],
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
                /** @var UnitObjective $objective */
                $objective = $this->route('objective');
                // Only the items being added must be offered by the picker:
                // those already linked were offered when they were chosen and
                // may have left it since (the program left the offering, the
                // catalog changed), and keeping or unticking them must work.
                $current = $objective->curricularItems()->pluck('curricular_items.id');
                $offered = app(UnitCurriculum::class)->itemsFor($objective->unit)->pluck('id');
                $outside = collect($this->input('curricular_item_ids'))
                    ->map(fn ($id) => (int) $id)
                    ->diff($current)
                    ->diff($offered);
                if ($outside->isNotEmpty()) {
                    $validator->errors()->add('curricular_item_ids', 'Some items are not part of this unit\'s program catalog.');
                }
            },
        ];
    }
}
