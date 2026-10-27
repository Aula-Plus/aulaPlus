<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Recalcular con el nivel actual» (ClickUp 86e3t4qjh): who last took the
 * current level and population again on an application, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->foreignId('recalculated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recalculated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recalculated_by_id');
            $table->dropColumn('recalculated_at');
        });
    }
};
