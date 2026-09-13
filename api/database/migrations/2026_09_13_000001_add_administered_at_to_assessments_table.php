<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date an assessment was actually taken, distinct from created_at (when the
 * record was loaded) — see docs/prompts/13-evaluaciones-resultados.md §1. A real
 * performance timeline for the student profile needs this, so it's not null.
 *
 * No backfill: the pilot hasn't started, so there's no production data to
 * migrate. A separate migration (not an edit to the original Session 1 table
 * definition) keeps the domain-model migration history intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->date('administered_at')->after('variant_number');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('administered_at');
        });
    }
};
