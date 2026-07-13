<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            // Tenant key. Applied automatically via the BelongsToSchool trait.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
