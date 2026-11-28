<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The role a proposal was made with (ClickUp 1dfd3mc55zu, PA-002): direction
 * or, now, a teacher. Kept on the record because it decides who sees it while
 * it is not confirmed (a teacher's only its proposer and psychopedagogy,
 * P06-AJU-02) and who may withdraw or resubmit it — and a user's role may
 * change later. Every proposal before this one was direction's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('proposed_as', 32)->nullable()->after('proposed_by_id');
        });

        DB::table('accommodations')
            ->whereNotNull('proposed_by_id')
            ->update(['proposed_as' => 'director']);
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('proposed_as');
        });
    }
};
