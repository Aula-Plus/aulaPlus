<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * We deliberately do NOT seed any user here: security rule #1 forbids
     * hardcoded / shared "demo" accounts. Only the role catalogue is seeded.
     * Create the first real user with `php artisan app:create-user`.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
