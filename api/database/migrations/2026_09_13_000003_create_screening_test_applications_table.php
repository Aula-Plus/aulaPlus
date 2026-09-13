<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applying a screening test to a group (docs/prompts/11-pruebas-de-sondeo.md
 * §2). Creating one auto-generates a ScreeningTestResult per active student of
 * the group, each with an anonymous per-application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_test_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screening_test_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applied_by_id')->constrained('users')->restrictOnDelete();
            $table->date('application_date');
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_test_applications');
    }
};
