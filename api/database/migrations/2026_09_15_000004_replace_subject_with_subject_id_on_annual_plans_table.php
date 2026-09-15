<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            // No production data (C2): drop the free-text subject and replace it
            // with an FK to the school's Subject catalog.
            $table->dropColumn('subject');
            $table->foreignId('subject_id')->after('group_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->string('subject');
        });
    }
};
