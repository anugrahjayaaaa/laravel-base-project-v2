<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // System roles,seeded with no permissions yet; permissions are
        // assigned in RBAC-004 (Phase 6) once the permission set is defined.
        // See docs/base/features/roles-permissions.md §Seeded Roles.
        Role::create(['name' => 'superadmin', 'guard_name' => 'api']);
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
        Role::create(['name' => 'user', 'guard_name' => 'api']);
    }
}
