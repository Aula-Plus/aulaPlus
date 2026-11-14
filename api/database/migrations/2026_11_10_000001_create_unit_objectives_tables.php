<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectives of a unit (screens 12 and 13 of the living document, ClickUp
 * 1dfd3mc4bg8). The teacher writes them; a unit's status and the plan's
 * coverage are derived from them, never chosen by hand (P12-DEC-03):
 *
 * - planned: the objective has a class session or an assessment built for it;
 * - given: a class closed covering it fully, or an assessment with results
 *   worked it.
 *
 * The two pivots are where classes and assessments point at the objectives
 * they work. `coverage` is what the teacher records when closing a class
 * (full / partial / none); it stays null while the class is not closed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->integer('position');
            $table->timestamps();
            $table->index(['school_id', 'unit_id']);
        });

        Schema::create('class_session_unit_objective', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_objective_id')->constrained()->cascadeOnDelete();
            $table->string('coverage')->nullable();
            $table->timestamps();
            $table->unique(['class_session_id', 'unit_objective_id']);
        });

        Schema::create('assessment_unit_objective', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_objective_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['assessment_id', 'unit_objective_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_unit_objective');
        Schema::dropIfExists('class_session_unit_objective');
        Schema::dropIfExists('unit_objectives');
    }
};
