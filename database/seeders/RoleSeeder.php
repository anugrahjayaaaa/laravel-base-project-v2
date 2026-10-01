<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Support\SystemRole;
use Illuminate\Database\Seeder;

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
        //
        // restore() before firstOrCreate: a system role can never be trashed
        // (RoleDeleteAction refuses), so this only fires on a row that was
        // trashed by hand, a seeder edit, or a restored database dump. Without
        // it, firstOrCreate would skip the trashed row and then blow up on the
        // unique index — a reseed that cannot repair its own state.
        foreach (SystemRole::names() as $name) {
            Role::withTrashed()
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->first()?->restore();

            Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }
}
