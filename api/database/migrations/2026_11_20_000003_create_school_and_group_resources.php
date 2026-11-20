<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 23 «Colegio y estructura» (ClickUp 1dfd3mc4bh4):
 *
 * - «Recursos disponibles» (P23-REC-02, PA-085): the school's resources (the
 *   garden, the lab, the computers), loaded by Dirección; each group unchecks
 *   the ones it does not have at hand (`group_resource_exclusions`) and adds
 *   its own (`group_resources`). Not to be confused with «Materiales»
 *   (screen 19, teaching materials).
 * - The grades are named as in Uruguay (P23-DEC-10): «Inicial 3, 4 y 5 años»,
 *   «1.° a 6.° de Primaria», 7°CB to 9°CB and 1°EMS to 3°EMS. Only `label`
 *   changes; `key` is the stable identifier.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> key => [new label, old label] */
    protected const GRADE_LABELS = [
        'primary_1' => ['1.° de Primaria', '1° de Primaria'],
        'primary_2' => ['2.° de Primaria', '2° de Primaria'],
        'primary_3' => ['3.° de Primaria', '3° de Primaria'],
        'primary_4' => ['4.° de Primaria', '4° de Primaria'],
        'primary_5' => ['5.° de Primaria', '5° de Primaria'],
        'primary_6' => ['6.° de Primaria', '6° de Primaria'],
        'grade_7' => ['7°CB', '7°'],
        'grade_8' => ['8°CB', '8°'],
        'grade_9' => ['9°CB', '9°'],
        'upper_secondary_1' => ['1°EMS', '1° de Bachillerato'],
        'upper_secondary_2' => ['2°EMS', '2° de Bachillerato'],
        'upper_secondary_3' => ['3°EMS', '3° de Bachillerato'],
    ];

    public function up(): void
    {
        Schema::create('school_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('group_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('group_resource_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_resource_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'school_resource_id']);
        });

        foreach (self::GRADE_LABELS as $key => [$label]) {
            DB::table('school_grades')->where('key', $key)->update(['label' => $label]);
        }
    }

    public function down(): void
    {
        foreach (self::GRADE_LABELS as $key => [, $old]) {
            DB::table('school_grades')->where('key', $key)->update(['label' => $old]);
        }

        Schema::dropIfExists('group_resource_exclusions');
        Schema::dropIfExists('group_resources');
        Schema::dropIfExists('school_resources');
    }
};
