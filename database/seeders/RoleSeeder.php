<?php

namespace Database\Seeders;

use App\Models\RoleLookup;
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
        //
        // Seeded on the guard the app actually assigns roles on, which is not
        // simply the configured default — Spatie intersects that default with
        // the guards a User can authenticate under. A hardcoded 'api' here
        // produced rows no permission check ever read, and then assignRole()
        // silently created a second, real row of the same name, leaving the
        // role pickers showing `superadmin` twice.
        //
        // firstOrCreate, not create: re-seeding must not duplicate a role.
        $guard = RoleLookup::guard();

        foreach (['superadmin', 'admin', 'user'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }
}
