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
            // After PermissionSeeder: the flags page is gated on
            // features.view / features.manage, and a flag with no store row
            // reads as OFF — so seeding them before anything can reach the
            // page avoids a window where every module looks disabled.
            FeatureFlagSeeder::class,
            UserSeeder::class,
        ]);
    }
}
