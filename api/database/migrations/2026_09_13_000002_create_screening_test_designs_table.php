<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A version of a screening-test type's design (docs/prompts/
 * 11-pruebas-de-sondeo.md §1). Same tri-state draft/approval pattern as
 * Accommodation.approved (null pending, true/false resolved): psychopedagogy
 * authors it, direction approves/rejects. A type keeps a full history of
 * designs; the one used to compute NEW colors is the most recent
 * approved = true. The color meanings are institutional text (screen 8
 * decision "Sin IA") — never written by the AI assistant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_test_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screening_test_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('cutoff_low', 8, 2);
            $table->decimal('cutoff_high', 8, 2);
            $table->text('meaning_red');
            $table->text('meaning_yellow');
            $table->text('meaning_green');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->boolean('approved')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'screening_test_type_id', 'approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_test_designs');
    }
};
