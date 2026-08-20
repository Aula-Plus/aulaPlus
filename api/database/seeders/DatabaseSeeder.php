<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The role catalogue and the curricular framework catalogue are global
     * data, safe to seed in every environment. PilotSchoolsSeeder creates
     * real-looking staff accounts with a known factory password for local
     * development/demo only — security rule #1 forbids shared/demo
     * credentials in production, so it's gated to non-production envs.
     * Create the first real production user with `php artisan app:create-user`.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(CurricularFrameworkSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(PilotSchoolsSeeder::class);
        }
    }
}
