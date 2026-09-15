<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            // Every assessment is of exactly one subject (decision C1). NOT NULL
            // from the start — there is no production data to backfill (C2).
            $table->foreignId('subject_id')->after('group_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
        });
    }
};
