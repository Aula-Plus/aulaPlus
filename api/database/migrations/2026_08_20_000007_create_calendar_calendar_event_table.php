<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_calendar_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['calendar_id', 'calendar_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_calendar_event');
    }
};
