<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FK columns are not auto-indexed on PostgreSQL. The impact preview (screen
 * 08) reads every take of a screening through
 * `screening_test_applications.screening_test_type_id`, newest date first;
 * and `screening_test_results.screening_test_design_id` is scanned by its
 * `nullOnDelete` FK whenever a design row is deleted (cascade from its type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->index(['school_id', 'screening_test_type_id', 'application_date']);
        });

        Schema::table('screening_test_results', function (Blueprint $table) {
            $table->index('screening_test_design_id');
        });
    }

    public function down(): void
    {
        Schema::table('screening_test_results', function (Blueprint $table) {
            $table->dropIndex(['screening_test_design_id']);
        });

        Schema::table('screening_test_applications', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'screening_test_type_id', 'application_date']);
        });
    }
};
