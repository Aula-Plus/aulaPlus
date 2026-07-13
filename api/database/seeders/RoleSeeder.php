<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the three staff roles (idempotent). Safe to run in every environment,
 * including production — it seeds no users and no credentials, only role rows.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure fresh reads while seeding (Spatie caches roles/permissions).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::values() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
