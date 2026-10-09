<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school, per-alert-type override of who an alert reaches first (ClickUp
 * 86e3jpzcv). A missing row means the type uses its default from
 * App\Support\AlertRouting — so a school that never touches the screen still
 * gets the product defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_routing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('recipients');
            $table->timestamps();

            $table->unique(['school_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_routing_settings');
    }
};
