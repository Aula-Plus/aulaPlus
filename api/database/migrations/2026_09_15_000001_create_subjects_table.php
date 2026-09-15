<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('short_code')->nullable();
            $table->string('color')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // A school cannot have two *live* subjects with the same name. This is a
        // PARTIAL unique index (excludes soft-deleted rows) so a name can be
        // reused after its subject is deleted — matching the StoreSubjectRequest
        // rule, which also ignores soft-deleted rows. A plain unique index would
        // otherwise 500 on a legitimate delete-then-recreate. Both PostgreSQL
        // (prod) and SQLite (tests) support partial indexes with this syntax.
        DB::statement(
            'CREATE UNIQUE INDEX subjects_school_id_name_unique ON subjects (school_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
