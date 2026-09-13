<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the accommodation category (docs/prompts/18-ajustes-categoria-
 * instancia.md §1): access | content | criteria, independent of the free
 * `type` string so accommodations can be filtered/grouped by category.
 *
 * Nullable at the DB level so existing factories that don't set it keep
 * working (no real accommodations exist yet — the pilot has not started).
 * New writes require it via StoreAccommodationRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
