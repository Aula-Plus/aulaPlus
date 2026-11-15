<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClickUp 1dfd3mc4zpk: Psychopedagogy's «N evaluaciones hoy» total in the
 * Inicio is on by default and each person can hide it, from the Inicio itself
 * or from the Calendar's filters. One per-person preference kept on the server
 * so it follows the person across computers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_today_assessments_total')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_today_assessments_total');
        });
    }
};
