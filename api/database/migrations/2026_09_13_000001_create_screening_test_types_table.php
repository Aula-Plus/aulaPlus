<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screening-test catalog (docs/prompts/11-pruebas-de-sondeo.md §1). A school
 * may have several active types at once (e.g. reading and mathematics), each
 * with its own design history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_test_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_test_types');
    }
};
