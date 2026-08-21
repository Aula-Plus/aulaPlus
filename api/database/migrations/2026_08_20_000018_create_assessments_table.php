<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->text('purpose')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->jsonb('content')->nullable();
            $table->integer('variant_number')->default(1);
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
