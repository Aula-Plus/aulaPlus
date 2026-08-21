<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical group membership per school year. We assume a student belongs to
 * a single group per school year (unique(student_id, school_year)) — if the
 * business confirms a student can be in more than one group the same year,
 * drop that constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->integer('school_year');
            $table->jsonb('details')->nullable();
            $table->timestamps();

            $table->index('group_id');
            $table->unique(['student_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_student');
    }
};
