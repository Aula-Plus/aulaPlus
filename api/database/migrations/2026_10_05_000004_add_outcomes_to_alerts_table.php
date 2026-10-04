<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three ways out of an alert (ClickUp 86e3jpzdp).
 *
 * - `alerts` keeps the *current* state: the outcome chosen, the responsible
 *   person (`assignee_id`), the deadline (`due_on`), who chose it and when.
 *   `source_alert_id` links an "escalated, deadline passed" alert to the alert
 *   it escalates; `escalated_at` marks that the current hand-off was already
 *   escalated, so `alerts:escalate` never escalates it twice.
 * - `alert_actions` is the thread: every outcome chosen, with its optional
 *   note (an operational note — what was done with the case — never a
 *   diagnosis; excluded from the audit diff, CLAUDE.md rule 11).
 * - `alert_user` lists the people an alert reaches individually (whoever it
 *   was handed to, whoever handed it, and the people an escalated alert is
 *   for), on top of the role-based `recipients` snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('source_alert_id')->nullable()->after('alert_rule_id')->constrained('alerts')->cascadeOnDelete();
            $table->string('outcome')->nullable()->after('recipients');
            $table->foreignId('assignee_id')->nullable()->after('outcome')->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable()->after('assignee_id');
            $table->foreignId('outcome_by_id')->nullable()->after('due_on')->constrained('users')->nullOnDelete();
            $table->timestamp('outcome_at')->nullable()->after('outcome_by_id');
            $table->timestamp('escalated_at')->nullable()->after('outcome_at');

            $table->index(['outcome', 'resolved', 'due_on']);
        });

        Schema::create('alert_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('outcome');
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->date('due_on');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['alert_id', 'created_at']);
        });

        Schema::create('alert_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['alert_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_user');
        Schema::dropIfExists('alert_actions');

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['outcome', 'resolved', 'due_on']);
            $table->dropConstrainedForeignId('source_alert_id');
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropConstrainedForeignId('outcome_by_id');
            $table->dropColumn(['outcome', 'due_on', 'outcome_at', 'escalated_at']);
        });
    }
};
