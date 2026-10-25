<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an accommodation approved from a student report to that report
 * (ClickUp 86e3jujgx), so «Origen» points at the file without anyone typing
 * it. Pre-existing rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->foreignId('origin_report_id')->nullable()->after('origin')
                ->constrained('report_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_report_id');
        });
    }
};
