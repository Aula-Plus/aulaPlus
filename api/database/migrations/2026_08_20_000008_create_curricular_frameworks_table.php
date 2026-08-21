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
        Schema::create('curricular_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricular_frameworks');
    }
};
