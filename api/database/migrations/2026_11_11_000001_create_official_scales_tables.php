<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Official grading scales (living document screen 24 · «Escalas oficiales»,
 * ClickUp 1dfd3mc4bgg). Global catalog data like the curricular frameworks:
 * NOT tenant-scoped, loaded once per framework by the Aula+ team
 * (OfficialScaleSeeder), never editable from the API or the UI.
 *
 * A scale belongs to one framework and, optionally, to one education level
 * (ANEP EBI has one scale for Primaria and another for Ciclo Básico). Each one
 * keeps its citation: the official document and its page.
 *
 * Levels are kept in the order the official document lists them: position 1
 * is the highest level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curricular_framework_id')->constrained()->cascadeOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            // Stable identifier used by the seeder (idempotent re-runs).
            $table->string('key')->unique();
            $table->string('name');
            $table->string('issuing_body');
            $table->string('citation_document');
            $table->string('citation_page');
            $table->string('source_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['curricular_framework_id', 'education_level_id']);
        });

        Schema::create('official_scale_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('official_scale_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['official_scale_id', 'position']);
            $table->unique(['official_scale_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_scale_levels');
        Schema::dropIfExists('official_scales');
    }
};
