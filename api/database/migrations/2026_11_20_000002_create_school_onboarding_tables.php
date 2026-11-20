<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): the record of how the
 * school's information came in. The Aula+ team loads it with artisan commands
 * (`app:onboarding-*`); Dirección only reads it (P29-DEC-115). Migration, not
 * sync: nothing here stays connected to the school's previous system.
 *
 * - `school_onboardings`: one row per school with the three header dates, when
 *   the school confirmed its offering and the Aula+ person assigned to it.
 * - `school_level_regimes`: the ANEP regime per level («habilitado» /
 *   «autorizado» + a note), loaded by Aula+, never declared by the school
 *   (P29-DEC-01). Shown read-only in screens 23 and 29.
 * - `onboarding_sources`: «Qué se recibió» (source, content, format, status).
 * - `onboarding_log_entries`: the «Bitácora» steps with their date and status.
 *
 * No student data anywhere: only what arrived and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('import_completed_on')->nullable();
            $table->date('regime_configured_on')->nullable();
            $table->date('opened_on')->nullable();
            $table->date('offering_confirmed_on')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_channel', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('school_level_regimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('education_level_id')->constrained('education_levels');
            $table->string('regime', 20);
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'education_level_id']);
        });

        Schema::create('onboarding_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('source', 120);
            $table->string('content', 300);
            $table->string('format', 40)->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('onboarding_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->date('happened_on');
            $table->string('step', 300);
            $table->string('status', 20);
            $table->timestamps();

            $table->index(['school_id', 'happened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_log_entries');
        Schema::dropIfExists('onboarding_sources');
        Schema::dropIfExists('school_level_regimes');
        Schema::dropIfExists('school_onboardings');
    }
};
