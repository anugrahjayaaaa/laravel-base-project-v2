<?php

namespace Tests\Feature\Role;

use App\Actions\V1\Role\RoleDeleteAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Actions\V1\Role\RoleCreateAction;
use App\Actions\V1\Role\RoleUpdateAction;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Role writes over HTTP (Phase 6, Group C).
 *
 * Two things here are regression tests rather than coverage of intent:
 *
 * 1. The permission ids arrive from checkbox values, so they are STRINGS. Spatie's
 *    syncPermissions() resolves a non-int value through findByName() and throws
 *    PermissionDoesNotExist looking for a permission named "19". Without the
 *    intval cast in SaveRoleAction the create form throws a 500 on a page that
 *    looks completely correct.
 * 2. The unique rule must be scoped to the resolved guard. Spatie keys a role by
 *    (name, guard_name), so an unscoped unique:roles,name rejects a perfectly good
 *    name because some other guard already holds it — see RoleGuardTest.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // App\Http\Middleware\VerifyCsrfToken::runningUnitTests() is hardcoded
        // false, so Laravel's test-time CSRF skip never applies and every POST
        // comes back 419. Same opt-out the other feature tests use.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        // The seeded `admin` role holds the whole catalogue (PermissionSeeder), so
        // it is the account that exercises roles.create / .update / .delete.
        $this->admin->assignRole(RoleLookup::find('admin'));
    }

    public function test_a_user_with_no_permissions_cannot_write_roles(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        $role = RoleLookup::find('admin');

        $this->actingAs($nobody)->post(route('roles.store'), ['name' => 'sneaky'])->assertForbidden();
        $this->actingAs($nobody)->put(route('roles.update', $role), ['name' => 'sneaky'])->assertForbidden();
        $this->actingAs($nobody)->delete(route('roles.destroy', $role))->assertForbidden();

        $this->assertFalse(Role::where('name', 'sneaky')->exists(), 'a refused write must not persist');
        $this->assertSame(3, Role::count(), 'the seeded roles must be untouched');
    }

    public function test_it_creates_a_role_and_syncs_permission_ids_posted_as_strings(): void
    {
        $ids = Permission::query()->limit(2)->pluck('id')->all();

        $response = $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => 'Support Agent',
                // Exactly what the checkbox form sends.
                'permissions' => array_map('strval', $ids),
            ]);

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHasNoErrors();

        $role = RoleLookup::find('Support Agent');

        $this->assertNotNull($role);
        $this->assertSame(RoleLookup::guard(), $role->guard_name);
        $this->assertEqualsCanonicalizing($ids, $role->permissions->pluck('id')->all());
    }

    public function test_a_role_name_taken_on_another_guard_is_still_available(): void
    {
        Role::create(['name' => 'Support Agent', 'guard_name' => 'api']);

        $this->actingAs($this->admin)
            ->post(route('roles.store'), ['name' => 'Support Agent'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(RoleLookup::find('Support Agent'));
    }

    public function test_a_duplicate_name_on_the_same_guard_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('roles.store'), ['name' => 'admin'])
            ->assertSessionHasErrors('name');
    }

    public function test_it_updates_permissions_and_keeps_the_role_out_of_the_unique_check(): void
    {
        // `ignore($this->role)` is what makes this pass: the name field is
        // resubmitted unchanged, so a rule that forgot to ignore the role would
        // reject every save of every role.
        //
        // Deliberately an ORDINARY role. This used to edit `admin`, which is
        // legal under no circumstances now — a system role's permissions are
        // code-defined and P6-E3 refuses the payload (see
        // test_a_system_role_permissions_cannot_be_edited_below). The purpose
        // here is the unique-rule ignore, which has nothing to do with system
        // roles, so it is asserted against a role the edit may touch.
        $role = Role::create([
            'name' => 'Support Agent',
            'guard_name' => RoleLookup::guard(),
        ]);
        $keep = Permission::query()->first();
        $drop = Permission::query()->skip(1)->first();

        $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => 'Support Agent',
                'permissions' => [(string) $keep->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([$keep->id], $role->fresh()->permissions->pluck('id')->all());
        $this->assertFalse($role->fresh()->permissions->contains('id', $drop->id));
    }

    /**
     * P6-E3: the role matrix is one POST away from emptying a system role.
     *
     * Submit no checkboxes and `syncPermissions([])` strips every permission
     * from the row — silently, with an audit row recording a successful save.
     * For `superadmin` that is the whole admin panel, gone by one form post.
     */
    #[DataProvider('systemRoleProvider')]
    public function test_a_system_role_permissions_cannot_be_edited(string $name): void
    {
        $role = RoleLookup::find($name);
        $before = $role->fresh()->permissions->pluck('id')->all();

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->put(route('roles.update', $role), [
                'name' => $name,
                // An empty matrix: the exact payload that strips the role.
                'permissions' => [],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame(
            $before,
            $role->fresh()->permissions->pluck('id')->all(),
            "the {$name} permission set was modified by a refused request"
        );
    }

    /**
     * A partial payload counts too — the guard is about the ROLE being a system
     * one, not about how much of it the caller is trying to change.
     */
    public function test_a_system_role_cannot_have_one_permission_swapped(): void
    {
        $role = RoleLookup::find(SystemRole::ADMIN);
        $before = $role->fresh()->permissions->pluck('id')->all();
        $swap = Permission::query()->first();

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->put(route('roles.update', $role), [
                'name' => SystemRole::ADMIN,
                'permissions' => [(string) $swap->id],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame($before, $role->fresh()->permissions->pluck('id')->all());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function systemRoleProvider(): array
    {
        return [
            'superadmin' => [SystemRole::SUPERADMIN],
            'admin' => [SystemRole::ADMIN],
            'user' => [SystemRole::USER],
        ];
    }

    public function test_a_system_role_cannot_be_renamed(): void
    {
        $role = RoleLookup::find('admin');

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->put(route('roles.update', $role), ['name' => 'renamed-admin'])
            ->assertSessionHasErrors('name');

        $this->assertNotNull(RoleLookup::find('admin'), 'the system role must survive the attempt');
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $role = RoleLookup::find('admin');

        $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $role))
            ->assertSessionHasErrors('name');

        $this->assertNotNull(RoleLookup::find('admin'));
    }

    public function test_the_action_still_refuses_a_populated_role_without_force(): void
    {
        // The guard now lives where it always mattered — the ACTION. The web
        // route passes force because the confirm modal in front of it is the
        // deliberate override (see RoleController::destroy). This test is the
        // remaining reason the guard exists: the API and the console call the
        // action with no modal in front of them, and a stray call there must not
        // silently strip access from every holder.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $user = User::factory()->create();
        $user->assignRole($role);

        try {
            app(RoleDeleteAction::class)->run($role, $this->admin);
            $this->fail('the action must refuse a populated role when force is not set');
        } catch (ValidationException $e) {
            $this->assertSame('This role is still assigned to 1 user(s). Trashing it removes the role from all of them.', $e->errors()['name'][0]);
        }

        $this->assertNotNull(RoleLookup::find('Support Agent'));
        $this->assertTrue($user->fresh()->hasRole('Support Agent'), 'a refused delete must not detach');
    }

    public function test_force_deletes_a_role_that_still_has_users(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $user = User::factory()->create();
        $user->assignRole($role);

        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
        $this->assertFalse($user->fresh()->hasRole('Support Agent'), 'force trashing still revokes');
    }

    public function test_it_deletes_an_unused_role_and_audits_the_change(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);

        $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertSoftDeleted('roles', ['id' => $role->id]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Role::class,
            'event' => 'role.deleted',
        ]);
    }

    // -----------------------------------------------------------------------
    // Soft delete + revocation
    //
    // The whole point of trashing a role rather than deleting it: the row comes
    // back, but the access does not. Every one of these tests fails against a
    // plain `$role->delete()` that relies on Spatie to clean up, because Spatie
    // deliberately skips detach on a non-force delete.
    // -----------------------------------------------------------------------

    public function test_trashing_a_role_revokes_it_from_every_user_holding_it(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $users = User::factory(3)->create(['is_active' => true]);
        foreach ($users as $user) {
            $user->assignRole($role);
        }
        $permission = Permission::query()->first();
        $role->syncPermissions([$permission->id]);

        $this->assertTrue($users->first()->fresh()->can($permission->name), 'precondition');

        // Straight to the forced call: the refusal half of this used to be
        // asserted through the web route, but the web route now forces because
        // the confirm modal is the deliberate override. The guard itself is
        // covered by test_the_action_still_refuses_a_populated_role_without_force.
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        foreach ($users as $user) {
            $fresh = $user->fresh();
            $this->assertFalse($fresh->hasRole('Support Agent'), 'the assignment must be gone, not just hidden');
            $this->assertFalse(
                $fresh->can($permission->name),
                'a revoked user must not keep the permission the role carried'
            );
        }

        $this->assertSame(
            0,
            DB::table('model_has_roles')->where('role_id', $role->id)->count(),
            'the pivot rows themselves must be deleted, or a restore would silently re-grant'
        );
    }

    public function test_trashing_a_role_records_how_many_users_it_revoked(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        User::factory(2)->create()->each(fn (User $user) => $user->assignRole($role));

        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $log = DB::table('activity_log')->where('event', 'role.deleted')->first();

        $this->assertNotNull($log);
        $properties = json_decode($log->properties, true);
        $this->assertSame(2, $properties['revoked_users']);
    }

    public function test_a_trashed_role_grants_nothing_even_while_the_pivot_row_remains(): void
    {
        // The global SoftDeletes scope is the read-side half of the revocation.
        // Proven separately from the detach so a future refactor cannot trade one
        // mechanism for the other and leave the other half broken.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $permission = Permission::query()->first();
        $role->syncPermissions([$permission->id]);

        DB::table('roles')->where('id', $role->id)->update(['deleted_at' => now()]);

        $this->assertTrue(
            DB::table('model_has_roles')->where('role_id', $role->id)->exists(),
            'precondition: the pivot row is still there'
        );
        $this->assertFalse($user->fresh()->hasRole('Support Agent'));
        $this->assertFalse($user->fresh()->can($permission->name));
    }

    public function test_restoring_a_role_brings_back_its_permissions_but_not_its_users(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $permission = Permission::query()->first();
        $role->syncPermissions([$permission->id]);

        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->actingAs($this->admin)
            ->post(route('roles.restore', $role->id))
            ->assertRedirect(route('roles.index'));

        $this->assertNotSoftDeleted('roles', ['id' => $role->id]);
        $this->assertEqualsCanonicalizing(
            [$permission->id],
            $role->fresh()->permissions->pluck('id')->all(),
            'the definition comes back intact'
        );
        $this->assertFalse(
            $user->fresh()->hasRole('Support Agent'),
            'a restore must not silently re-assign the role to everyone who had it'
        );

        // ...and it still works once somebody assigns it on purpose.
        $user->fresh()->assignRole($role->fresh());
        $this->assertTrue($user->fresh()->can($permission->name));
    }

    public function test_a_trashed_role_disappears_from_the_pickers(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->assertNull(RoleLookup::find('Support Agent'));
        $this->assertFalse(
            RoleLookup::assignable()->contains('name', 'Support Agent'),
            'a trashed role must not be assignable from a user form'
        );
    }

    public function test_a_trashed_role_name_stays_reserved(): void
    {
        // The soft-deleted row still occupies the (name, guard_name) unique
        // index, which is what makes a restore collision impossible rather than
        // merely unlikely. Asserted so nobody "helpfully" adds whereNull to the
        // unique rule and opens the hole this design depends on.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->actingAs($this->admin)
            ->post(route('roles.store'), ['name' => 'Support Agent'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_trash_tab_lists_trashed_roles_and_the_live_tab_does_not(): void
    {
        $live = Role::create(['name' => 'Live Agent', 'guard_name' => RoleLookup::guard()]);
        $gone = Role::create(['name' => 'Retired Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($gone, $this->admin, force: true);

        $indexed = $this->actingAs($this->admin)->get(route('roles.index'))->viewData('roles')->pluck('name');
        $this->assertTrue($indexed->contains('Live Agent'));
        $this->assertFalse($indexed->contains('Retired Agent'), 'a trashed role must not appear on the live tab');

        $trash = $this->actingAs($this->admin)
            ->get(route('roles.index', ['trashed' => 1]))
            ->viewData('roles')
            ->pluck('name');

        $this->assertSame(['Retired Agent'], $trash->all());
        $this->assertFalse($trash->contains($live->name));
    }

    public function test_a_trashed_role_cannot_be_edited(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->actingAs($this->admin)
            ->get(route('roles.edit', $role->id))
            ->assertNotFound();
    }

    public function test_force_deleting_a_live_role_is_refused(): void
    {
        // Otherwise the permanent step bypasses the whole point of the trash:
        // one call, no deleted_at marker, no revocation recorded.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);

        $this->actingAs($this->admin)
            ->delete(route('roles.force-delete', $role->id))
            ->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);
    }

    public function test_it_permanently_deletes_a_trashed_role(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $permission = Permission::query()->first();
        $role->syncPermissions([$permission->id]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $this->actingAs($this->admin)
            ->delete(route('roles.force-delete', $role->id))
            ->assertRedirect(route('roles.index'));

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Role::class,
            'event' => 'role.force_deleted',
        ]);
    }

    public function test_a_trashed_system_role_cannot_be_force_deleted(): void
    {
        // The `user` role, not `admin`: trashing the role this test's own actor
        // holds would strip the actor of the permission the endpoint checks, and
        // the request would 403 at the FormRequest before ever reaching the
        // system-role refusal this test is about.
        $system = RoleLookup::find('user');
        DB::table('roles')->where('id', $system->id)->update(['deleted_at' => now()]);

        $this->actingAs($this->admin)
            ->delete(route('roles.force-delete', $system->id))
            ->assertSessionHasErrors('name');

        $this->assertSoftDeleted('roles', ['id' => $system->id]);
    }

    public function test_restore_and_force_delete_require_their_own_permissions(): void
    {
        // Deliberately NOT folded into roles.update / roles.delete. A restore
        // brings back a whole permission set, and a force delete destroys the
        // audit subject — neither is "editing a role".
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $limited = User::factory()->create(['is_active' => true]);
        $limited->assignRole(RoleLookup::find('user'));
        $limited->givePermissionTo('roles.update', 'roles.delete');

        $this->actingAs($limited)
            ->post(route('roles.restore', $role->id))
            ->assertForbidden();

        $this->actingAs($limited)
            ->delete(route('roles.force-delete', $role->id))
            ->assertForbidden();

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
        $this->assertTrue(
            DB::table('activity_log')->where('event', 'role.force_deleted')->doesntExist(),
            'a refused write must not persist'
        );
    }

    public function test_a_user_with_no_permissions_cannot_reach_the_trash_endpoints(): void
    {
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        app(RoleDeleteAction::class)->run($role, $this->admin, force: true);

        $nobody = User::factory()->create(['is_active' => true]);

        // 403, not a redirect carrying the action's own refusal message — that
        // message would confirm to an unauthorized caller that the role exists.
        $this->actingAs($nobody)->post(route('roles.restore', $role->id))->assertForbidden();
        $this->actingAs($nobody)->delete(route('roles.force-delete', $role->id))->assertForbidden();
    }

    public function test_the_create_audit_row_is_written_inside_the_transaction(): void
    {
        // DEP-003: an audit row that survives a rollback records a save that
        // never happened. Forcing the failure AFTER the action would have
        // written proves the rollback takes the log with it.
        $this->expectException(RuntimeException::class);

        try {
            DB::transaction(function (): void {
                app(RoleCreateAction::class)->run(['name' => 'Support Agent'], $this->admin);

                throw new RuntimeException('rolled back after the write');
            });
        } finally {
            $this->assertFalse(
                DB::table('activity_log')->where('event', 'role.created')->exists(),
                'the audit row must roll back with the transaction'
            );
            $this->assertFalse(Role::where('name', 'Support Agent')->exists());
        }
    }

    public function test_the_update_audit_row_is_written_inside_the_transaction(): void
    {
        // Both verbs write the log the same way (PersistsRole). Checking only
        // create would let the shared trait grow a create-only audit and the
        // update path would keep passing.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);

        $this->expectException(RuntimeException::class);

        try {
            DB::transaction(function () use ($role): void {
                app(RoleUpdateAction::class)->run($role, [
                    'name' => 'Support Agent Renamed',
                    'permissions' => [],
                ], $this->admin);

                throw new RuntimeException('rolled back after the write');
            });
        } finally {
            $this->assertFalse(
                DB::table('activity_log')->where('event', 'role.updated')->exists(),
                'the audit row must roll back with the transaction'
            );
            $this->assertNotNull(RoleLookup::find('Support Agent'), 'the rename must roll back too');
        }
    }

    public function test_it_searches_and_paginates_without_n_plus_one(): void
    {
        foreach (range(1, 15) as $n) {
            Role::create(['name' => "Agent {$n}", 'guard_name' => RoleLookup::guard()]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $page = $this->actingAs($this->admin)->get(route('roles.index', ['search' => 'Agent']));
        $page->assertOk();
        $page->assertSee('Agent 1');

        // auth, session, permission + one roles query + the two withCount
        // subqueries. The point is that it does not grow with the row count.
        $this->assertLessThan(20, $queries, "N+1: {$queries} queries for one page of roles");
    }
}
