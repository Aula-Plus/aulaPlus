<?php

use App\Models\MeetingSheetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Espacio de psicopedagogía» (screen 10, ClickUp 1dfd3mc4bge): the team's
 * agenda, the therapeutic companions of each student and the meeting sheets
 * («planillas») with their per-student fragments and actions, plus the
 * school-defined sheet types (P10-DEC-01), seeded for every existing school.
 *
 * A companion is a record, not a user account (decision in ClickUp). Free
 * text here (`content`, `description`) holds sensitive data about
 * minors (CLAUDE.md rule 11) and is excluded from the audit diffs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_sheet_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('therapeutic_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('weekly_hours', 4, 1);
            $table->json('days');
            // «Rol del acompañante» (ClickUp 1dfd3mc4bge): free text written
            // by psychopedagogy, visible to whoever sees the companion.
            $table->string('role', 160)->nullable();
            // A companion who stops accompanying gets an end date and stays in
            // the student's history; the current ones have none.
            $table->date('ended_on')->nullable();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'ended_on']);
        });

        Schema::create('psychopedagogy_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('meeting_sheet_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('scheduled_on');
            $table->time('start_time');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_attendees', 160)->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('note_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'scheduled_on']);
        });

        Schema::create('psychopedagogy_meeting_user', function (Blueprint $table) {
            $table->foreignId('psychopedagogy_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['psychopedagogy_meeting_id', 'user_id']);
        });

        Schema::create('meeting_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_sheet_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('psychopedagogy_meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->date('held_on');
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_attendees', 160)->nullable();
            $table->text('content');
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'held_on']);
        });

        Schema::create('meeting_sheet_user', function (Blueprint $table) {
            $table->foreignId('meeting_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['meeting_sheet_id', 'user_id']);
        });

        Schema::create('meeting_sheet_student', function (Blueprint $table) {
            $table->foreignId('meeting_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            $table->primary(['meeting_sheet_id', 'student_id']);
        });

        Schema::create('meeting_sheet_fragments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('scope', 40);
            // Who wrote the proposal: only `manual`. An AI proposal waits until
            // family data exists (product answer C, ClickUp 1dfd3mc4bge).
            $table->string('source', 16)->default('manual');
            $table->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['meeting_sheet_id', 'student_id']);
        });

        Schema::create('meeting_sheet_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_sheet_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('due_on');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'done_at']);
        });

        DB::table('schools')->pluck('id')->each(fn (int $schoolId) => MeetingSheetType::seedDefaults($schoolId));
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_sheet_actions');
        Schema::dropIfExists('meeting_sheet_fragments');
        Schema::dropIfExists('meeting_sheet_student');
        Schema::dropIfExists('meeting_sheet_user');
        Schema::dropIfExists('meeting_sheets');
        Schema::dropIfExists('psychopedagogy_meeting_user');
        Schema::dropIfExists('psychopedagogy_meetings');
        Schema::dropIfExists('therapeutic_companions');
        Schema::dropIfExists('meeting_sheet_types');
    }
};
