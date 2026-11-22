<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an institutional event concerns (screen 21, P21-DEC-05/108; ClickUp
 * 1dfd3mc55zp, Eitán 17/11/2026). One row per target, and an event concerns
 * the groups that match ANY of its rows (a combination):
 *
 * - `education_level_id` only: a level (Primaria; «Secundaria» is Ciclo
 *   Básico + Bachillerato, two rows).
 * - `school_grade_id` only: a generation (every group of that grade).
 * - `school_grade_id` + `curricular_framework_id`: the groups of that grade
 *   that follow that program in their «Oferta curricular» (Cambridge in 3° de
 *   Primaria).
 * - `group_id` only: one group, by the name the school gave it («3° B»;
 *   Eitán, ClickUp 1dfd3mc55zp). A group of the event's school year. If the
 *   group is deleted the row stays with every column null and concerns
 *   nobody — never «the whole school», which would widen who sees it.
 *
 * No rows = the whole school, which is what every event created before this
 * keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained('education_levels');
            $table->foreignId('school_grade_id')->nullable()->constrained('school_grades');
            $table->foreignId('curricular_framework_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'calendar_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_audiences');
    }
};
