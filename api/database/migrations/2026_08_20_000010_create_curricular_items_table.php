<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global catalog, shared by every school. No school_id / BelongsToSchool.
 * Hierarchical via parent_id (simple adjacency list, no nested sets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricular_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curricular_catalog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('curricular_items')->cascadeOnDelete();
            $table->string('type');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['curricular_catalog_id', 'parent_id']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricular_items');
    }
};
