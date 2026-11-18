<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 13 — Unidad (ClickUp 1dfd3mc4bga).
 *
 * - `units.planner_values`: what the teacher filled in the unit planner, keyed
 *   by the resolved field key (a catalog field's `key`, or `school_<id>` for a
 *   school's own field). No field is mandatory (P13-DEC-07).
 * - `unit_objective_curricular_item`: the curricular items an objective works
 *   (P13-DEC-05). Reached only through the tenant-scoped objective.
 * - `curricular_items.source` / `page`: the citation each catalog element is
 *   shown with («con su fuente y página»). Global catalog content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->json('planner_values')->nullable();
        });

        Schema::table('curricular_items', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->string('page', 50)->nullable();
        });

        Schema::create('unit_objective_curricular_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_objective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['unit_objective_id', 'curricular_item_id'], 'unit_objective_curricular_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_objective_curricular_item');

        Schema::table('curricular_items', function (Blueprint $table) {
            $table->dropColumn(['source', 'page']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('planner_values');
        });
    }
};
