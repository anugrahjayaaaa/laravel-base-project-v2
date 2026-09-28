<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 Group B gate: the permission catalogue is seeded, idempotent, and
 * the role matrix is what PermissionCatalog says it is.
 *
 * The catalogue is the contract every `can()` call in the app is written
 * against, so the failure worth testing is not "the seeder ran" but "a
 * permission the code checks is missing from the database" — that is why most
 * of these assert against PermissionCatalog rather than a hardcoded list.
 */
class PermissionSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RoleSeeder first, exactly as DatabaseSeeder orders them: PermissionSeeder
        // attaches to roles, and `superadmin` is created by RoleSeeder — seeding
        // it alone would leave assignRole('superadmin') throwing RoleDoesNotExist
        // and every superadmin assertion here failing for the wrong reason.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    #[Test]
    public function every_catalog_permission_exists_on_the_resolved_guard(): void
    {
        $names = Permission::where('guard_name', RoleLookup::guard())
            ->pluck('name')
            ->all();

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertContains($permission, $names, "Seeded permissions are missing [{$permission}].");
        }
    }

    #[Test]
    public function every_permission_maps_to_a_resource_the_app_actually_has(): void
    {
        // A permission with no route, controller or view grants nothing and
        // still shows up in the permissions UI as though it means something.
        // This is the guard that kept `audit.*` (Phase 10) and `features.*`
        // (Phase 7) out of the catalogue while neither exists.
        $this->assertEqualsCanonicalizing(
            ['users', 'roles', 'permissions', 'settings'],
            array_keys(PermissionCatalog::grouped()),
            'The catalogue has a group with no feature behind it.'
        );
    }

    #[Test]
    public function admin_holds_the_entire_catalogue(): void
    {
        $user = User::factory()->create();
        $user->assignRole(SystemRole::ADMIN);

        // Not a subset: admin is the delegated superadmin, so a permission added
        // to the catalogue is granted to it without a second edit here.
        foreach (PermissionCatalog::all() as $permission) {
            $this->assertTrue($user->can($permission), "admin should hold [{$permission}].");
        }

        $role = Role::where('name', SystemRole::ADMIN)->firstOrFail();
        $this->assertSame(
            count(PermissionCatalog::all()),
            $role->permissions()->count(),
            'admin is missing catalogue entries, or holds rows outside the catalogue.'
        );
    }

    #[Test]
    public function a_permission_dropped_from_the_catalogue_is_pruned_on_reseed(): void
    {
        // A permission removed from PermissionCatalog has no route and no
        // can() call behind it any more. left in the database it would keep
        // appearing in the permissions UI and keep granting to whoever holds it.
        $stale = Permission::create([
            'name' => 'features.view',
            'guard_name' => RoleLookup::guard(),
        ]);

        $admin = Role::where('name', SystemRole::ADMIN)->firstOrFail();
        $admin->givePermissionTo($stale);

        $this->seed(PermissionSeeder::class);

        $this->assertNull(
            Permission::where('name', 'features.view')->first(),
            'A permission the catalogue no longer declares must be pruned.'
        );

        $this->assertSame(
            0,
            DB::table('role_has_permissions')->where('permission_id', $stale->id)->count(),
            'Pruning a permission must cascade to role_has_permissions.'
        );
    }

    #[Test]
    public function pruning_does_not_touch_another_guard(): void
    {
        // The prune is scoped to RoleLookup::guard(). A second guard (an API
        // guard, say) owns its own permissions and this seeder must not touch
        // them, or it would delete another guard's whole catalogue.
        $other = Permission::create([
            'name' => 'features.view',
            'guard_name' => 'api',
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertNotNull(
            Permission::where('name', 'features.view')->where('guard_name', 'api')->first(),
            'The seeder pruned a permission belonging to another guard.'
        );
    }

    #[Test]
    public function the_catalogue_has_no_duplicates(): void
    {
        $all = PermissionCatalog::all();

        // A duplicate in the list would be seeded twice and quietly double every
        // badge count in the permissions UI.
        $this->assertSame(
            array_values(array_unique($all)),
            $all,
            'PermissionCatalog::all() contains a duplicate name.'
        );
    }

    #[Test]
    public function grouped_catalogue_loses_nothing(): void
    {
        $flattened = [];

        foreach (PermissionCatalog::grouped() as $group => $permissions) {
            $this->assertNotEmpty($permissions, "Group [{$group}] is empty.");

            foreach ($permissions as $permission) {
                $this->assertStringStartsWith($group.'.', $permission);
                $flattened[] = $permission;
            }
        }

        // The matrix renders one heading per group and reads grouped(), so a
        // permission missing from all() is invisible in the UI.
        $this->assertEqualsCanonicalizing(PermissionCatalog::all(), $flattened);
    }

    #[Test]
    public function superadmin_has_no_permission_rows_but_passes_every_check(): void
    {
        $role = Role::where('name', SystemRole::SUPERADMIN)
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();

        $this->assertSame(0, $role->permissions()->count(), 'superadmin must hold no role_has_permissions rows.');

        $user = User::factory()->create();
        $user->assignRole(SystemRole::SUPERADMIN);

        // Arbitrary and unseeded on purpose: Gate::before grants by role, so the
        // answer must not depend on the catalogue at all.
        $this->assertTrue($user->can('users.view'));
        $this->assertTrue($user->can('settings.manage'));
        $this->assertTrue($user->can('a.permission.that.does.not.exist'));
    }

    #[Test]
    public function superadmin_permission_rows_stay_empty_after_a_check(): void
    {
        $user = User::factory()->create();
        $user->assignRole(SystemRole::SUPERADMIN);
        $user->can('users.view');

        $role = Role::where('name', SystemRole::SUPERADMIN)->firstOrFail();
        $this->assertSame(0, $role->permissions()->count());
    }

    #[Test]
    public function admin_holds_every_users_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(SystemRole::ADMIN);

        foreach (PermissionCatalog::forResource('users') as $permission) {
            $this->assertTrue(
                $user->can($permission),
                "admin should be able to [{$permission}]."
            );
        }
    }

    #[Test]
    public function user_role_holds_no_permissions(): void
    {
        $role = Role::where('name', SystemRole::USER)
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();

        $this->assertSame(0, $role->permissions()->count());

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);

        $this->assertFalse($user->can('users.view'));
        $this->assertFalse($user->can('settings.manage'));
    }

    #[Test]
    public function no_permission_is_granted_directly_to_a_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole(SystemRole::ADMIN);

        // Direct grants bypass the role, so removing a role would not revoke
        // them — the exact drift PolicyKeyTest was written about, in the
        // opposite direction.
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $user->id)->count());
    }

    #[Test]
    public function reseeding_permission_seeder_changes_no_record_counts(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'roles' => Role::count(),
            'role_has_permissions' => DB::table('role_has_permissions')->count(),
        ];

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $after = [
            'permissions' => Permission::count(),
            'roles' => Role::count(),
            'role_has_permissions' => DB::table('role_has_permissions')->count(),
        ];

        $this->assertSame($before, $after, 'PermissionSeeder is not idempotent.');
    }

    #[Test]
    public function reseeding_does_not_leave_a_duplicate_role_or_permission(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        foreach (SystemRole::names() as $name) {
            $this->assertSame(
                1,
                Role::where('name', $name)->where('guard_name', RoleLookup::guard())->count(),
                "Role [{$name}] was duplicated on the guard."
            );
        }

        foreach (PermissionCatalog::all() as $name) {
            $this->assertSame(
                1,
                Permission::where('name', $name)->where('guard_name', RoleLookup::guard())->count(),
                "Permission [{$name}] was duplicated on the guard."
            );
        }
    }

    #[Test]
    public function reseeding_revokes_a_permission_dropped_from_the_matrix(): void
    {
        // syncPermissions, not givePermissionTo: a role retired from the matrix
        // must stop granting it, or a permission deleted from the catalogue
        // keeps working for everyone holding the role.
        $admin = Role::where('name', SystemRole::ADMIN)->firstOrFail();
        $this->assertTrue(
            $admin->permissions()->where('name', 'users.force_delete')->exists(),
            'Precondition: admin starts with the permission the matrix declares.'
        );

        DB::table('role_has_permissions')
            ->where('role_id', $admin->id)
            ->whereIn('permission_id', function ($query) {
                $query->select('id')->from('permissions')->where('name', 'users.force_delete');
            })
            ->delete();

        $this->seed(PermissionSeeder::class);

        $this->assertTrue(
            $admin->permissions()->where('name', 'users.force_delete')->exists(),
            'Re-seeding should restore a permission the matrix still declares.'
        );
    }

    #[Test]
    public function system_role_names_are_the_only_seeded_roles(): void
    {
        $names = Role::where('guard_name', RoleLookup::guard())->pluck('name')->all();

        $this->assertEqualsCanonicalizing(SystemRole::names(), $names);
    }

    #[Test]
    public function system_role_predicate_covers_every_seeded_role(): void
    {
        foreach (SystemRole::names() as $name) {
            $this->assertTrue(SystemRole::isSystem($name));
        }

        $this->assertFalse(SystemRole::isSystem('manager'));
        $this->assertFalse(SystemRole::isSystem(''));
    }
}
