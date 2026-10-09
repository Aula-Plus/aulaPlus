<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ayuda y sugerencias" messages from staff to the Aula+ team: bug reports
 * (`issue`) and improvement requests (`improvement`). `improvement_group` is
 * set by the Aula+ team (directly in the DB, no UI) to cluster requests that
 * ask for the same thing, so they can count requests and distinct schools.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('message');
            $table->string('role', 50);
            $table->string('screen', 255)->nullable();
            $table->string('improvement_group', 255)->nullable();
            $table->timestamps();

            $table->index(['kind', 'improvement_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
