<?php

use App\Models\CommentCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comment "tone" (positive/neutral/concerning) is replaced by optional,
 * school-editable categories (several per comment). Comments never open
 * alerts on their own any more, so the auto-generated "behavior" alerts are
 * removed (no real data yet); recurrence of a category is surfaced as a trend
 * mark whose threshold each school configures (`comment_trend_*` columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('comment_comment_category', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_category_id')->constrained('comment_categories')->cascadeOnDelete();

            $table->primary(['comment_id', 'comment_category_id']);
        });

        Schema::table('schools', function (Blueprint $table) {
            // A trend = at least `min_count` comments of the same category in
            // the last `days` days. Defaults: 4 comments in 21 days.
            $table->unsignedSmallInteger('comment_trend_min_count')->default(4);
            $table->unsignedSmallInteger('comment_trend_days')->default(21);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('tone');
        });

        DB::table('alerts')->where('type', 'behavior')->delete();

        DB::table('schools')->pluck('id')->each(fn (int $schoolId) => CommentCategory::seedDefaults($schoolId));
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('tone')->nullable();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['comment_trend_min_count', 'comment_trend_days']);
        });

        Schema::dropIfExists('comment_comment_category');
        Schema::dropIfExists('comment_categories');
    }
};
