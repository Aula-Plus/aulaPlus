<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A result can be a level of an official scale, not only a number (ClickUp
 * 1dfd3mc4bgg): in Primaria each student is recorded with a level of the
 * rubric scale. Exactly one of `score` (decimal, as before) or
 * `official_scale_level_id` is set — the Form Request enforces which one per
 * group. `numeric_grade` is the optional 1..10 number the teacher writes next
 * to the level when the school has no equivalence of its own.
 *
 * Existing numeric results are untouched (score keeps its values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_results', function (Blueprint $table) {
            $table->decimal('score', 5, 2)->nullable()->change();
            $table->foreignId('official_scale_level_id')->nullable()->after('score')
                ->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('numeric_grade')->nullable()->after('official_scale_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('official_scale_level_id');
            $table->dropColumn('numeric_grade');
        });
    }
};
