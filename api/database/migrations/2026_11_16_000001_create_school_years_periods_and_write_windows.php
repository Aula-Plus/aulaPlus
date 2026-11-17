<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7):
 *
 * - `school_years`: one row per school and calendar year, open or closed.
 *   Direction picks the day from which closing is enabled
 *   (`closing_enabled_from`). Closing deletes or moves nothing: the year's
 *   groups stay as history and become read-only. «Deshacer el cierre» records
 *   who and when (`reopened_by_id`, `reopened_at`). `split_calendar_bodies`
 *   lists the bodies (Cambridge, IB) direction split by program this year.
 * - `school_periods`: the year's periods per body (`calendar_body`, e.g. ANEP
 *   Bachillerato or Cambridge) or, for a body split by program or a program
 *   with no body, per program (`curricular_framework_id`) — exactly one of
 *   the two. Each has a name and a start date. Independent from the
 *   «Visibilidad de calificaciones» cutoff (PA-040).
 * - `group_write_windows`: direction re-enables loading in one group of a
 *   closed year, with a reason and an until-date, recording who and when. A
 *   window past its date no longer counts; ending it early keeps the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status');
            $table->date('closing_enabled_from')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('split_calendar_bodies')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'year']);
        });

        Schema::create('school_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_body')->nullable();
            $table->foreignId('curricular_framework_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->timestamps();

            $table->index(['school_year_id', 'calendar_body', 'starts_on']);
            $table->index(['school_year_id', 'curricular_framework_id', 'starts_on']);
        });

        Schema::create('group_write_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 200);
            $table->date('until_on');
            $table->foreignId('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'until_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_write_windows');
        Schema::dropIfExists('school_periods');
        Schema::dropIfExists('school_years');
    }
};
