<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Compartir con» (ClickUp 86e3qkwnv): psychopedagogy writes an *extract* of
 * an observation it can read and shares it with direction and/or teachers who
 * see the student. The extract is its own row, linked to the original
 * (`comment_id`), so recipients read only the extract and the original keeps
 * its scope. `student_id` is denormalized from the original so the student's
 * extract list is a single indexed query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });

        Schema::create('comment_share_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_share_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_share_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_share_user');
        Schema::dropIfExists('comment_shares');
    }
};
