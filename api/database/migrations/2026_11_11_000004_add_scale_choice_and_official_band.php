<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClickUp 1dfd3mc4bgg, PO answers of 12/11:
 *
 * - `official_scale_levels.official_band`: the grade band the official table
 *   prints next to a level (e.g. ANEP «9–10»). Reference data shown with the
 *   scale's citation only; it never enters a calculation or a student's grade.
 * - `assessments.official_scale_id`: the scale an assessment records its
 *   results with. When a group has two valid scales the teacher chooses one
 *   before the first result; it is kept with the assessment so the results
 *   already recorded stay tied to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_scale_levels', function (Blueprint $table) {
            $table->string('official_band', 20)->nullable()->after('description');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('official_scale_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('official_scale_id');
        });

        Schema::table('official_scale_levels', function (Blueprint $table) {
            $table->dropColumn('official_band');
        });
    }
};
