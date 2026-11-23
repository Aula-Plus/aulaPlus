<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screens 14 «Nueva clase» and 15 «Propuesta de clase» (ClickUp 86e3jujh0).
 *
 * - `sections`: the class the teacher confirmed, section by section (opening,
 *   central activity, closing, materials, assessment of the class). Only the
 *   sections the teacher approved or edited are stored; a rejected one never
 *   is (nothing is saved without approval, P15-DEC-01).
 * - `closing_result` / `closed_at`: the one-tap closing of the class
 *   («Todo / Una parte / Nada», P15-DEC-03). Null while the class is not
 *   closed — closing is optional and an unclosed class keeps its objectives
 *   as planned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->json('sections')->nullable()->after('description');
            $table->string('closing_result')->nullable()->after('status');
            $table->timestamp('closed_at')->nullable()->after('closing_result');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['sections', 'closing_result', 'closed_at']);
        });
    }
};
