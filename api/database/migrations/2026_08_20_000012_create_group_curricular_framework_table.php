<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_curricular_framework', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_framework_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'curricular_framework_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_curricular_framework');
    }
};
