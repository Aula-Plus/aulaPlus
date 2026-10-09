<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // What a teacher sees of grades in subjects they do not teach.
            $table->string('grades_visibility')->default('own_subject');
            // Periodic mode: cutoffs fall every N months counted from the anchor.
            $table->unsignedSmallInteger('grades_cutoff_months')->nullable();
            $table->date('grades_cutoff_anchor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['grades_visibility', 'grades_cutoff_months', 'grades_cutoff_anchor']);
        });
    }
};
