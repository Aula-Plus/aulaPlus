<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A subject's curricular program ("programa curricular", ClickUp 86e3dt6ag).
 *
 * - `syllabus_*`: one optional PDF per subject, uploaded by direction and
 *   stored on a private disk (CLAUDE.md rule 7) — only ever served through a
 *   short-lived temporary URL. `syllabus_text` is the text extracted from it,
 *   used as provisional AI planning context.
 * - `curricular_item_id`: the link between the school's subject and the
 *   global curricular catalog left open by the subjects design
 *   (docs/superpowers/specs/2026-09-15-subjects-materias-design.md, A2). Set
 *   by the Aula+ team once the subject's units/objectives are loaded in the
 *   catalog; from then on the AI uses the catalog instead of the PDF text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('curricular_item_id')->nullable()->after('color')->constrained('curricular_items')->nullOnDelete();
            $table->string('syllabus_path')->nullable()->after('curricular_item_id');
            $table->string('syllabus_original_name')->nullable()->after('syllabus_path');
            $table->unsignedInteger('syllabus_size')->nullable()->after('syllabus_original_name');
            $table->timestamp('syllabus_uploaded_at')->nullable()->after('syllabus_size');
            $table->foreignId('syllabus_uploaded_by_id')->nullable()->after('syllabus_uploaded_at')->constrained('users')->nullOnDelete();
            $table->longText('syllabus_text')->nullable()->after('syllabus_uploaded_by_id');
            $table->timestamp('syllabus_text_extracted_at')->nullable()->after('syllabus_text');
        });

        $this->restorePartialNameIndex();
    }

    /**
     * SQLite (the test database) adds foreign keys by rebuilding the table,
     * which recreates `subjects_school_id_name_unique` without its
     * `WHERE deleted_at IS NULL` clause. Put the partial index back so a
     * soft-deleted subject's name stays reusable. PostgreSQL alters in place
     * and keeps the index untouched.
     */
    protected function restorePartialNameIndex(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS subjects_school_id_name_unique');
        DB::statement(
            'CREATE UNIQUE INDEX subjects_school_id_name_unique ON subjects (school_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_uploaded_by_id');
            $table->dropConstrainedForeignId('curricular_item_id');
            $table->dropColumn([
                'syllabus_path',
                'syllabus_original_name',
                'syllabus_size',
                'syllabus_uploaded_at',
                'syllabus_text',
                'syllabus_text_extracted_at',
            ]);
        });

        $this->restorePartialNameIndex();
    }
};
