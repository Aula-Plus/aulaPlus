<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI-written text summary of a group's active accommodations ("Ajustes
 * activos" on the group profile). Psychopedagogy generates a draft, reads it,
 * then publishes it for the group's teachers; the last published one is kept
 * with its date. `fingerprint` is a hash of the aggregated accommodations the
 * text was generated from, used to flag a published summary as outdated.
 * `content` is derived from aggregated, anonymised data only and is excluded
 * from the audit diff in the model. `flagged_types` lists, redacted, the
 * accommodation types that blocked a generation because they name a student.
 *
 * At most one `pending` row per group (partial unique index, valid on both
 * PostgreSQL and SQLite): a backstop for the controller's lock so two
 * concurrent requests can never pay for two AI calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_accommodation_narratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('content')->nullable();
            $table->string('fingerprint', 64);
            $table->text('error_message')->nullable();
            $table->json('flagged_types')->nullable();
            $table->foreignId('generated_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'group_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX group_accommodation_narratives_one_pending ON group_accommodation_narratives (group_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('group_accommodation_narratives');
    }
};
