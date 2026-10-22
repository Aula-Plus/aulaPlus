<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product review of 14/10 (ClickUp 86e3jpzdp follow-up).
 *
 * - When an alert's deadline arrives and it is still pending, the SAME alert
 *   is marked «Llegó a su fecha sin salida» — no second "escalated" alert is
 *   created any more. `alerts.escalated_at` (which marked a hand-off already
 *   escalated) becomes `alerts.overdue_at` (when the current deadline was
 *   found passed without a way out). Renaming keeps the data: an alert that
 *   was already escalated is, from now on, an alert marked overdue. Escalated
 *   alerts already stored stay as they are (they still close when the alert
 *   they escalate moves); none is created any more.
 * - A scheduled follow-up can be created from an alert and stays linked to it
 *   (`scheduled_follow_ups.alert_id`). They are separate records: each keeps
 *   its own state, deadline and closing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->renameColumn('escalated_at', 'overdue_at');
        });

        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->foreignId('alert_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            // Every alert list eager-loads its linked follow-ups by alert_id;
            // PostgreSQL does not index a foreign key on its own.
            $table->index('alert_id');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->dropIndex(['alert_id']);
            $table->dropConstrainedForeignId('alert_id');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->renameColumn('overdue_at', 'escalated_at');
        });
    }
};
