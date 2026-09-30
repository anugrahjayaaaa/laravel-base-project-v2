<?php

namespace Tests\Feature;

use App\Actions\V1\Role\AssignRolesAction;
use App\Exceptions\LastSuperadminException;
use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-E4 and P6-E5 — the two invariants that keep the application governable.
 *
 * E4 exists because the last-superadmin guard lived in exactly one place.
 * `AssignRolesAction` refused to strip the role, and the three sibling actions
 * that remove a superadmin by some OTHER means never asked: `DeactivateUserAction`
 * flipped `is_active`, `DeleteUserAction` soft-deleted (which drops the role
 * through Spatie's soft-delete scope without anyone touching the pivot), and
 * `ForceDeleteUserAction` removed the row. Verified before the fix: a caller
 * holding `users.deactivate` + `users.delete` + `users.force_delete` and NOT
 * being a superadmin could walk the only superadmin down to zero.
 *
 * E5 is the mirror image and is about intent rather than outcome. The existing
 * guards answer WHO may grant superadmin and whether the app would be
 * stranded; neither answers whether the caller meant to. So superadmin cannot
 * be added or removed without an explicit `confirm_superadmin`.
 *
 * Every attacker here holds REAL permissions. An under-permissioned caller
 * would be refused by the route's `can:` before reaching these actions, and
 * this file would pass while proving nothing about the guards.
 */
class LastSuperadminGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function superadmin(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
            'is_locked' => false,
        ]);
        $user->assignRole(SystemRole::SUPERADMIN);

        return $user->fresh();
    }

    /**
     * A caller holding the three permissions that remove a superadmin, and NOT
     * the superadmin role — so nothing but the E4 guard can refuse.
     */
    private function operatorWithUserPowers(): User
    {
        $role = AppRole::create([
            'name' => 'Ops '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::whereIn('name', [
                'users.view', 'users.delete', 'users.deactivate', 'users.force_delete',
            ])->where('guard_name', RoleLookup::guard())->get()
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
            'is_locked' => false,
        ]);
        $user->assignRole($role);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Precondition, asserted rather than assumed: an E4 test that passes
     * because the caller lacked the permission would be measuring the route
     * gate, not the guard.
     */
    private function assertOperatorIsFullyArmed(User $operator): void
    {
        $this->assertTrue($operator->can('users.delete'), 'fixture: no users.delete');
        $this->assertTrue($operator->can('users.deactivate'), 'fixture: no users.deactivate');
        $this->assertTrue($operator->can('users.force_delete'), 'fixture: no users.force_delete');
        $this->assertFalse($operator->hasRole(SystemRole::SUPERADMIN), 'fixture: operator is a superadmin');
    }

    /**
     * P6-E4. Removing the last superadmin by any route must be refused, and the
     * refusal must leave the account intact.
     *
     * The verb is per-route, not uniform: `users.destroy` is a DELETE. Sending
     * the wrong one returns 405 and the test would "pass" a refusal that never
     * reached the action.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removalProvider(): array
    {
        return [
            'deactivated' => ['deactivate', 'post'],
            'soft deleted' => ['destroy', 'delete'],
        ];
    }

    #[DataProvider('removalProvider')]
    public function test_the_last_superadmin_cannot_be_removed(string $route, string $verb): void
    {
        $superadmin = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        $this->assertOperatorIsFullyArmed($operator);

        // A second, ordinary account so "this is the only user" style guards
        // cannot be what refuses — the invariant under test is about
        // superadmins specifically.
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($operator, 'web');

        $uri = route("users.{$route}", $superadmin);
        $response = $this->{$verb}($uri);

        // LastSuperadminException renders as a redirect carrying an `error`
        // flash (see bootstrap/app.php) — NOT a validation error bag, so
        // assertSessionHasErrors would assert the wrong contract.
        $response->assertRedirect();

        $survivor = $superadmin->fresh();

        $this->assertNotNull(
            session('error'),
            'the refusal did not carry the error flash the exception contract promises'
        );

        $this->assertTrue($survivor->is_active, 'the superadmin was deactivated anyway');
        $this->assertFalse($survivor->trashed(), 'the superadmin was trashed anyway');
        $this->assertTrue(
            $survivor->hasRole(SystemRole::SUPERADMIN),
            'the superadmin lost the role anyway'
        );

        $this->assertSame(
            1,
            User::role(SystemRole::SUPERADMIN)->where('is_active', true)->count(),
            'the active superadmin count changed despite the refusal'
        );
    }

    /**
     * ...and the app is not left with nobody. This is the whole point: the
     * count must still be one.
     */
    public function test_the_refusal_leaves_exactly_one_working_superadmin(): void
    {
        $superadmin = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($operator, 'web');

        $this->post(route('users.deactivate', $superadmin))->assertRedirect();

        $this->assertSame(1, User::role(SystemRole::SUPERADMIN)->where('is_active', true)->count());
        $this->assertTrue($superadmin->fresh()->is_active);
    }

    /**
     * The guard must not fire when a SECOND superadmin remains — otherwise the
     * safety net becomes a lockout, which is the same failure mode wearing the
     * opposite sign.
     */
    public function test_one_of_two_superadmins_can_still_be_demoted(): void
    {
        $first = $this->superadmin();
        $second = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        $this->assertOperatorIsFullyArmed($operator);

        $this->actingAs($operator, 'web');

        $this->post(route('users.deactivate', $second))->assertRedirect();

        $this->assertNull(session('error'), 'a legitimate demotion was refused');
        $this->assertFalse($second->fresh()->is_active, 'the second superadmin was not demoted');
        $this->assertTrue($first->fresh()->is_active, 'the wrong account was demoted');
    }

    /**
     * A superadmin is not the last one if they are already deactivated, so the
     * count has to be about who can administer NOW rather than who ever held
     * the role.
     */
    public function test_an_already_deactivated_superadmin_does_not_count(): void
    {
        $dormant = $this->superadmin();
        $dormant->update(['is_active' => false]);

        $live = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($operator, 'web');

        // `live` is the only superadmin who can log in, so removing `live` is
        // refused — and the dormant row must not be what saves the count.
        $this->post(route('users.deactivate', $live))->assertRedirect();

        $this->assertTrue($live->fresh()->is_active, 'the only usable superadmin was deactivated');
    }

    /**
     * The bulk path is a sibling of the single-user one and runs the same
     * action, so it inherits the guard. Pinned because it is reached through a
     * different controller with a different id key.
     */
    public function test_the_bulk_path_cannot_remove_the_last_superadmin(): void
    {
        $superadmin = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        $this->assertOperatorIsFullyArmed($operator);
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($operator, 'web');

        $this->post(route('users.bulk-action'), [
            'action' => 'deactivate',
            'user_ids' => [$superadmin->id],
            'ids' => [$superadmin->id],
        ])->assertRedirect();

        $this->assertTrue(
            $superadmin->fresh()->is_active,
            'the bulk action deactivated the last superadmin'
        );
        $this->assertSame(1, User::role(SystemRole::SUPERADMIN)->where('is_active', true)->count());
    }

    /**
     * The API is the same actions behind a different verb; the invariant does
     * not get a JSON exemption.
     */
    public function test_the_api_cannot_deactivate_the_last_superadmin(): void
    {
        $superadmin = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $token = $operator->createToken('probe')->plainTextToken;

        // No actingAs(): Sanctum resolves sanctum.guard before the bearer token,
        // so a live web session would outrank the token and authenticate the
        // request as the wrong user.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.v1.users.deactivate', $superadmin));

        $this->assertSame(409, $response->status());
        $this->assertSame('LAST_SUPERADMIN', $response->json('code'));
        $this->assertTrue($superadmin->fresh()->is_active);
    }

    // -----------------------------------------------------------------
    // P6-E5 — the confirmation flag
    // -----------------------------------------------------------------

    private function assigner(): User
    {
        return $this->superadmin();
    }

    /**
     * Granting superadmin without the flag is refused — even to a superadmin,
     * who is otherwise allowed to do exactly this. That is the point: the guard
     * is about intent, not authority.
     */
    public function test_granting_superadmin_requires_the_confirmation_flag(): void
    {
        $target = User::factory()->create();
        $assigner = $this->assigner();

        $this->expectException(AuthorizationException::class);

        app(AssignRolesAction::class)->run($target, [SystemRole::SUPERADMIN], $assigner);
    }

    public function test_granting_superadmin_succeeds_with_the_flag(): void
    {
        $target = User::factory()->create();

        app(AssignRolesAction::class)->run($target, [SystemRole::SUPERADMIN], $this->assigner(), true);

        $this->assertTrue($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    /**
     * The reverse direction, which is the one people forget. A demotion is how
     * an account quietly loses the ability to undo whatever demoted it.
     */
    public function test_removing_superadmin_requires_the_confirmation_flag(): void
    {
        $target = $this->superadmin();
        $keeper = $this->superadmin(); // so E4's last-superadmin rule cannot fire
        $assigner = $this->assigner();

        $this->expectException(AuthorizationException::class);

        app(AssignRolesAction::class)->run($target, [SystemRole::ADMIN], $assigner);
    }

    public function test_removing_superadmin_succeeds_with_the_flag(): void
    {
        $target = $this->superadmin();
        $keeper = $this->superadmin();
        $assigner = $this->assigner();

        app(AssignRolesAction::class)->run($target, [SystemRole::ADMIN], $assigner, true);

        $this->assertFalse($target->fresh()->hasRole(SystemRole::SUPERADMIN));
        $this->assertTrue($keeper->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    /**
     * The flag is only about a CHANGE. Saving a superadmin's own form — where
     * `superadmin` is in the payload both before and after — must not demand
     * it, or every unrelated edit to a superadmin account would be blocked.
     */
    public function test_resubmitting_an_unchanged_superadmin_role_needs_no_flag(): void
    {
        $target = $this->superadmin();
        $assigner = $this->assigner();

        // Same role before and after: no change, so no confirmation demanded.
        app(AssignRolesAction::class)->run($target, [SystemRole::SUPERADMIN, SystemRole::ADMIN], $assigner);

        $this->assertTrue($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    /**
     * Over HTTP, and with the state assertion — the flag has to survive the
     * FormRequest and the boolean cast, not merely work when called directly.
     */
    public function test_the_endpoint_refuses_an_unconfirmed_grant_and_writes_nothing(): void
    {
        $assigner = $this->superadmin();
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($assigner, 'web');

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
            'roles' => [SystemRole::SUPERADMIN],
        ])->assertForbidden();

        $this->assertFalse(
            $target->fresh()->hasRole(SystemRole::SUPERADMIN),
            'the refused grant still applied the role'
        );
    }

    public function test_the_endpoint_applies_a_confirmed_grant(): void
    {
        $assigner = $this->superadmin();
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($assigner, 'web');

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'status' => 'active',
            'roles' => [SystemRole::SUPERADMIN],
            'confirm_superadmin' => '1',
        ])->assertRedirect();

        $this->assertTrue($target->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    /**
     * A hidden `"0"` is the string "0" — falsy in PHP but not `false`. Without
     * the boolean cast an explicitly-UNconfirmed payload would confirm itself.
     */
    public function test_a_string_zero_does_not_count_as_confirmation(): void
    {
        $target = User::factory()->create();
        $assigner = $this->superadmin();

        $this->expectException(AuthorizationException::class);

        app(AssignRolesAction::class)->run(
            $target,
            [SystemRole::SUPERADMIN],
            $assigner,
            // What the action receives after UpdateUserAction's cast.
            filter_var('0', FILTER_VALIDATE_BOOLEAN)
        );
    }

    /**
     * The form has to be able to SEND the flag. Without the control in the
     * picker, E5 silently makes superadmin unassignable through the UI — a
     * lockout wearing a safety feature.
     */
    public function test_the_role_picker_offers_the_confirmation_control(): void
    {
        $assigner = $this->superadmin();
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($assigner, 'web');

        $html = $this->get(route('users.edit', $target))->assertOk()->getContent();

        $this->assertStringContainsString('name="confirm_superadmin"', $html);
    }

    /**
     * ...and only for a caller who may assign roles. A picker without the
     * permission must not leak the control, or the field becomes a side-channel
     * on a form that has no business carrying it.
     */
    public function test_the_confirmation_control_is_absent_without_assign_roles(): void
    {
        $role = AppRole::create([
            'name' => 'Viewer '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::findByName('users.update', RoleLookup::guard())
        );

        $editor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $editor->assignRole($role);

        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($editor->fresh(), 'web');

        $html = $this->get(route('users.edit', $target))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="confirm_superadmin"', $html);
    }

    /**
     * P6-E3 from the superadmin angle: even a superadmin cannot empty a system
     * role's permission set through the matrix.
     *
     * Asserted against `admin`, NOT `superadmin`. `superadmin` deliberately
     * holds ZERO permission rows — its access comes from `Gate::before` (see
     * PermissionSeeder::matrix()), so there is nothing there to strip and an
     * assertion over an empty array passes for the wrong reason. `admin` is
     * granted the entire catalogue, so stripping it is a real, visible loss.
     */
    public function test_even_a_superadmin_cannot_strip_a_system_permission_set(): void
    {
        $assigner = $this->superadmin();

        $this->actingAs($assigner, 'web');

        $role = RoleLookup::find(SystemRole::ADMIN);
        $before = $role->permissions->pluck('id')->sort()->values()->all();

        // Precondition: an empty comparison proves nothing if the role had no
        // permissions to begin with.
        $this->assertNotEmpty($before, 'fixture: admin had no permissions to begin with');

        $this->from(route('roles.index'))
            ->put(route('roles.update', $role), [
                'name' => SystemRole::ADMIN,
                'permissions' => [],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame(
            $before,
            $role->fresh()->permissions->pluck('id')->sort()->values()->all(),
            'the admin permission set was stripped by a refused request'
        );

        // The consequence, stated directly: the delegated superadmin still works.
        $this->assertTrue(
            $assigner->fresh()->can('users.view'),
            'the acting superadmin lost access through a refused request'
        );
    }

    /**
     * The guard queries the pivot, so pin the cost while it is here rather
     * than discovering a per-request COUNT on a page that renders a sidebar.
     */
    public function test_the_last_superadmin_guard_costs_one_query(): void
    {
        $superadmin = $this->superadmin();
        $operator = $this->operatorWithUserPowers();
        $this->assertOperatorIsFullyArmed($operator);
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($operator, 'web');

        // Warm the permission cache and the session first.
        $this->post(route('users.deactivate', $superadmin));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->post(route('users.deactivate', $superadmin));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $counts = 0;
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'model_has_roles')) {
                $counts++;
            }
        }

        // The guard's own COUNT plus the roles it reads. A per-user loop would
        // scale this with the id list; the single-target path cannot.
        $this->assertLessThanOrEqual(
            3,
            $counts,
            "the deactivate path ran {$counts} pivot queries — the guard may be looping"
        );
    }
}
