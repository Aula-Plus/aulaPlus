<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student-file change history (ClickUp 86e3qkwmc). Extends the existing
 * audit trail instead of adding a parallel table:
 *
 * - `student_id` / `section`: which student's file and which part of it the
 *   entry belongs to (App\Enums\StudentFileSection), so the history can be
 *   queried and filtered without re-deriving ownership per model.
 * - `actor_role`: the actor's role at the time of the change (roles can
 *   change later; the history must show the one they acted with).
 * - `details`: the readable before/after values (or the created/deleted
 *   snapshot) for the history view. Unlike `changes` — which keeps redacting
 *   sensitive fields exactly as before — it may hold content about a minor,
 *   so it is encrypted at rest (`encrypted:array` cast on AuditLog) and only
 *   returned by the history endpoint after the per-record visibility check
 *   (CLAUDE.md rule 11).
 *
 * Existing rows are back-filled with student_id/section where the owning
 * record still exists; their `details` stay null (the history then falls
 * back to the redacted `changes`). Class names are literal strings so this
 * migration does not depend on the models as they evolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('section')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('details')->nullable();

            $table->index(['student_id', 'id']);
        });

        $direct = [
            'App\\Models\\Accommodation' => ['accommodations', 'accommodations'],
            'App\\Models\\Barrier' => ['barriers', 'barriers'],
            'App\\Models\\TechnicalReport' => ['technical_reports', 'reports'],
            'App\\Models\\ScheduledFollowUp' => ['scheduled_follow_ups', 'follow_ups'],
            'App\\Models\\Alert' => ['alerts', 'alerts'],
        ];

        foreach ($direct as $class => [$table, $section]) {
            DB::update(
                "update audit_logs set section = ?, student_id = (select {$table}.student_id from {$table} where {$table}.id = audit_logs.auditable_id) where auditable_type = ? and student_id is null",
                [$section, $class],
            );
        }

        DB::update(
            'update audit_logs set section = ?, student_id = auditable_id where auditable_type = ? and student_id is null and auditable_id in (select id from students)',
            ['student_data', 'App\\Models\\Student'],
        );

        DB::update(
            'update audit_logs set section = ?, student_id = (select comments.commentable_id from comments where comments.id = audit_logs.auditable_id) where auditable_type = ? and student_id is null and auditable_id in (select id from comments where commentable_type = ?)',
            ['observations', 'App\\Models\\Comment', 'App\\Models\\Student'],
        );

        DB::update(
            'update audit_logs set section = ?, student_id = (select accommodations.student_id from accommodation_instance_overrides inner join accommodations on accommodations.id = accommodation_instance_overrides.accommodation_id where accommodation_instance_overrides.id = audit_logs.auditable_id) where auditable_type = ? and student_id is null',
            ['accommodations', 'App\\Models\\AccommodationInstanceOverride'],
        );

        // A row whose owner no longer exists got student_id = null from the
        // subquery: drop its section too so it is not half-annotated.
        DB::update('update audit_logs set section = null where student_id is null');
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'id']);
            $table->dropConstrainedForeignId('student_id');
            $table->dropColumn(['section', 'actor_role', 'details']);
        });
    }
};
