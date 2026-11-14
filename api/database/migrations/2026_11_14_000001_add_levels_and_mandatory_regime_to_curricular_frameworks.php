<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 23 › Oferta curricular / Planificadores, PO answers of 13–14/11
 * (ClickUp 1dfd3mc4bgf):
 *
 * - `curricular_frameworks.education_levels`: the education levels (keys of
 *   `education_levels`) a program applies to, so the offering editor only
 *   offers what fits the group's level. Catalog data, not code (P23-DEC-03).
 * - `school_curricular_framework.mandatory`: a program Aula+ loads for the
 *   school as mandatory regime (P23-DEC-01). Today that is ANEP for every
 *   school, because they are all in Uruguay; it is data per school so a school
 *   elsewhere simply has none. It is never shown to users as a rule.
 * - IGCSE and IB DP get their own planner family (they start with only the
 *   common fields; the school can add its own).
 * - `school_planner_fields.description` / `purpose`: what a school's own
 *   field means for the school, and whether it is for the AI or only for the
 *   record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->json('education_levels')->nullable()->after('planner_family');
        });

        foreach (self::FRAMEWORK_LEVELS as $name => $levels) {
            DB::table('curricular_frameworks')->where('name', $name)
                ->update(['education_levels' => json_encode($levels)]);
        }
        foreach (self::NEW_FAMILIES as $name => $family) {
            DB::table('curricular_frameworks')->where('name', $name)->update(['planner_family' => $family]);
        }

        Schema::table('school_curricular_framework', function (Blueprint $table) {
            $table->boolean('mandatory')->default(false)->after('active');
        });

        // Aula+ loads the ANEP regime for every school (P23-DEC-01).
        $anep = DB::table('curricular_frameworks')->where('planner_family', 'anep')->pluck('id');
        $now = now();
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            foreach ($anep as $frameworkId) {
                $exists = DB::table('school_curricular_framework')
                    ->where('school_id', $schoolId)->where('curricular_framework_id', $frameworkId);
                if ($exists->exists()) {
                    $exists->update(['active' => true, 'mandatory' => true, 'updated_at' => $now]);
                } else {
                    DB::table('school_curricular_framework')->insert([
                        'school_id' => $schoolId,
                        'curricular_framework_id' => $frameworkId,
                        'active' => true,
                        'mandatory' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        Schema::table('school_planner_fields', function (Blueprint $table) {
            $table->string('description', 300)->nullable()->after('name');
            // Own fields only: 'ai' (the AI reads it) or 'record' (only kept).
            $table->string('purpose')->nullable()->after('description');
        });
        DB::table('school_planner_fields')->whereNull('planner_field_definition_id')->update(['purpose' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('school_planner_fields', function (Blueprint $table) {
            $table->dropColumn(['description', 'purpose']);
        });
        Schema::table('school_curricular_framework', function (Blueprint $table) {
            $table->dropColumn('mandatory');
        });
        DB::table('curricular_frameworks')->whereIn('planner_family', array_values(self::NEW_FAMILIES))
            ->update(['planner_family' => null]);
        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->dropColumn('education_levels');
        });
    }

    /** Keep in sync with CurricularFrameworkSeeder::LEVELS. */
    private const FRAMEWORK_LEVELS = [
        'ANEP EBI' => ['initial', 'primary', 'lower_secondary'],
        'ANEP Bachillerato' => ['upper_secondary'],
        'Cambridge Primary' => ['primary'],
        'Cambridge Lower Secondary' => ['lower_secondary'],
        'IGCSE' => ['lower_secondary', 'upper_secondary'],
        'IB PYP' => ['initial', 'primary'],
        'IB MYP' => ['lower_secondary', 'upper_secondary'],
        'IB DP' => ['upper_secondary'],
    ];

    private const NEW_FAMILIES = [
        'IGCSE' => 'igcse',
        'IB DP' => 'ib_dp',
    ];
};
