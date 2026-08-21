<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adopts the domain model for Student: membership in a group moves to the
 * group_student pivot (historical, per school year) created later in this
 * batch, so the direct group_id FK is dropped here. first_name/last_name are
 * replaced by a single full_name, and the tracking/profile fields from the
 * domain doc are added. The status/family-contact/pedagogical-notes fields
 * added ahead of the domain doc are dropped — soft deletes now cover
 * "student left the school", and family contact info is out of the current
 * domain scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('school_id');
        });

        DB::table('students')->update([
            'full_name' => DB::raw("trim(first_name || ' ' || last_name)"),
        ]);

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'group_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('full_name')->nullable(false)->change();

            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn([
                'first_name',
                'last_name',
                'status',
                'family_contact_name',
                'family_contact_phone',
                'family_contact_email',
                'pedagogical_notes',
            ]);

            $table->string('photo_url')->nullable()->after('full_name');
            $table->integer('enrollment_year')->nullable()->after('birth_date');
            $table->boolean('has_therapeutic_companion')->default(false)->after('enrollment_year');
            $table->jsonb('learning_profile')->nullable()->after('has_therapeutic_companion');
            $table->text('tracking_notes')->nullable()->after('learning_profile');
            $table->jsonb('individual_profile')->nullable()->after('tracking_notes');
            $table->jsonb('related_documents')->nullable()->after('individual_profile');
            $table->softDeletes();
        });

        // Backfill any pre-existing rows before tightening to NOT NULL.
        DB::table('students')->whereNull('enrollment_year')->update(['enrollment_year' => now()->year]);

        Schema::table('students', function (Blueprint $table) {
            $table->integer('enrollment_year')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'photo_url',
                'enrollment_year',
                'has_therapeutic_companion',
                'learning_profile',
                'tracking_notes',
                'individual_profile',
                'related_documents',
                'deleted_at',
            ]);

            $table->string('first_name')->nullable()->after('school_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('status')->default('active');
            $table->string('family_contact_name')->nullable();
            $table->string('family_contact_phone')->nullable();
            $table->string('family_contact_email')->nullable();
            $table->text('pedagogical_notes')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
        });

        DB::statement("update students set first_name = full_name, last_name = ''");

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });
    }
};
