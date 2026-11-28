<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal and shared events (screen 21, ClickUp 1dfd3mc56kp): an event may
 * name one student of the school. Every participant sees the name; the link
 * to the student's file is only offered to whoever could already open it
 * (StudentPolicy) — the invitation never widens access. Removing the student
 * leaves the event without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('created_by_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
        });
    }
};
