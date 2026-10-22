<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-ups with a title, stages, several responsibles and a history
 * (ClickUp 86e3qkwna).
 *
 * - `scheduled_follow_ups.title`: required for new follow-ups (validated in
 *   StoreScheduledFollowUpRequest). Nullable at the DB level only so existing
 *   rows can be backfilled: they get the first line of their description,
 *   truncated to 80 characters.
 * - `follow_up_stages`: one or more per follow-up. Subdividing a stage marks
 *   it `split` and creates children pointing at it (`parent_stage_id`), so the
 *   original keeps its notes and history.
 * - `follow_up_stage_notes`: "avances y aclaraciones" of a stage.
 * - `follow_up_members`: general responsibles and participants, replacing the
 *   two-role `assigned_to_id` + `scheduled_follow_up_user` pair. Rows are never
 *   deleted: withdrawal/removal is recorded on the row (who, when, reason).
 *   `assigned_to_id` stays, kept in sync with the first general responsible,
 *   for the existing lists that read it.
 * - `follow_up_events`: who changed what and when (incl. old/new deadline).
 *
 * Existing follow-ups are migrated to one stage (its description, assignee and
 * due date; done with the resolution note as conclusion when it was resolved),
 * their assignee as general responsible, and the creator and shared people as
 * participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->string('title', 150)->nullable()->after('student_id');
        });

        Schema::create('follow_up_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_stage_id')->nullable()->constrained('follow_up_stages')->nullOnDelete();
            $table->text('description');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date');
            $table->string('status', 20)->default('pending');
            $table->text('conclusion')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // PostgreSQL does not index foreign keys on its own.
            $table->index(['scheduled_follow_up_id', 'status']);
            $table->index(['responsible_id', 'status']);
            $table->index('parent_stage_id');
        });

        Schema::create('follow_up_stage_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follow_up_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            // Notes are always loaded per stage (eager load by stage ids).
            $table->index('follow_up_stage_id');
        });

        Schema::create('follow_up_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_follow_up_id', 'user_id']);
            $table->index(['user_id', 'hidden_at']);
        });

        Schema::create('follow_up_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follow_up_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['scheduled_follow_up_id', 'created_at']);
            $table->index('follow_up_stage_id');
        });

        $this->backfill();

        Schema::dropIfExists('scheduled_follow_up_user');
    }

    protected function backfill(): void
    {
        $shared = DB::table('scheduled_follow_up_user')->get()->groupBy('scheduled_follow_up_id');

        DB::table('scheduled_follow_ups')->orderBy('id')->each(function (object $row) use ($shared): void {
            $firstLine = trim(strtok($row->description, "\n") ?: $row->description);
            DB::table('scheduled_follow_ups')->where('id', $row->id)->update([
                'title' => mb_substr($firstLine !== '' ? $firstLine : 'Seguimiento', 0, 80),
            ]);

            $responsibleId = $row->assigned_to_id ?? $row->created_by_id;
            $resolved = (bool) $row->resolved;
            DB::table('follow_up_stages')->insert([
                'school_id' => $row->school_id,
                'scheduled_follow_up_id' => $row->id,
                'description' => $row->description,
                'responsible_id' => $responsibleId,
                'due_date' => $row->due_date,
                'status' => $resolved ? 'done' : 'pending',
                'conclusion' => $resolved ? $row->resolution_note : null,
                'closed_by_id' => $resolved ? $row->resolved_by_id : null,
                'closed_at' => $resolved ? $row->resolved_at : null,
                'created_by_id' => $row->created_by_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $members = [$responsibleId => 'general_responsible'];
            foreach ([$row->created_by_id, ...($shared[$row->id] ?? collect())->pluck('user_id')->all()] as $userId) {
                $members[$userId] ??= 'participant';
            }
            foreach ($members as $userId => $role) {
                DB::table('follow_up_members')->insert([
                    'school_id' => $row->school_id,
                    'scheduled_follow_up_id' => $row->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            DB::table('follow_up_events')->insert([
                'school_id' => $row->school_id,
                'scheduled_follow_up_id' => $row->id,
                'actor_id' => $row->created_by_id,
                'type' => 'created',
                'data' => json_encode(['due_date' => $row->due_date]),
                'created_at' => $row->created_at,
            ]);
            if ($resolved) {
                DB::table('follow_up_events')->insert([
                    'school_id' => $row->school_id,
                    'scheduled_follow_up_id' => $row->id,
                    'actor_id' => $row->resolved_by_id,
                    'type' => 'resolved',
                    'note' => $row->resolution_note,
                    'created_at' => $row->resolved_at ?? $row->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::create('scheduled_follow_up_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scheduled_follow_up_id', 'user_id']);
        });

        // Active participants go back to being "shared with".
        DB::table('follow_up_members')
            ->where('role', 'participant')
            ->whereNull('withdrawn_at')
            ->whereNull('removed_at')
            ->orderBy('id')
            ->each(function (object $member): void {
                DB::table('scheduled_follow_up_user')->insert([
                    'scheduled_follow_up_id' => $member->scheduled_follow_up_id,
                    'user_id' => $member->user_id,
                    'created_at' => $member->created_at,
                    'updated_at' => $member->updated_at,
                ]);
            });

        Schema::dropIfExists('follow_up_events');
        Schema::dropIfExists('follow_up_members');
        Schema::dropIfExists('follow_up_stage_notes');
        Schema::dropIfExists('follow_up_stages');

        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
