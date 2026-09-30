<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API half of Phase 6: role management and the permission catalogue.
 *
 * Until now these existed only in the browser — a token could administer users
 * but not the roles those users hold. The point of these tests is not that the
 * endpoints exist; it is that closing the gap did NOT open a second, weaker set
 * of rules. Every authorization case is the web case, replayed over HTTP:
 * the same actions and the same Form Requests do the work, so a rule restated
 * in a controller is the failure mode being guarded against.
 */
class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);

        // Every test starts as the superadmin; the gating tests re-actor below.
        Sanctum::actingAs($this->superadmin);
    }


    private function role(string $name): AppRole
    {
        return AppRole::where('name', $name)->firstOrFail();
    }

    /**
     * Grant an ordinary role a permission set and return a fresh user wearing it.
     *
     * The registrar cache is flushed here because these grants happen MID-TEST,
     * after the user has already been resolved once: Spatie caches the
     * permission map per request, so without the flush `can()` keeps answering
     * from the pre-grant state and the route gate 403s for a caller who in fact
     * holds the permission. That failure mode is indistinguishable from a broken
     * gate, which is why it is centralised here rather than repeated inline.
     *
     * Always an ORDINARY role: a system role's permission set is code-defined
     * (P6-E3) and PersistsRole refuses the write.
     */
    private function userWith(string $roleName, array $permissions): User
    {
        // Order matters: the role must exist and carry its permissions BEFORE
        // assignRole, because Spatie snapshots the permission map on the first
        // permission check and the grant has to be in place before then.
        $role = AppRole::create(['name' => $roleName, 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();

        // Asserted AFTER the grant, and via the pivot rather than
        // hasAnyRole(), so the check cannot prime the permission cache before
        // the grant it is meant to confirm.
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('role_id', $role->id)
                ->exists(),
            'fixture: the role was not attached'
        );

        return $user;
    }

    // -----------------------------------------------------------------------
    // Roles — reads
    // -----------------------------------------------------------------------

    public function test_the_role_listing_is_gated(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->getJson(route('api.v1.roles.index'))->assertForbidden();
    }

    public function test_it_lists_roles_with_their_counts(): void
    {
        $response = $this->getJson(route('api.v1.roles.index'))->assertOk();

        $names = collect($response->json('data.roles'))->pluck('name');
        $this->assertContains(SystemRole::SUPERADMIN, $names);

        // The count columns the web table shows, so a client does not have to
        // guess whether a role with no members is zero or unloaded. The
        // permission aggregate is `permissions_count` — IndexRoleAction counts
        // the permissions relation, not the roles.
        $superadminRow = collect($response->json('data.roles'))->firstWhere('name', SystemRole::SUPERADMIN);
        $this->assertSame(1, $superadminRow['users_count']);
        $this->assertArrayHasKey('permissions_count', $superadminRow);
        $this->assertArrayHasKey('is_system', $superadminRow);
    }

    public function test_it_shows_one_role_with_its_permission_names(): void
    {
        $response = $this->getJson(route('api.v1.roles.show', $this->role('admin')))->assertOk();

        $this->assertSame('admin', $response->json('data.role.name'));
        $this->assertTrue($response->json('data.role.is_system'));
        // Names, not ids: a client that just read this can send it straight back.
        $permissions = $response->json('data.role.permissions');
        $this->assertIsArray($permissions);
        $this->assertContains('users.view', $permissions);
    }

    public function test_a_non_superadmin_cannot_read_the_superadmin_role_by_id(): void
    {
        // The listing hides it, so a guessable id must not be a way around that.
        // This is the case a show() endpoint introduces and the index does not
        // have — the reason it needs its own assertion.
        $delegated = $this->userWith('role-reader', ['roles.view']);

        // The fixture itself, so a green run cannot be explained by a grant
        // that quietly did not take.
        $this->assertTrue($delegated->can('roles.view'), 'fixture: no roles.view');

        Sanctum::actingAs($delegated);

        $this->getJson(route('api.v1.roles.index'))
            ->assertOk()
            ->assertJsonMissing(['name' => SystemRole::SUPERADMIN]);

        $this->getJson(route('api.v1.roles.show', $this->role(SystemRole::SUPERADMIN)))
            ->assertForbidden();

        // A role they ARE allowed to see still resolves, or the refusal above
        // would pass on a blanket 404.
        $this->getJson(route('api.v1.roles.show', $this->role('admin')))->assertOk();
        $this->getJson(route('api.v1.roles.show', $this->role('role-reader')))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Roles — writes, and the guards that must survive the new surface
    // -----------------------------------------------------------------------

    public function test_it_creates_a_role(): void
    {
        $permission = \Spatie\Permission\Models\Permission::findByName('users.view', RoleLookup::guard());

        $response = $this->postJson(route('api.v1.roles.store'), [
            'name' => 'api-made',
            'permissions' => [$permission->id],
        ])->assertCreated();

        $this->assertSame('api-made', $response->json('data.role.name'));
        $this->assertDatabaseHas('roles', ['name' => 'api-made']);

        $this->assertTrue(
            $this->role('api-made')->hasPermissionTo('users.view'),
            'the permission was not attached'
        );
    }

    public function test_creating_a_role_is_gated(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->postJson(route('api.v1.roles.store'), ['name' => 'nope'])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'nope']);
    }

    /**
     * P6-E3 over the new surface. The web matrix is where this was found; the
     * point of the assertion here is that adding an API did not create a way to
     * strip a system role that the UI refuses.
     */
    public function test_the_api_cannot_strip_a_system_roles_permissions(): void
    {
        $admin = $this->role('admin');
        $before = $admin->permissions()->count();
        $this->assertGreaterThan(0, $before, 'fixture: admin should hold the catalogue');

        $this->putJson(route('api.v1.roles.update', $admin), [
            'name' => $admin->name,
            'permissions' => [],
        ])->assertStatus(422);

        $this->assertSame(
            $before,
            $admin->fresh()->permissions()->count(),
            'the refused API write emptied the role anyway'
        );
    }

    /**
     * And the rename guard, same reason.
     */
    public function test_the_api_cannot_rename_a_system_role(): void
    {
        $user = $this->role('user');

        $this->putJson(route('api.v1.roles.update', $user), ['name' => 'renamed'])
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['name' => 'user']);
        $this->assertDatabaseMissing('roles', ['name' => 'renamed']);
    }

    public function test_the_api_cannot_delete_a_system_role(): void
    {
        $this->deleteJson(route('api.v1.roles.destroy', $this->role('admin')))
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['name' => 'admin', 'deleted_at' => null]);
    }

    public function test_it_updates_a_ordinary_role(): void
    {
        $role = AppRole::create(['name' => 'temp', 'guard_name' => RoleLookup::guard()]);

        $this->putJson(route('api.v1.roles.update', $role), ['name' => 'renamed-temp'])
            ->assertOk();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'renamed-temp']);
    }

    public function test_updating_a_role_is_gated(): void
    {
        $role = AppRole::create(['name' => 'temp2', 'guard_name' => RoleLookup::guard()]);

        Sanctum::actingAs($this->plainUser());

        $this->putJson(route('api.v1.roles.update', $role), ['name' => 'nope'])->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'temp2']);
    }

    public function test_it_trashes_and_restores_a_role(): void
    {
        $role = AppRole::create(['name' => 'trashable', 'guard_name' => RoleLookup::guard()]);

        $this->deleteJson(route('api.v1.roles.destroy', $role))->assertOk();
        $this->assertSoftDeleted('roles', ['id' => $role->id]);

        $this->postJson(route('api.v1.roles.restore', $role->id))->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);
    }

    /**
     * The trashed tab is part of the listing, so the query flag has to work
     * over the API too — otherwise a client cannot see what it just deleted.
     */
    public function test_the_listing_can_scope_to_the_trash(): void
    {
        $role = AppRole::create(['name' => 'gone', 'guard_name' => RoleLookup::guard()]);
        $this->deleteJson(route('api.v1.roles.destroy', $role))->assertOk();

        $this->getJson(route('api.v1.roles.index', ['trashed' => 1]))
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'gone');

        $this->getJson(route('api.v1.roles.index'))
            ->assertOk()
            ->assertJsonMissing(['name' => 'gone']);
    }

    // -----------------------------------------------------------------------
    // Permission catalogue
    // -----------------------------------------------------------------------

    public function test_the_catalogue_is_gated(): void
    {
        Sanctum::actingAs($this->plainUser());

        $this->getJson(route('api.v1.permissions.index'))->assertForbidden();
    }

    public function test_it_lists_the_catalogue_with_the_roles_holding_each_permission(): void
    {
        $response = $this->getJson(route('api.v1.permissions.index', ['per_page' => 50]))->assertOk();

        $names = collect($response->json('data.permissions'))->pluck('name');
        $this->assertContains('users.view', $names);

        $row = collect($response->json('data.permissions'))->firstWhere('name', 'users.view');
        $this->assertArrayHasKey('roles_count', $row);
        $this->assertIsArray($row['roles']);
    }

    public function test_the_catalogue_has_no_write_endpoints(): void
    {
        // P6-C7: the catalogue is defined in code. Asserting the absence keeps
        // it a decision rather than an oversight someone later "fixes" by
        // adding a POST.
        foreach (['api.v1.permissions.store', 'api.v1.permissions.update', 'api.v1.permissions.destroy'] as $name) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Route::has($name),
                "{$name} exists; the catalogue is meant to be read-only"
            );
        }
    }

    /**
     * A plain authenticated account with no roles.
     *
     * Deliberately does NOT assert `hasAnyRole()`. That read primes Spatie's
     * permission cache for the request, so a caller which later grants the user
     * a role keeps getting the pre-grant answer from can() — the route gate then
     * 403s a caller who really does hold the permission, which looks exactly
     * like a broken gate. The precondition is asserted in userWith() instead,
     * after the grant is in place.
     */
    private function plainUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
