<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's result on one assessment (docs/prompts/13-evaluaciones-
 * resultados.md §2). Tenant-scoped with its own school_id (the majority domain
 * pattern), audited, and authored (created_by_id).
 *
 * The unique (assessment_id, student_id) pair enforces one result per student
 * per assessment — loading a result again updates the existing row (upsert),
 * it never duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->text('feedback')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_results');
    }
};
