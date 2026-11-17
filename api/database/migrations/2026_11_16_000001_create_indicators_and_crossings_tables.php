<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp, first delivery: the
 * four tabs). Psicopedagogía and Dirección only; the AI takes no part.
 *
 * - `screening_test_types.taken_every_year` («se toma todos los años»): which
 *   take an indicator reads — the current school year's for an annual
 *   screening, the latest of any year otherwise (P30-DAT-02, PO decision).
 *   Existing rows start from their frequency (yearly / twice a year → true).
 * - `external_measures` + `external_measure_values`: a datum the school
 *   brings from outside (template, CSV or by hand), one value per student and
 *   school year, always the original number.
 * - `indicators`: what the school calculates, with its bands and their
 *   meaning, where it would show and whether teachers see it (both only
 *   stored here; the student/group profiles are the second delivery).
 * - `crossings`: one or two conditions and the reading the school writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->boolean('taken_every_year')->default(false)->after('frequency_years');
        });

        DB::table('screening_test_types')
            ->whereIn('frequency', ['yearly', 'twice_yearly'])
            ->update(['taken_every_year' => true]);

        Schema::create('external_measures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('scale_min', 8, 2)->default(0);
            $table->decimal('scale_max', 8, 2)->default(100);
            $table->boolean('taken_every_year')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('school_id');
        });

        Schema::create('external_measure_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_measure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('school_year');
            $table->decimal('value', 8, 2);
            $table->foreignId('loaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['external_measure_id', 'student_id', 'school_year'], 'external_measure_values_unique');
            $table->index(['school_id', 'external_measure_id']);
        });

        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('calculation', 20);
            $table->json('inputs');
            $table->boolean('base_100')->default(false);
            $table->string('z_population', 20)->nullable();
            $table->json('bands');
            $table->json('display_places');
            $table->boolean('teacher_visible')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('school_id');
        });

        Schema::create('crossings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('conditions');
            $table->text('reading');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crossings');
        Schema::dropIfExists('indicators');
        Schema::dropIfExists('external_measure_values');
        Schema::dropIfExists('external_measures');

        Schema::table('screening_test_types', function (Blueprint $table) {
            $table->dropColumn('taken_every_year');
        });
    }
};
