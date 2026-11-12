<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The levels each alert condition applies to (ClickUp 1dfd3mc2wx0): a list
 * of `education_levels` ids — the same list groups and screenings use. Null
 * means every level, which is what the conditions configured before this
 * keep until someone changes them, so nobody stops getting an alert they get
 * today just because the option now exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->json('education_level_ids')->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('education_level_ids');
        });
    }
};
