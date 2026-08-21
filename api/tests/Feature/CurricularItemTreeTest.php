<?php

use App\Enums\CurricularItemType;
use App\Models\CurricularCatalog;
use App\Models\CurricularItem;
use Illuminate\Support\Facades\Schema;

it('walks a 3-level curricular item tree via descendants()', function () {
    $catalog = CurricularCatalog::factory()->create();

    $subject = CurricularItem::factory()->create([
        'curricular_catalog_id' => $catalog->id,
        'parent_id' => null,
        'type' => CurricularItemType::Subject,
        'name' => 'Matemática',
    ]);

    $strandA = CurricularItem::factory()->create([
        'curricular_catalog_id' => $catalog->id,
        'parent_id' => $subject->id,
        'type' => CurricularItemType::Strand,
        'name' => 'Números',
    ]);

    $strandB = CurricularItem::factory()->create([
        'curricular_catalog_id' => $catalog->id,
        'parent_id' => $subject->id,
        'type' => CurricularItemType::Strand,
        'name' => 'Geometría',
    ]);

    $substrand = CurricularItem::factory()->create([
        'curricular_catalog_id' => $catalog->id,
        'parent_id' => $strandA->id,
        'type' => CurricularItemType::Substrand,
        'name' => 'Fracciones',
    ]);

    $descendants = $subject->descendants();

    expect($descendants)->toHaveCount(3)
        ->and($descendants->pluck('id')->all())->toEqualCanonicalizing([
            $strandA->id,
            $strandB->id,
            $substrand->id,
        ])
        ->and($strandA->descendants()->pluck('id')->all())->toBe([$substrand->id])
        ->and($strandB->descendants())->toBeEmpty()
        ->and($substrand->descendants())->toBeEmpty();
});

it('is not tenant-scoped: no school_id column on the catalog tables', function () {
    expect(Schema::hasColumn('curricular_frameworks', 'school_id'))->toBeFalse()
        ->and(Schema::hasColumn('curricular_catalogs', 'school_id'))->toBeFalse()
        ->and(Schema::hasColumn('curricular_items', 'school_id'))->toBeFalse();
});
