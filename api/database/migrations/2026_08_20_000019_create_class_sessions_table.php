<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain doc lists an "Evaluación" field on ClassSession without further
 * detail. We assume an optional assessment_id linking the session to the
 * assessment taken during it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->integer('duration_minutes');
            $table->string('title');
            $table->text('objective')->nullable();
            $table->text('description')->nullable();
            $table->text('outcome')->nullable();
            $table->text('teacher_notes')->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
