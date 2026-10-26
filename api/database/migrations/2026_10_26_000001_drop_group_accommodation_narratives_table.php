<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the AI text summary of a group's accommodations (ClickUp
 * 1dfd3mc2wwy, PO decision of 21/10): the AI no longer receives it and teachers
 * no longer read it. The texts already generated or published are deleted, not
 * hidden or archived. Rolling back only recreates the empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('group_accommodation_narratives');
    }

    public function down(): void
    {
        (require __DIR__.'/2026_10_03_000001_create_group_accommodation_narratives_table.php')->up();
    }
};
