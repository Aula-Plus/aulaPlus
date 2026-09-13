<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivating an accommodation for a single assessment instance
 * (docs/prompts/18-ajustes-categoria-instancia.md §2). The "constancia"
 * (the reason it was switched off) is pinned to the assessment, never to the
 * student nor to the accommodation in general — the accommodation stays in
 * force for every other assessment.
 *
 * Tenant-scoped with its own school_id (the majority domain pattern) and
 * audited. `reason` is mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_instance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deactivated_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();

            // One override per accommodation per assessment instance.
            $table->unique(['accommodation_id', 'assessment_id']);
            $table->index(['school_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_instance_overrides');
    }
};
