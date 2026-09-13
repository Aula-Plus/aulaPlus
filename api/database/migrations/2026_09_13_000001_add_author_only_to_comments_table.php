<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fourth comment scope "only the author" (docs/prompts/19-comentarios-alcance.md
 * §1). The three role-based scopes ("everyone who sees the student/group" /
 * "director only" / "psychopedagogue only") are already expressible via
 * `visible_to`, but restricting to a single *person* is not — a role is shared
 * by many users. When `author_only` is true the comment is visible only to
 * `author_id`, regardless of `visible_to` (which is forced to null in that case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('author_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('author_only');
        });
    }
};
