<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing an alert (ClickUp 86e3jpzdp follow-up): an alert can be in the
 * charge of two people at once, who follow it together.
 *
 * - `alert_collaborators` lists the co-responsible next to `alerts.assignee_id`
 *   (who shared it with them, and when). Like `alert_user`, it is a pivot of
 *   two tenant-scoped rows and carries no `school_id` of its own.
 * - `alert_actions` becomes the full thread: besides the way out chosen
 *   (`kind = outcome`), it records a share (`kind = share`, `assignee_id` =
 *   the co-responsible) and a plain note (`kind = note`). Only an outcome
 *   step carries an outcome and a deadline, so those columns become nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['alert_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('alert_actions', function (Blueprint $table) {
            $table->string('kind')->default('outcome')->after('actor_id');
            $table->string('outcome')->nullable()->change();
            $table->unsignedBigInteger('assignee_id')->nullable()->change();
            $table->date('due_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('alert_actions')->where('kind', '!=', 'outcome')->delete();

        Schema::table('alert_actions', function (Blueprint $table) {
            $table->dropColumn('kind');
            $table->string('outcome')->nullable(false)->change();
            $table->unsignedBigInteger('assignee_id')->nullable(false)->change();
            $table->date('due_on')->nullable(false)->change();
        });

        Schema::dropIfExists('alert_collaborators');
    }
};
