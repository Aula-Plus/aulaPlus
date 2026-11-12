<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional events (screen 21, P21-DEC-05): a date, an optional time and
 * what it is. `all_day` marks an event without a time (its `start_at` is the
 * school-local midnight of its day). `location` feeds the «Dónde» column of
 * the Inicio (P01-HOY-01). `created_by_id` records which director added it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->boolean('all_day')->default(false)->after('end_at');
            $table->string('location', 120)->nullable()->after('all_day');
            $table->foreignId('created_by_id')->nullable()->after('type')->constrained('users')->nullOnDelete();

            $table->index(['school_id', 'type', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'type', 'start_at']);
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropColumn(['all_day', 'location']);
        });
    }
};
