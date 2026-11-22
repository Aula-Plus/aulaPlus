<?php

namespace App\Services\Materials;

use App\Enums\CurricularItemType;
use App\Models\CurricularCatalog;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\Subject;
use App\Services\CurricularStructure\CurricularOfferings;
use Illuminate\Support\Collection;

/**
 * The program elements a material can be tagged with (screen 19, PA-109 and
 * PA-118): «el selector lee el catálogo del programa de la materia; si está
 * vacío, lo dice». Same reading as the Unit's objective picker (screen 13):
 * the programs the group-subject follows (Oferta curricular), and per catalog
 * the subject's subtree plus the general competencies when the subject is
 * linked to it, or the whole catalog otherwise.
 *
 * Global catalog content only — no student data.
 */
class MaterialCurriculum
{
    /** Hard cap so a huge catalog never turns the picker into a dump. */
    public const MAX_ITEMS = 1500;

    public function __construct(private readonly CurricularOfferings $offerings) {}

    /**
     * @return Collection<int, CurricularItem>
     */
    public function itemsFor(Group $group, Subject $subject): Collection
    {
        $frameworkIds = $this->offerings->effectiveIdsFor($group, $subject);

        $catalogs = CurricularCatalog::query()
            ->with('curricularFramework:id,name')
            ->whereIn('curricular_framework_id', $frameworkIds)
            ->orderBy('id')
            ->get();

        $linked = $subject->loadMissing('curricularItem')->curricularItem;

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
     * @return array<string, mixed>
     */
    public static function row(CurricularItem $item): array
    {
        $catalog = $item->relationLoaded('curricularCatalog') ? $item->curricularCatalog : null;

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type->value,
            'catalog' => $catalog?->name,
            'framework' => $catalog?->curricularFramework?->name,
        ];
    }
}
