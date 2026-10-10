<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where and since when an accommodation applies (ClickUp 86e3dpbwy follow-up,
 * living document screen 04 · Ajustes):
 *
 * - `starts_on`: the date the accommodation is in force from ("desde cuándo
 *   rige"). Distinct from `created_at` (when the row was loaded). Existing rows
 *   are backfilled with the date they were created, then the column becomes
 *   NOT NULL — every accommodation has a start date.
 * - `assessment_types`: the assessment types it applies to (subset of
 *   App\Enums\AssessmentType). NULL = every type.
 * - `accommodation_subject`: the subjects it is restricted to. No rows = a
 *   general accommodation that applies in every subject. Teachers only see the
 *   general ones plus the ones scoped to a subject they teach in the student's
 *   group (Accommodation::scopeVisibleTo).
 *
 * The pivot carries no school_id of its own, like barrier_accommodation: both
 * sides are tenant-scoped rows and the FormRequest only accepts subjects of the
 * accommodation's school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('category');
            $table->jsonb('assessment_types')->nullable()->after('starts_on');
        });

        DB::table('accommodations')
            ->whereNull('starts_on')
            ->update(['starts_on' => DB::raw('DATE(created_at)')]);

        Schema::table('accommodations', function (Blueprint $table) {
            $table->date('starts_on')->nullable(false)->change();
        });

        Schema::create('accommodation_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['accommodation_id', 'subject_id']);
            // FK columns are not auto-indexed on PostgreSQL; the teacher
            // visibility check joins on subject_id.
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_subject');

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'assessment_types']);
        });
    }
};
