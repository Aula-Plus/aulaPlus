<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-actor comments on a Student or Group (polymorphic — see
 * docs/prompts/04-seguimiento-institucional.md §1). Kept polymorphic even
 * though only Student/Group are used today, to avoid a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('content');
            $table->string('tone')->nullable();
            $table->jsonb('visible_to')->nullable();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id']);
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
