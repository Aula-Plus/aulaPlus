<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('name');
            $table->string('anep_authorization_type')->nullable()->after('logo_url');
            $table->string('anep_primary_body')->nullable()->after('anep_authorization_type');
            $table->string('anep_secondary_body')->nullable()->after('anep_primary_body');
            $table->jsonb('levels_offered')->nullable()->after('anep_secondary_body');
            $table->jsonb('instruction_languages')->nullable()->after('levels_offered');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'logo_url',
                'anep_authorization_type',
                'anep_primary_body',
                'anep_secondary_body',
                'levels_offered',
                'instruction_languages',
            ]);
        });
    }
};
