<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-written text summary of a group's active accommodations ("Ajustes
 * activos" on the group profile). Psychopedagogy generates a draft, reads it,
 * then publishes it for the group's teachers; the last published one is kept
 * with its date. `fingerprint` is a hash of the aggregated accommodations the
 * text was generated from, used to flag a published summary as outdated.
 * `content` is derived from aggregated, anonymised data only and is excluded
 * from the audit diff in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_accommodation_narratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('content')->nullable();
            $table->string('fingerprint', 64);
            $table->text('error_message')->nullable();
            $table->foreignId('generated_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_accommodation_narratives');
    }
};
