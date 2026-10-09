<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text area of a screening test type (e.g. "lectoescritura", "lenguaje").
 * Nullable: existing types have none, and the school fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->string('area', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};
