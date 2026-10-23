<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 08 general data of a screening: population (which levels it is
 * taken in), how often it is taken, and whether teachers may see its results
 * (off by default — Psicopedagogía and Dirección always do).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->json('levels')->nullable()->after('area');
            $table->string('frequency')->nullable()->after('levels');
            $table->unsignedSmallInteger('frequency_years')->nullable()->after('frequency');
            $table->boolean('teacher_visible')->default(false)->after('frequency_years');
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->dropColumn(['levels', 'frequency', 'frequency_years', 'teacher_visible']);
        });
    }
};
