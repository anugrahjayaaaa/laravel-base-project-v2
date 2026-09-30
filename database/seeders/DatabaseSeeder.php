<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            // After RoleSeeder (roles must exist to receive permissions) and
            // before SuperAdminSeeder (so the superadmin account and its role
            // assignment are consistent within a single db:seed pass).
            PermissionSeeder::class,
            SuperAdminSeeder::class,
            TimezoneSeeder::class,
            SystemSettingSeeder::class,
            UserSeeder::class,
        ]);
    }
}
