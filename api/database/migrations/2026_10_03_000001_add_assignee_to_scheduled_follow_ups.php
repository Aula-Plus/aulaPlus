<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled follow-up gets a responsible person (`assigned_to_id`, the
 * creator by default) and an optional list of people who also follow it
 * (`scheduled_follow_up_user`). Who may *see* the follow-up does not change —
 * everyone who sees the student still does; this only decides whose pending
 * list it shows up in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->foreignId('assigned_to_id')->nullable()->after('created_by_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['assigned_to_id', 'resolved']);
        });

        // Existing follow-ups belong to whoever created them.
        DB::table('scheduled_follow_ups')->update(['assigned_to_id' => DB::raw('created_by_id')]);

        Schema::create('scheduled_follow_up_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scheduled_follow_up_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_follow_up_user');

        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_id');
        });
    }
};
