<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * High-volume adoption/usage log (docs/prompts/04-seguimiento-institucional.md
 * §5) feeding the director-only adoption dashboard. Deliberately NOT
 * Auditable — this is a log of usage, not a record needing full audit
 * trail/diff overhead, and no updated_at (rows are never modified).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['school_id', 'event_type', 'created_at']);
            $table->index(['user_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
