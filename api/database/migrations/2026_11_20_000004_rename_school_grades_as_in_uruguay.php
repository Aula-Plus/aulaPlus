<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Screen 23 «Colegio y estructura» (ClickUp 1dfd3mc4bh4): the grades are named
 * as in Uruguay (P23-DEC-10): «Inicial 3, 4 y 5 años», «1.° a 6.° de
 * Primaria», 7°CB to 9°CB and 1°EMS to 3°EMS. Only `label` changes; `key` is
 * the stable identifier.
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
        foreach (self::GRADE_LABELS as $key => [$label]) {
            DB::table('school_grades')->where('key', $key)->update(['label' => $label]);
        }
    }

    public function down(): void
    {
        foreach (self::GRADE_LABELS as $key => [, $old]) {
            DB::table('school_grades')->where('key', $key)->update(['label' => $old]);
        }
    }
};
