<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Mis horarios» (ClickUp 1dfd3mc4bgh): a teacher's own, private weekly
 * timetable per group-subject they teach. It is a default value for the time
 * and room of a class being created or edited — never a source of truth and
 * never a generator of dates. `weekday` is ISO-8601 (1 = Monday … 7 = Sunday);
 * `start_time` is school-local.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->string('room', 60)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'teacher_id']);
            $table->unique(['teacher_id', 'group_id', 'subject_id', 'weekday', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
