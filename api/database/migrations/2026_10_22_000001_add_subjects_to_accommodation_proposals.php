<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_proposals', function (Blueprint $table) {
            // Subjects the report points to (JSON list of subject ids); null/empty = every subject.
            $table->json('subject_ids')->nullable();
            // `explicit` (the report says it) or `inferred`; null when no subject was proposed.
            $table->string('subjects_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_proposals', function (Blueprint $table) {
            $table->dropColumn(['subject_ids', 'subjects_basis']);
        });
    }
};
