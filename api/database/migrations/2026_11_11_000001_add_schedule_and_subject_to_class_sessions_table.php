<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar (screen 21, ClickUp 1dfd3mc4bgh): the time and the room are data
 * of the class itself, both optional (a class without a time shows on its day
 * without one). `subject_id` says which of the group's subjects the class is
 * for, so a group with several teachers shows each teacher only their own
 * classes, and the Inicio can say «Matemática · 9°CB-A».
 *
 * Existing rows inherit the subject of the annual plan their unit belongs to;
 * rows without a unit stay without a subject (visible to the group's teachers,
 * as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('group_id')->constrained()->nullOnDelete();
            $table->time('start_time')->nullable()->after('date');
            $table->string('room', 60)->nullable()->after('duration_minutes');

            $table->index(['school_id', 'date']);
        });

        DB::table('class_sessions')
            ->whereNotNull('unit_id')
            ->whereNull('subject_id')
            ->update([
                'subject_id' => DB::raw('(SELECT annual_plans.subject_id FROM units INNER JOIN annual_plans ON annual_plans.id = units.annual_plan_id WHERE units.id = class_sessions.unit_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'date']);
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn(['start_time', 'room']);
        });
    }
};
