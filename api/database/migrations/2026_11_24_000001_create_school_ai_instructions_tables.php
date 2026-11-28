<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 24 «Evaluación y juicios» (ClickUp 1dfd3mc4bh6; P24-DEC-20 / PA-091,
 * P24-DEC-110 / PA-126).
 *
 * `school_ai_instructions`: the school's own layer on top of Aula+'s fixed
 * base, one row per rule. `request_type` is an AIRequestType. A rule without
 * `education_level_id` is the institution-wide one; with a level and no
 * `school_grade_ids` it covers the whole level; with grades it covers that
 * range (or that single grade) of the level. The most specific rule that
 * covers a group wins (P24-DEC-02). `settings` holds the fields of its type
 * (tone, words to avoid, what to achieve…): school-written guidance, never
 * student data.
 *
 * `judgment_style_previews`: «Ver ejemplo» (P24-JUI-02). A judgment written
 * in the school's style for an invented student, with the subject and the
 * unit names of a group Direction picks. It never holds data of a real
 * student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_ai_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 32);
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->json('school_grade_ids')->nullable();
            $table->json('settings');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'request_type']);
        });

        Schema::create('judgment_style_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('draft')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judgment_style_previews');
        Schema::dropIfExists('school_ai_instructions');
    }
};
