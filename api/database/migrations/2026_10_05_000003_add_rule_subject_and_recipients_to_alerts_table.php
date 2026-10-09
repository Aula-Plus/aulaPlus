<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustained-low-performance alerts (ClickUp 86e3jpzcv) carry the subject they
 * were met in, the rule that produced them, the date the condition was met,
 * and a snapshot of who they reach first (`recipients`, a JSON list of
 * App\Enums\AlertRecipient values).
 *
 * `recipients` stays null on alerts created before this change: AlertPolicy
 * treats a null snapshot as the legacy rule (school-wide clinical roles only),
 * so existing alerts keep exactly the visibility they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->after('subject_id')->constrained()->nullOnDelete();
            $table->date('condition_met_on')->nullable()->after('description');
            $table->json('recipients')->nullable()->after('condition_met_on');

            $table->index(['student_id', 'subject_id', 'type', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'subject_id', 'type', 'resolved']);
            $table->dropConstrainedForeignId('alert_rule_id');
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn(['condition_met_on', 'recipients']);
        });
    }
};
