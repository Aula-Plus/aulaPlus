<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Origen» of an accommodation (ClickUp 86e3qkwkk, decision PA-031 of
 * 21/10/2026): free text that only psychopedagogy edits, filled in when an
 * accommodation is created or a direction proposal is confirmed. Shown to
 * psychopedagogy and direction only, never to a teacher. May name a clinical
 * report, so it is kept out of the audit diff (CLAUDE.md rule 11).
 *
 * Pre-existing rows stay null: their origin is not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('origin', 255)->nullable()->after('focus_area');
        });
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
