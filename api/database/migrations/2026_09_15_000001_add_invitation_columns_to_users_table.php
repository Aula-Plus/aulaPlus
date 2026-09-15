<?php

// api/database/migrations/2026_09_15_000001_add_invitation_columns_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // An invited user has no password until they accept the invitation.
            $table->string('password')->nullable()->change();
            // Deactivation ("dar de baja") — reversible; never a hard delete.
            $table->timestamp('disabled_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
