<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signal per category of an observation (ClickUp 86e3qkwpn): one of
 * App\Enums\CommentSignal (`improvement`, `difficulty`, `no_change`), stored
 * on the comment↔category pivot so it counts only in its own category.
 *
 * Nullable: every row written before signals existed stays `null` and counts
 * nowhere (neither the trend marks nor the «Señal de dificultad» alert). New
 * observations always carry one — enforced by the store Form Requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_comment_category', function (Blueprint $table) {
            $table->string('signal')->nullable();

            $table->index(['comment_category_id', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::table('comment_comment_category', function (Blueprint $table) {
            $table->dropIndex(['comment_category_id', 'signal']);
            $table->dropColumn('signal');
        });
    }
};
