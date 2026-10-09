<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Team-facing summary of the student's technical report (strengths /
            // what is hard in class terms / adjustments). Written and confirmed by
            // psychopedagogy; never names a diagnosis. Hidden from everyone until
            // `team_summary_confirmed_at` is set.
            $table->text('team_summary_strengths')->nullable();
            $table->text('team_summary_difficulties')->nullable();
            $table->text('team_summary_adjustments')->nullable();
            $table->timestamp('team_summary_confirmed_at')->nullable();
            $table->foreignId('team_summary_confirmed_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_summary_confirmed_by_id');
            $table->dropColumn([
                'team_summary_strengths',
                'team_summary_difficulties',
                'team_summary_adjustments',
                'team_summary_confirmed_at',
            ]);
        });
    }
};
