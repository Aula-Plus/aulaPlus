<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meeting of psychopedagogy's agenda (screen 10) reaches the calendar of
 * each person cited as a shared event of type `meeting` (ClickUp
 * 1dfd3mc56kp, P21-DEC-01): the meeting keeps a link to that event, which
 * App\Services\Calendar\MeetingEvents creates and keeps in step. Removing
 * the event leaves the meeting without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psychopedagogy_meetings', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->nullable()->after('created_by_id')->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('psychopedagogy_meetings', function (Blueprint $table) {
            $table->dropUnique(['calendar_event_id']);
            $table->dropConstrainedForeignId('calendar_event_id');
        });
    }
};
