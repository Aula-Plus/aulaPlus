<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each loaded result keeps the design version it was read with and the name
 * of the band it fell into then, besides its original score and date. When
 * the cutoffs change later, the result is shown with the current design and,
 * if the band changed, "con el diseño anterior era X" — so a change in the
 * student can be told apart from a change in the criterion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_results', function (Blueprint $table) {
            $table->foreignId('screening_test_design_id')->nullable()->after('score')
                ->constrained()->nullOnDelete();
            $table->string('band')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('screening_test_design_id');
            $table->dropColumn('band');
        });
    }
};
