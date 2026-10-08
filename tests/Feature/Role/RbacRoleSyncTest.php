<?php

namespace Tests\Feature\Role;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * P6-E8 — the role sync contract, end to end over HTTP.
 *
 * `RoleAssignActionTest` covers the action directly. This drives the endpoint,
 * because the interesting failures live in the seams the action cannot see: the
 * `roles` key being absent versus empty, the validation rule on unknown names,
 * and the physical shape of the permission pivot afterwards.
 *
 * ADR-004 is the reason `model_has_permissions` is asserted EMPTY throughout.
 * Permissions are role-derived — a user holds none of their own — so a row
 * appearing in that table means something copied a permission onto the account
 * instead of granting the role that carries it. That is the drift the ADR
 * exists to prevent, and no assertion on `can()` alone would catch it.
 */
class RbacRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // A real superadmin actor: role assignment is refused from anyone
        // outside the superadmin circle, so every refusal below has to come
        // from the rule under test and not from the caller being unable to act.
        $this->actor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->actor->assignRole(SystemRole::SUPERADMIN);

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function makeRole(string $name, array $permissions): AppRole
    {
        $role = AppRole::create([
            'name' => $name,
            'guard_name' => RoleLookup::guard(),
        ]);

        if ($permissions !== []) {
            $role->givePermissionTo(
                Permission::whereIn('name', $permissions)
                    ->where('guard_name', RoleLookup::guard())
                    ->get()
            );
        }

        return $role;
    }

    private function subject(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function update(User $target, array $payload)
    {
        return $this->actingAs($this->actor->fresh(), 'web')
            ->put(route('users.update', $target), array_merge([
                'name' => $target->name,
                'status' => 'active',
            ], $payload));
    }

    /**
     * The brief's first case: `user` becomes `staff`, and the account's
     * abilities change with the role rather than being copied onto it.
     */
    public function test_changing_the_role_changes_what_the_account_can_do(): void
    {
        $staff = $this->makeRole('staff', ['users.view', 'users.update']);

        $target = $this->subject();
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        // Precondition: the starting point is what the brief describes.
        $this->assertFalse($target->fresh()->can('users.view'));

        $this->update($target, ['roles' => ['staff']])->assertRedirect();

        $fresh = $target->fresh();

        $this->assertTrue($fresh->can('users.view'), 'the new role did not grant its permission');
        $this->assertFalse($fresh->can('roles.view'), 'the account gained an unrelated permission');
        $this->assertSame(['staff'], $fresh->getRoleNames()->all());
    }

    /**
     * ADR-004. The permission came from the ROLE, so nothing may be written to
     * the user's own pivot — an empty table is the correct end state, not a
     * missing feature.
     */
    public function test_the_sync_writes_nothing_to_the_user_permission_pivot(): void
    {
        $staff = $this->makeRole('staff', ['users.view', 'users.update']);

        $target = $this->subject();
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        $this->assertSame(0, DB::table('model_has_permissions')->count(), 'fixture: pivot not empty');

        $this->update($target, ['roles' => ['staff']])->assertRedirect();

        $this->assertSame(
            0,
            DB::table('model_has_permissions')->count(),
            'a permission was copied onto the user instead of being role-derived'
        );

        // ...and the ability is real anyway, which is what makes the empty pivot
        // correct rather than a silent failure to grant.
        $this->assertTrue($target->fresh()->can('users.view'));
    }

    public function test_replacing_a_role_swaps_rather_than_accumulates(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);
        $auditor = $this->makeRole('auditor', ['roles.view']);

        $target = $this->subject();
        $target->assignRole($staff);

        $this->update($target, ['roles' => ['auditor']])->assertRedirect();

        $fresh = $target->fresh();

        $this->assertSame(['auditor'], $fresh->getRoleNames()->all(), 'the old role was not removed');
        $this->assertFalse($fresh->can('users.view'), 'the replaced role still grants');
        $this->assertTrue($fresh->can('roles.view'));
    }

    /**
     * An empty array is a deliberate "remove them all". This is the case that
     * makes `array_key_exists` load-bearing rather than `isset()`.
     */
    public function test_an_empty_array_clears_every_role(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);

        $target = $this->subject();
        $target->assignRole($staff);

        $this->update($target, ['roles' => []])->assertRedirect();

        $this->assertSame([], $target->fresh()->getRoleNames()->all());
        $this->assertFalse($target->fresh()->can('users.view'));
    }

    /**
     * Omitting the key entirely must leave the assignment alone — the other
     * half of the `array_key_exists` contract, and the one that protects an
     * unrelated field edit.
     */
    public function test_omitting_the_roles_key_leaves_the_assignment_untouched(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);

        $target = $this->subject();
        $target->assignRole($staff);

        // A rename, with no `roles` key at all.
        $this->update($target, ['name' => 'Renamed By Someone Else'])->assertRedirect();

        $fresh = $target->fresh();

        $this->assertSame('Renamed By Someone Else', $fresh->name);
        $this->assertSame(['staff'], $fresh->getRoleNames()->all(), 'an omitted key cleared the roles');
        $this->assertTrue($fresh->can('users.view'));
    }

    /**
     * A name that exists on no guard is rejected by validation, not silently
     * skipped — a typo must not quietly become a role removal.
     */
    public function test_an_unknown_role_name_is_rejected(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);

        $target = $this->subject();
        $target->assignRole($staff);

        $this->update($target, ['roles' => ['no-such-role-anywhere']])
            ->assertSessionHasErrors('roles.0');

        $this->assertSame(
            ['staff'],
            $target->fresh()->getRoleNames()->all(),
            'a rejected payload still changed the assignment'
        );
    }

    /**
     * A rejected payload must not half-apply: the rename in the same request
     * does land, so the failure above cannot be an early return that silently
     * discarded everything either.
     */
    public function test_a_rejected_role_payload_does_not_corrupt_the_account(): void
    {
        $target = $this->subject();
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        $this->update($target, [
            'name' => 'Still Renamed',
            'roles' => ['no-such-role-anywhere'],
        ])->assertSessionHasErrors('roles.0');

        $fresh = $target->fresh();

        $this->assertSame([SystemRole::USER], $fresh->getRoleNames()->all());
        $this->assertSame(
            0,
            DB::table('model_has_permissions')->count(),
            'the rejected sync wrote to the user permission pivot'
        );
    }

    /**
     * Creating with an admin-supplied role list takes the same path as an
     * update, so the pivot contract applies to both.
     */
    public function test_creating_with_roles_also_stays_role_derived(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);

        $this->actingAs($this->actor->fresh(), 'web')
            ->post(route('users.store'), [
                'name' => 'Created With Roles',
                'email' => 'created-'.uniqid().'@example.test',
                'username' => 'created'.uniqid(),
                'password' => 'Password!2345',
                'password_confirmation' => 'Password!2345',
                'status' => 'active',
                'roles' => ['staff'],
            ])
            ->assertRedirect();

        $created = User::where('email', 'like', 'created-%@example.test')
            ->where('name', 'Created With Roles')
            ->firstOrFail();

        $this->assertSame(['staff'], $created->getRoleNames()->all());
        $this->assertTrue($created->can('users.view'));
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    /**
     * Self-service default. No `roles` key on the create form, so the account
     * lands on the configured default rather than on nothing — and still writes
     * nothing to the permission pivot.
     */
    public function test_a_create_without_roles_lands_on_the_default_role(): void
    {
        $this->actingAs($this->actor->fresh(), 'web')
            ->post(route('users.store'), [
                'name' => 'Created Without Roles',
                'email' => 'noroles-'.uniqid().'@example.test',
                'username' => 'noroles'.uniqid(),
                'password' => 'Password!2345',
                'password_confirmation' => 'Password!2345',
                'status' => 'active',
            ])
            ->assertRedirect();

        $created = User::where('name', 'Created Without Roles')->firstOrFail();

        $this->assertNotSame(
            [],
            $created->getRoleNames()->all(),
            'the create form must not leave the account on no role at all'
        );
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    /**
     * The sync is auditable. A role change with no audit row cannot be
     * investigated, and the audit row has to record what actually changed.
     */
    public function test_the_role_change_is_audited_with_before_and_after(): void
    {
        $staff = $this->makeRole('staff', ['users.view']);

        $target = $this->subject();
        $target->assignRole(RoleLookup::find(SystemRole::USER));

        $this->update($target, ['roles' => ['staff']])->assertRedirect();

        $row = DB::table('activity_log')
            ->where('event', 'user.roles_assigned')
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'the role change wrote no audit row');
        $this->assertStringContainsString(SystemRole::USER, $row->properties);
        $this->assertStringContainsString('staff', $row->properties);
    }
}
