<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global catalog, shared by every school. No school_id / BelongsToSchool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricular_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curricular_framework_id')->constrained()->cascadeOnDelete()->index();
            $table->string('name');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricular_catalogs');
    }
};
