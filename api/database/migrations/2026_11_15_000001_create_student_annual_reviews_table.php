<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Revisión anual» of screen 06 (Ajustes y PTP, ClickUp 1dfd3mc4bgc): once
 * per school year, psychopedagogy marks that it reviewed a student's
 * accommodations (and, later, PTP). Nothing expires and nothing is switched
 * off by a missing review (P06-DEC-04): a missing row only raises a task in
 * psychopedagogy's Inicio. One row per student and school year; marking again
 * the same year moves the date and who to the latest mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_annual_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('school_year');
            $table->date('reviewed_on');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'school_year']);
            $table->index(['school_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_annual_reviews');
    }
};
