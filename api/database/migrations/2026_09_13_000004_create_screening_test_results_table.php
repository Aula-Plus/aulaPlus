<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's slot within a screening-test application (docs/prompts/
 * 11-pruebas-de-sondeo.md §§2-3). `code` is a short label unique WITHIN the
 * application (not global). `color` is computed and persisted at score-load
 * time from the type's then-current approved design — it is never re-derived
 * on read, so approving a new design does not change already-loaded colors.
 *
 * The code -> student mapping is sensitive (it de-anonymises results about a
 * minor, CLAUDE.md security rule 11): it is exposed ONLY by the roster
 * endpoint, never by the results-by-code endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screening_test_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->decimal('score', 8, 2)->nullable();
            $table->string('color')->nullable();
            $table->foreignId('loaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamps();

            $table->unique(['screening_test_application_id', 'code']);
            $table->index('school_id');
            // FK columns are not auto-indexed on PostgreSQL; index student_id
            // for the cascade on student delete and any by-student lookup.
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_test_results');
    }
};
