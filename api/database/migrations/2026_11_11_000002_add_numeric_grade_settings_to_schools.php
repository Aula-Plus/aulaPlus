<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the school decides about its Primaria scale (screen 24, P24-EVA-03 /
 * P24-ESC-02, ClickUp 1dfd3mc4bgg):
 *
 * - `schools.show_primary_numeric_grade`: «Mostrar la calificación numérica
 *   junto al nivel de avance». One switch for every place a Primaria grade is
 *   shown; off = no number anywhere.
 * - `school_scale_equivalences`: the school's OWN, optional equivalence
 *   level → number 1..10. Aula+ never converts between scales; this is
 *   declared by the school and shown as the school's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('show_primary_numeric_grade')->default(false);
        });

        Schema::create('school_scale_equivalences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('official_scale_level_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('numeric_grade');
            $table->timestamps();

            $table->unique(['school_id', 'official_scale_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_scale_equivalences');

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('show_primary_numeric_grade');
        });
    }
};
