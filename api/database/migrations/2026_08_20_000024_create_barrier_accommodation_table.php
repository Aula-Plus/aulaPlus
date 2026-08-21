<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only the table and Eloquent relations are added here — the validation
 * workflow (who may validate, state transitions) is implemented in session 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barrier_accommodation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('validated')->default(false);
            $table->foreignId('validated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['barrier_id', 'accommodation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barrier_accommodation');
    }
};
