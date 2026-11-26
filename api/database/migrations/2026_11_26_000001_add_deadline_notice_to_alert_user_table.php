<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Avisarme a mí también cuando llegue el plazo» (ClickUp 86e3jpzdp, PO
 * decision of 24/10): whoever handed an alert off may ask to be told too when
 * its deadline passes. Off by default; stored per person on the alert's
 * `alert_user` row, so it survives the alert being handed off again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_user', function (Blueprint $table) {
            $table->boolean('notify_at_deadline')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('alert_user', function (Blueprint $table) {
            $table->dropColumn('notify_at_deadline');
        });
    }
};
