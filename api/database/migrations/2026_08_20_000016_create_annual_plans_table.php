<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_framework_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            // When set, this is an individualized plan (PEI/PTP) for this student
            // within the group, instead of the group's general annual plan.
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->text('description');
            $table->integer('year');
            $table->string('subject');
            $table->string('language');
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_plans');
    }
};
