<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_curricular_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'curricular_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_curricular_item');
    }
};
