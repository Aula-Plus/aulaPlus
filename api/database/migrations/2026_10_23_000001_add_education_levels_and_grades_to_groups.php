<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The level of a group as a choosable datum (ClickUp 86e3t4qjh).
 *
 * - `education_levels`: the same four levels for every school (Inicial,
 *   Primaria, Ciclo Básico, Bachillerato). `key` is the stable identifier,
 *   `label` only what is shown, so renaming a label never touches a group or a
 *   record. `screening_population` is the correspondence with a screening's
 *   population (screen 08), kept as data so it can change without code.
 * - `school_grades`: the grade list (8°, 1° de Bachillerato…), each with the
 *   level it suggests. Only a help to pre-fill the level, never a rule.
 * - `groups.education_level_id` (null = "Sin nivel": existing groups stay so
 *   until direction picks one — never deduced from the old free-text `level`)
 *   and `groups.school_grade_id` (optional).
 * - `schools.psychopedagogue_can_set_group_level`: direction may hand
 *   psychopedagogy the power to set a group's level.
 * - `screening_test_applications`: the group's level, the population it mapped
 *   to and the screening's population, as they were when it was applied, so a
 *   later change doesn't move that screening's impact view by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_levels', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('position');
            $table->string('screening_population')->nullable();
            $table->timestamps();
        });

        Schema::create('school_grades', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('position');
            $table->foreignId('education_level_id')->constrained('education_levels');
            $table->timestamps();
        });

        $now = now();
        $levels = [
            ['key' => 'initial', 'label' => 'Inicial', 'position' => 1, 'screening_population' => null],
            ['key' => 'primary', 'label' => 'Primaria', 'position' => 2, 'screening_population' => 'primary'],
            ['key' => 'lower_secondary', 'label' => 'Ciclo Básico', 'position' => 3, 'screening_population' => 'lower_secondary'],
            ['key' => 'upper_secondary', 'label' => 'Bachillerato', 'position' => 4, 'screening_population' => 'upper_secondary'],
        ];
        DB::table('education_levels')->insert(array_map(
            fn (array $level): array => [...$level, 'created_at' => $now, 'updated_at' => $now],
            $levels,
        ));
        $levelIds = DB::table('education_levels')->pluck('id', 'key');

        $grades = [
            ['initial', 'initial_3', 'Inicial 3 años'],
            ['initial', 'initial_4', 'Inicial 4 años'],
            ['initial', 'initial_5', 'Inicial 5 años'],
            ['primary', 'primary_1', '1° de Primaria'],
            ['primary', 'primary_2', '2° de Primaria'],
            ['primary', 'primary_3', '3° de Primaria'],
            ['primary', 'primary_4', '4° de Primaria'],
            ['primary', 'primary_5', '5° de Primaria'],
            ['primary', 'primary_6', '6° de Primaria'],
            ['lower_secondary', 'grade_7', '7°'],
            ['lower_secondary', 'grade_8', '8°'],
            ['lower_secondary', 'grade_9', '9°'],
            ['upper_secondary', 'upper_secondary_1', '1° de Bachillerato'],
            ['upper_secondary', 'upper_secondary_2', '2° de Bachillerato'],
            ['upper_secondary', 'upper_secondary_3', '3° de Bachillerato'],
        ];
        DB::table('school_grades')->insert(array_map(
            fn (array $grade, int $index): array => [
                'key' => $grade[1],
                'label' => $grade[2],
                'position' => $index + 1,
                'education_level_id' => $levelIds[$grade[0]],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $grades,
            array_keys($grades),
        ));

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('education_level_id')->nullable()->after('level')->constrained('education_levels');
            $table->foreignId('school_grade_id')->nullable()->after('education_level_id')->constrained('school_grades');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('psychopedagogue_can_set_group_level')->default(false);
        });

        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->foreignId('education_level_id')->nullable()->after('group_id')->constrained('education_levels');
            $table->string('screening_population')->nullable()->after('education_level_id');
            $table->json('population_levels')->nullable()->after('screening_population');
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('education_level_id');
            $table->dropColumn(['screening_population', 'population_levels']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('psychopedagogue_can_set_group_level');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_grade_id');
            $table->dropConstrainedForeignId('education_level_id');
        });

        Schema::dropIfExists('school_grades');
        Schema::dropIfExists('education_levels');
    }
};
