<?php

namespace Database\Seeders;

use App\Models\CurricularFramework;
use Illuminate\Database\Seeder;

/**
 * Global catalog, shared by every school — safe to run in every environment.
 */
class CurricularFrameworkSeeder extends Seeder
{
    public const NAMES = [
        'ANEP EBI',
        'ANEP Bachillerato',
        'Cambridge Primary',
        'Cambridge Lower Secondary',
        'IGCSE',
        'IB PYP',
        'IB MYP',
        'IB DP',
    ];

    /**
     * Which planner fields each framework brings (App\Enums\PlannerFamily;
     * screen 23 › Planificadores). Frameworks not listed bring only the common
     * fields. Keep in sync with the 2026_11_12 migration, which backfills it.
     */
    public const FAMILIES = [
        'ANEP EBI' => 'anep',
        'ANEP Bachillerato' => 'anep',
        'Cambridge Primary' => 'cambridge',
        'Cambridge Lower Secondary' => 'cambridge',
        'IB PYP' => 'ib_pyp',
        'IB MYP' => 'ib_myp',
        'IGCSE' => 'igcse',
        'IB DP' => 'ib_dp',
    ];

    /**
     * The education levels (education_levels.key) each program applies to:
     * the offering editor only offers what fits the group's level. Keep in
     * sync with the 2026_11_14 migration, which backfills it.
     */
    public const LEVELS = [
        'ANEP EBI' => ['initial', 'primary', 'lower_secondary'],
        'ANEP Bachillerato' => ['upper_secondary'],
        'Cambridge Primary' => ['primary'],
        'Cambridge Lower Secondary' => ['lower_secondary'],
        'IGCSE' => ['lower_secondary', 'upper_secondary'],
        'IB PYP' => ['initial', 'primary'],
        'IB MYP' => ['lower_secondary', 'upper_secondary'],
        'IB DP' => ['upper_secondary'],
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            CurricularFramework::query()->updateOrCreate(
                ['name' => $name],
                [
                    'planner_family' => self::FAMILIES[$name] ?? null,
                    'education_levels' => self::LEVELS[$name] ?? null,
                ],
            );
        }
    }
}
