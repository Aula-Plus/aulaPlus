<?php

namespace App\Services\Units;

use App\Enums\CurricularItemType;
use App\Models\CurricularCatalog;
use App\Models\CurricularItem;
use App\Models\Unit;
use App\Services\CurricularStructure\CurricularOfferings;
use Illuminate\Support\Collection;

/**
 * The curricular items an objective of a unit may be linked to (screen 13,
 * P13-DEC-05, ClickUp 1dfd3mc4bga): «el selector lee el catálogo del programa
 * de la materia; si está vacío, lo dice».
 *
 * The programs are the plan's framework plus the ones the group-subject
 * follows (Oferta curricular, stored + mandatory). Per catalog of those
 * programs:
 *
 * - when the subject is linked to an item of that catalog, its subtree plus
 *   the catalog's general competencies (e.g. the MCN's, which cross every
 *   subject);
 * - otherwise, the whole catalog.
 *
 * Global catalog content only — no student data.
 */
class UnitCurriculum
{
    /** Hard cap so a huge catalog never turns the picker into a dump. */
    public const MAX_ITEMS = 1500;

    public function __construct(private readonly CurricularOfferings $offerings) {}

    /**
     * @return Collection<int, CurricularItem>
     */
    public function itemsFor(Unit $unit): Collection
    {
        $plan = $unit->loadMissing('annualPlan.group', 'annualPlan.subject.curricularItem')->annualPlan;

        $frameworkIds = collect([$plan->curricular_framework_id])
            ->concat($plan->group && $plan->subject
                ? $this->offerings->effectiveFor($plan->group, $plan->subject)->pluck('id')
                : [])
            ->filter()
            ->unique()
            ->values();

        $catalogs = CurricularCatalog::query()
            ->with('curricularFramework:id,name')
            ->whereIn('curricular_framework_id', $frameworkIds)
            ->orderBy('id')
            ->get();

        $linked = $plan->subject?->curricularItem;

        return $catalogs->flatMap(function (CurricularCatalog $catalog) use ($linked): Collection {
            $items = CurricularItem::query()
                ->where('curricular_catalog_id', $catalog->id)
                ->orderBy('id')
                ->get();

            if ($linked !== null && $linked->curricular_catalog_id === $catalog->id) {
                $subtree = collect([$linked->id]);
                $frontier = collect([$linked->id]);
                while ($frontier->isNotEmpty()) {
                    $frontier = $items->whereIn('parent_id', $frontier->all())->pluck('id');
                    $subtree = $subtree->concat($frontier);
                }
                $items = $items->filter(fn (CurricularItem $item): bool => $subtree->contains($item->id)
                    || $item->type === CurricularItemType::GeneralCompetency);
            }

            return $items->each(fn (CurricularItem $item) => $item->setRelation('curricularCatalog', $catalog));
        })->take(self::MAX_ITEMS)->values();
    }

    /**
     * How an item is shown and cited: its program and catalog, its parent (to
     * locate it) and its source and page when the catalog carries them.
     *
     * @param  Collection<int, CurricularItem>|null  $siblings  items of the same catalog, to name the parent without a query
     * @return array<string, mixed>
     */
    public function row(CurricularItem $item, ?Collection $siblings = null): array
    {
        $catalog = $item->relationLoaded('curricularCatalog') ? $item->curricularCatalog : $item->curricularCatalog()->with('curricularFramework:id,name')->first();
        $parent = $item->parent_id === null ? null
            : ($siblings?->firstWhere('id', $item->parent_id) ?? CurricularItem::query()->find($item->parent_id, ['id', 'name']));

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type->value,
            'description' => $item->description,
            'parent_name' => $parent?->name,
            'catalog' => $catalog?->name,
            'framework' => $catalog?->curricularFramework?->name,
            'source' => $item->source,
            'page' => $item->page,
        ];
    }
}
