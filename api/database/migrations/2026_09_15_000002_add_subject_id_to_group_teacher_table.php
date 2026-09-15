<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_teacher', function (Blueprint $table) {
            // A teacher-in-a-group assignment is per subject (Option A: one row
            // per subject, no nullable "teaches everything").
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['group_id', 'teacher_id']);
            $table->unique(['group_id', 'teacher_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('group_teacher', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'teacher_id', 'subject_id']);
            $table->dropConstrainedForeignId('subject_id');
            $table->unique(['group_id', 'teacher_id']);
        });
    }
};
