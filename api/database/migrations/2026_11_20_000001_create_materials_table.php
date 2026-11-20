<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 19 «Materiales» (ClickUp 1dfd3mc4bgx): a teacher's own materials and
 * the school's institutional catalog.
 *
 * A row is either a file someone uploaded (`source = upload`, private disk,
 * temporary URLs only — CLAUDE.md rule 7) or the catalog copy of a confirmed
 * assessment (`source = assessment`), whose `content` is a snapshot cleaned of
 * student marks (screen 19 red line). `subject_id` + `school_grade_id` are the
 * catalog's folders (P19-DEC-02: subject and grade before anything else).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('school_grade_id')->nullable()->constrained('school_grades');
            $table->string('source', 20);
            $table->foreignId('assessment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('type', 30);
            $table->json('tags')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('format', 10)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->json('content')->nullable();
            $table->string('catalog_status', 20)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users');
            $table->timestamp('no_student_data_confirmed_at')->nullable();
            $table->foreignId('no_student_data_confirmed_by_id')->nullable()->constrained('users');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['school_id', 'subject_id', 'school_grade_id']);
            $table->index(['school_id', 'owner_id']);
        });

        Schema::create('material_curricular_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['material_id', 'curricular_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_curricular_item');
        Schema::dropIfExists('materials');
    }
};
