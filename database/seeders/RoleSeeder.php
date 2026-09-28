<?php

namespace Database\Seeders;

use App\Models\RoleLookup;
use App\Support\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // System roles, seeded with no permissions here. PermissionSeeder runs
        // immediately after this and owns the role permission matrix (RBAC-004);
        // superadmin gets no rows at all and its access comes from Gate::before.
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

        // SystemRole, not a literal here: App\Support\SystemRole is the one
        // answer to "is this a system role?", shared with the roles UI and the
        // protection rules. A second copy of the list is how a fourth name gets
        // added in one place and not the others.
        foreach (SystemRole::names() as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }
}
