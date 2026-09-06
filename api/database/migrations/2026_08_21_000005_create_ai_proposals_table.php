<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI teaching-assistant proposals (docs/prompts/05-asistente-ia-docente.md §2).
 * Every proposal is a draft the requesting teacher explicitly applies or
 * discards — the assistant proposes, it never decides.
 *
 * `context_sent` and `raw_response` are large JSON blobs; they are excluded
 * from the audit diff in App\Models\AIProposal (CLAUDE.md security rules 2 and
 * 11 — the context snapshot must never leak un-anonymized student data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->jsonb('input_parameters');
            $table->jsonb('context_sent')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('applied_to_id')->nullable();
            $table->string('applied_to_type')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
            $table->index(['school_id', 'requested_by_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
    }
};
