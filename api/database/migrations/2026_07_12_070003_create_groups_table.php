<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            // Tenant key. Applied automatically via the BelongsToSchool trait.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // The teacher who leads the group (nullable while unassigned).
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');             // e.g. "3° A"
            $table->string('level')->nullable(); // e.g. "Primaria" / "Secundaria"
            $table->string('year')->nullable();  // school year, e.g. "2026"
            $table->timestamps();

            $table->index(['school_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
