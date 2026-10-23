<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Elegir personas» under each «Visible para» option (ClickUp 86e3qkwnv): a
 * per-role list of the specific people a comment is visible to, e.g.
 * `{"director": [3]}`. A role absent from the map keeps its whole audience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->json('visible_to_users')->nullable()->after('visible_to');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('visible_to_users');
        });
    }
};
