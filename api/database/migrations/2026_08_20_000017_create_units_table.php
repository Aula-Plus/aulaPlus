<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped, but inherits its tenant via annual_plan (no school_id column
 * of its own) — per the domain doc. Isolation is enforced by always reaching
 * Unit through its AnnualPlan relation, which is itself BelongsToSchool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annual_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('position');
            $table->date('start_date');
            $table->date('end_date');
            $table->jsonb('materials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
