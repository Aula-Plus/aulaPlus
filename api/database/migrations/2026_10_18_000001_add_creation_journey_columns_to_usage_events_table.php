<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creation journeys for the adoption dashboard (ClickUp 86e3qkxtc, screen 28):
 * each step a teacher takes while creating content (form opened, filling
 * started, generation requested/finished, creation confirmed, draft
 * discarded) is one usage_events row sharing a `journey_id`.
 *
 * - `step` is the journey step (App\Enums\JourneyStep); null for rows that
 *   are not part of a journey (e.g. `login`).
 * - `content_type` / `content_id` identify the kind of content and, once
 *   created, the real row. `group_id` is the group it was created for.
 *
 * Existing `*.created` rows are backfilled as `creation_confirmed` steps of
 * their content type (their id stays in `metadata`), so counts and journeys
 * read one consistent shape. No other historical step can be reconstructed:
 * those simply have no data before this migration ran.
 *
 * `ai_proposals.journey_id` lets the generation Job and apply/discard link
 * their steps to the journey the proposal was requested in.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    protected const CREATED_EVENT_CONTENT_TYPES = [
        'annual_plan.created' => 'annual_plan',
        'class_session.created' => 'class_session',
        'assessment.created' => 'assessment',
    ];

    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->uuid('journey_id')->nullable()->after('event_type');
            $table->string('step')->nullable()->after('journey_id');
            $table->string('content_type')->nullable()->after('step');
            $table->unsignedBigInteger('content_id')->nullable()->after('content_type');
            $table->foreignId('group_id')->nullable()->after('content_id')->constrained()->nullOnDelete();

            $table->index(['school_id', 'step', 'created_at']);
            $table->index(['school_id', 'user_id', 'journey_id']);
        });

        // One statement (one table pass) instead of one per type: the
        // content type is the event type without its `.created` suffix.
        // replace() is portable across PostgreSQL and SQLite.
        DB::table('usage_events')
            ->whereIn('event_type', array_keys(self::CREATED_EVENT_CONTENT_TYPES))
            ->update([
                'step' => 'creation_confirmed',
                'content_type' => DB::raw("replace(event_type, '.created', '')"),
            ]);

        Schema::table('ai_proposals', function (Blueprint $table) {
            $table->uuid('journey_id')->nullable()->after('requested_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_proposals', function (Blueprint $table) {
            $table->dropColumn('journey_id');
        });

        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'step', 'created_at']);
            $table->dropIndex(['school_id', 'user_id', 'journey_id']);
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['journey_id', 'step', 'content_type', 'content_id']);
        });
    }
};
