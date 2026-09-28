<?php

namespace Database\Seeders;

use App\Models\RoleLookup;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission catalogue and the role permission matrix.
 *
 * Runs after RoleSeeder and before SuperAdminSeeder: roles must exist before
 * permissions can be attached to them, and the superadmin user must exist for
 * the role assignment to be meaningful in the same pass.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Which roles hold which permissions.
     *
     * `superadmin` is deliberately absent. Its access comes from the
     * `Gate::before` rule in App\Providers\AuthServiceProvider, not from rows in
     * role_has_permissions — so adding a permission to the catalogue never
     * requires re-seeding it, and a superadmin can never be silently stripped
     * of a capability by editing a role's permission set.
     *
     * `user` is intentionally empty. Profile, password change, sessions and
     * logout are self-actions authorized by `$request->user()->id ===
     * $target->id`, not by a permission. Giving this role a permission to edit
     * its own profile would misdescribe the model — and a user with zero
     * permissions still reaches /dashboard, /profile and /sessions.
     *
     * A method, not a const: a class constant cannot call
     * PermissionCatalog::forResource(), and hardcoding the `users.*` list here
     * would be the third copy of it.
     *
     * @return array<string, array<int, string>>
     */
    private function matrix(): array
    {
        return [
            // admin: the whole catalogue. It is the delegated superadmin — the
            // account you hand someone when you do not want to hand them the
            // superadmin account — so it is granted the entire catalogue rather
            // than a hand-picked subset. A hand-picked list is a second copy of
            // PermissionCatalog that silently rots: every permission added later
            // would need remembering in two places, and forgetting is invisible.
            SystemRole::ADMIN => PermissionCatalog::all(),
            SystemRole::USER => [],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // BEFORE seeding: the registrar may already hold a cached permissions
        // collection from a previous run, and writing rows the cache does not
        // know about is how a second, real role of the same name appears.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = RoleLookup::guard();

        // Prune permissions the catalogue no longer declares. Without this a
        // permission removed from PermissionCatalog stays in the database
        // forever: it keeps showing up in the permissions UI, and any role still
        // holding it keeps passing can() for something nothing checks. Only
        // rows on our own guard are touched, and role_has_permissions cascades.
        $orphans = Permission::where('guard_name', $guard)
            ->whereNotIn('name', PermissionCatalog::all())
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->delete();
        }

        foreach (PermissionCatalog::all() as $name) {
            // firstOrCreate, not create: re-seeding must not violate the
            // unique (name, guard_name) index or duplicate the catalogue.
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        // AFTER creating, BEFORE assigning: the registrar caches permission ids
        // and their role mapping, and syncPermissions reads that cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->matrix() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            // syncPermissions, not givePermissionTo: a re-seed must also REMOVE
            // a permission this role no longer has, otherwise a role retired
            // from the matrix keeps granting it forever.
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
