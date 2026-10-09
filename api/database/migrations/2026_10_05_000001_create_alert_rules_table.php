<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school-configured "sustained low performance" condition (ClickUp
 * 86e3jpzcv; documento vivo screen 11). The school picks the condition shape
 * and its numbers; `alerts:performance` evaluates every active rule daily.
 *
 * `consecutive_count` is only used by `consecutive_below`, `period_days` only
 * by `average_below` (validated in the Form Requests). `subject_id` narrows a
 * rule to one subject; null means it applies to every subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('condition');
            $table->decimal('threshold', 5, 2);
            $table->unsignedSmallInteger('consecutive_count')->nullable();
            $table->unsignedSmallInteger('period_days')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['school_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
