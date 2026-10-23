<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Señal de dificultad» alert (ClickUp 86e3qkwpn): "N señales de dificultad en
 * una misma categoría en D días", configured by the school as one more
 * alert rule.
 *
 * - `alert_rules.signal_count` holds N (`period_days` already holds D).
 * - `alert_rules.threshold` becomes nullable: the new condition has no score
 *   threshold (the performance conditions still require it, validated in
 *   AlertRuleRequest).
 * - `alerts.comment_category_id` is the category the signals were counted
 *   in; null for every other alert type. Deleting the category keeps the
 *   alert (its description already names it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('signal_count')->nullable()->after('consecutive_count');
            $table->decimal('threshold', 5, 2)->nullable()->change();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('comment_category_id')->nullable()->after('subject_id')
                ->constrained('comment_categories')->nullOnDelete();

            $table->index(['student_id', 'comment_category_id', 'type', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'comment_category_id', 'type', 'resolved']);
            $table->dropConstrainedForeignId('comment_category_id');
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('signal_count');
        });
    }
};
