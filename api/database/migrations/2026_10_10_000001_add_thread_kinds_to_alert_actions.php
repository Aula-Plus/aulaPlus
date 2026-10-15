<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An alert's thread (ClickUp 86e3jpzdp follow-up): `alert_actions` becomes
 * the full history of the case, not only the ways out chosen.
 *
 * - `kind = outcome`: a way out chosen, with responsible and deadline;
 * - `kind = note`: an operational note by whoever has the alert;
 * - `kind = overdue`: the deadline arrived with the alert still pending
 *   («Llegó a su fecha sin salida»). Written by the daily `alerts:overdue`
 *   run, so it has no actor.
 *
 * Only an outcome step carries an outcome and a deadline, and only a person's
 * step carries an actor, so those columns become nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_actions', function (Blueprint $table) {
            $table->string('kind')->default('outcome')->after('actor_id');
            $table->unsignedBigInteger('actor_id')->nullable()->change();
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
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
            $table->string('outcome')->nullable(false)->change();
            $table->unsignedBigInteger('assignee_id')->nullable(false)->change();
            $table->date('due_on')->nullable(false)->change();
        });
    }
};
