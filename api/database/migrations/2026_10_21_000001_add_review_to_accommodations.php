<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direction proposes accommodations, psychopedagogy confirms them (ClickUp
 * 86e3qkwkk).
 *
 * - accommodations.review_status: null for an accommodation psychopedagogy
 *   created directly (every pre-existing row); otherwise the state of the
 *   direction proposal (App\Enums\AccommodationReviewStatus). Who proposed it
 *   and who confirmed or rejected it, and when, are recorded by the server.
 * - accommodation_review_events: the back-and-forth of a proposal (submitted,
 *   edited, returned, resubmitted, confirmed, withdrawn, rejected) with the
 *   comment/reason given and, for an edit, the names of the fields changed. `note` may hold sensitive content about a minor
 *   (CLAUDE.md rule 11): it is only returned to school-wide roles and never
 *   logged.
 * - scheduled_follow_ups.accommodation_id: the review task psychopedagogy gets
 *   for a proposal, linked to it and closed by the circuit itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('review_status')->nullable()->after('approved');
            $table->foreignId('proposed_by_id')->nullable()->after('review_status')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->after('proposed_by_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->index(['school_id', 'review_status']);
        });

        Schema::create('accommodation_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('note')->nullable();
            // Which fields an `edited` step changed (names only, never their
            // content — CLAUDE.md rule 11): the history says who changed what.
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['accommodation_id', 'id']);
        });

        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->foreignId('accommodation_id')->nullable()->after('student_id')
                ->constrained()->nullOnDelete();
            // PostgreSQL does not index foreign keys on its own; the circuit
            // looks the review task up by its proposal.
            $table->index('accommodation_id');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_follow_ups', function (Blueprint $table) {
            $table->dropIndex(['accommodation_id']);
            $table->dropConstrainedForeignId('accommodation_id');
        });

        Schema::dropIfExists('accommodation_review_events');

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'review_status']);
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropConstrainedForeignId('proposed_by_id');
            $table->dropColumn(['review_status', 'reviewed_at']);
        });
    }
};
