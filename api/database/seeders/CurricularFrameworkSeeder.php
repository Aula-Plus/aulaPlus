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

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            CurricularFramework::query()->firstOrCreate(['name' => $name]);
        }
    }
}
