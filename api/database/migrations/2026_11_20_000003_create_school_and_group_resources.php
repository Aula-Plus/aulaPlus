<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 23 «Colegio y estructura» (ClickUp 1dfd3mc4bh4):
 *
 * - «Recursos disponibles» (P23-REC-02, PA-085): the school's resources (the
 *   garden, the lab, the computers), loaded by Dirección; each group unchecks
 *   the ones it does not have at hand (`group_resource_exclusions`) and adds
 *   its own (`group_added_resources`), for the whole group or only for the
 *   teacher who added it. Not to be confused with «Materiales» (screen 19,
 *   teaching materials).
 *
 * The grades' Uruguayan names are in 2026_11_20_000004.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('group_added_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // ResourceVisibility: `group` (every teacher of the group) or
            // `own` (only the teacher who added it; Dirección and
            // Psicopedagogía see it exists and whose it is).
            $table->string('visibility', 16)->default('group');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('group_resource_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_resource_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'school_resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_resource_exclusions');
        Schema::dropIfExists('group_added_resources');
        Schema::dropIfExists('school_resources');
    }
};
