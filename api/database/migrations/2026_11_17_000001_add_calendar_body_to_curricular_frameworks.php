<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 26 «Año y calendario», PO answer of 17/11 (ClickUp 1dfd3mc4bh7):
 * the periods are set once per body (App\Enums\CalendarBody) — ANEP
 * Bachillerato, ANEP Ciclo Básico y Primaria, Cambridge, IB — so each catalog
 * program says which body's calendar it follows. Catalog data, not code
 * (null = the program is its own row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->string('calendar_body')->nullable()->after('education_levels');
        });

        foreach (self::BODIES as $name => $body) {
            DB::table('curricular_frameworks')->where('name', $name)->update(['calendar_body' => $body]);
        }
    }

    public function down(): void
    {
        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->dropColumn('calendar_body');
        });
    }

    /** Keep in sync with CurricularFrameworkSeeder::BODIES. */
    private const BODIES = [
        'ANEP EBI' => 'anep_basic',
        'ANEP Bachillerato' => 'anep_upper_secondary',
        'Cambridge Primary' => 'cambridge',
        'Cambridge Lower Secondary' => 'cambridge',
        'IGCSE' => 'cambridge',
        'IB PYP' => 'ib',
        'IB MYP' => 'ib',
        'IB DP' => 'ib',
    ];
};
