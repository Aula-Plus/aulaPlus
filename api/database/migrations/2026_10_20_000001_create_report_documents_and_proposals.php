<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Direction's switch for the AI reading of student reports. Off by
            // default: a school opts in after reading what happens to the data.
            $table->boolean('ai_report_interpretation_enabled')->default(false);
        });

        Schema::create('report_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Private disk path under a random name; never exposed, only via a temporary URL.
            $table->string('path');
            $table->string('original_name');
            $table->string('format', 8);
            $table->unsignedInteger('size');
            // `clinical` (psychopedagogy + direction) or `teachers` (enabled for teachers).
            $table->string('visibility')->default('clinical');
            $table->string('status')->default('processing');
            // Encrypted JSON lists of page texts (original / anonymized): clinical content.
            $table->longText('pages')->nullable();
            $table->longText('anonymized_pages')->nullable();
            // Whether psychopedagogy asked for the AI reading of this report.
            $table->boolean('use_ai')->default(true);
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('send_confirmed_at')->nullable();
            $table->foreignId('send_confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id']);
        });

        Schema::create('accommodation_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category');
            $table->text('description');
            $table->string('focus_area')->nullable();
            // `explicit` (the report says it), `inferred` (the AI deduced it) or `manual` (added by psychopedagogy).
            $table->string('basis');
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->text('source_fragment')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('accommodation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'report_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_proposals');
        Schema::dropIfExists('report_documents');

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('ai_report_interpretation_enabled');
        });
    }
};
