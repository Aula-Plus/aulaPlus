<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks on «Lo que recibe la IA» (ClickUp 1dfd3mc2wx2; documento vivo screen
 * 03, P03-AJU-03 / P03-ACT-53): psychopedagogy flags a line of a group's
 * input as «La IA lo entendió mal», and later either changes the
 * accommodation or says «El ajuste está bien».
 *
 * Their own table (not support_messages), countable and groupable by group,
 * line and origin type. What it keeps is only what the mark needs: the line
 * exactly as the AI received it (pedagogical action, subjects, assessment
 * types — already free of student names, see BuildAccommodationInput), its
 * stable key, the origin accommodation type (category), the subject chosen in
 * the view and psychopedagogy's optional comment. No student id, no name, no
 * count.
 *
 * `alerts.student_id` becomes nullable and `alerts.ai_input_mark_id` links
 * the weekly alert of a mark still pending — the first alert that is not
 * about one student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_input_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            // The subject chosen in the view when marking (optional).
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            // sha256 of the normalized line (BuildAccommodationInput::lineKey).
            $table->string('line_key', 64);
            // The line as the AI received it.
            $table->json('line');
            // Origin accommodation type (AccommodationCategory), null if none.
            $table->string('category')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Null while pending; AIInputMarkResolution once closed.
            $table->string('resolution')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'group_id', 'resolution']);
            $table->index(['group_id', 'line_key']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->change();
            $table->foreignId('ai_input_mark_id')->nullable()->after('comment_category_id')
                ->constrained('ai_input_marks')->nullOnDelete();
            $table->index('ai_input_mark_id');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['ai_input_mark_id']);
            $table->dropConstrainedForeignId('ai_input_mark_id');
        });

        Schema::dropIfExists('ai_input_marks');
    }
};
