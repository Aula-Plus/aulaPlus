<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_curricular_framework', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_framework_id')->constrained()->cascadeOnDelete();
            $table->string('level_from')->nullable();
            $table->string('level_to')->nullable();
            $table->boolean('active')->default(true);
            $table->jsonb('configuration')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'curricular_framework_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_curricular_framework');
    }
};
