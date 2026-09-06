<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('status')->default('active')->after('birth_date');
            $table->string('family_contact_name')->nullable()->after('status');
            $table->string('family_contact_phone')->nullable()->after('family_contact_name');
            $table->string('family_contact_email')->nullable()->after('family_contact_phone');
            $table->text('pedagogical_notes')->nullable()->after('family_contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'family_contact_name',
                'family_contact_phone',
                'family_contact_email',
                'pedagogical_notes',
            ]);
        });
    }
};
