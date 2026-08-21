<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adopts the domain model for Group: membership (teacher, students) moves to
 * the group_teacher / group_student pivots created later in this batch, so
 * the direct teacher_id FK is dropped here. `year` (string) is replaced by
 * `school_year` (integer) per the domain doc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'teacher_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn('year');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->integer('school_year')->after('level');
            $table->jsonb('group_profile')->nullable()->after('school_year');
            $table->jsonb('related_documents')->nullable()->after('group_profile');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['school_year', 'group_profile', 'related_documents']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('school_id')->constrained('users')->nullOnDelete();
            $table->string('year')->nullable();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->index(['school_id', 'teacher_id']);
        });
    }
};
