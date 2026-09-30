<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6-E7 — the two scenarios the brief specifies, run end to end.
 *
 * Everything else in Phase 6 proves a boundary one route at a time. This is
 * the check that the boundary is coherent as a whole: two real accounts, the
 * screens they can open, and the menu they are offered. The three layers —
 * route `can:`, the sidebar composer, and the in-page controls — are asserted
 * together, because a disagreement between any two of them is what produces a
 * link that 403s or a button the server refuses.
 */
class RbacAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // The settings WRITE below is a POST, and VerifyCsrfToken reports
        // runningUnitTests() false by design, so it must be dropped or the
        // request comes back 419 and the assertion measures CSRF.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    /**
     * A user holding exactly the named permissions, and nothing else.
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        if ($permissions === []) {
            $user->assignRole(RoleLookup::find(SystemRole::USER));

            return $user->fresh();
        }

        $role = AppRole::create([
            'name' => 'Matrix '.implode('+', $permissions).' '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::whereIn('name', $permissions)
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user->assignRole($role);

        return $user->fresh();
    }

    // -----------------------------------------------------------------
    // Scenario A — role `user`, zero permissions
    // -----------------------------------------------------------------

    public function test_scenario_a_is_refused_every_admin_screen(): void
    {
        $nobody = $this->userWith([]);

        // Precondition. Without this the refusals below would also pass for a
        // caller who is somehow not authenticated at all.
        $this->actingAs($nobody, 'web');
        $this->assertAuthenticatedAs($nobody);
        $this->assertFalse($nobody->can('users.view'));
        $this->assertFalse($nobody->can('settings.view'));
        $this->assertFalse($nobody->can('roles.view'));
        $this->assertFalse($nobody->can('permissions.view'));

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('permissions.index'))->assertForbidden();
    }

    /**
     * ...and still reaches everything ungated. A `Gate::before` that answered
     * "no" to everything would satisfy the test above while locking every
     * account out of the application, so the other half has to be asserted too.
     */
    public function test_scenario_a_keeps_the_self_service_screens(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get(route('sessions'))->assertOk();
    }

    /**
     * The menu must not advertise a screen that would 403. Asserted against the
     * rendered URLs rather than the words "Users"/"Roles", because a label can
     * appear in unrelated copy (a heading, a flash message) while the link
     * itself is correctly absent.
     */
    public function test_scenario_a_is_offered_no_admin_links(): void
    {
        $nobody = $this->userWith([]);

        $this->actingAs($nobody, 'web');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('users.index'), $html);
        $this->assertStringNotContainsString(route('roles.index'), $html);
        $this->assertStringNotContainsString(route('permissions.index'), $html);
        $this->assertStringNotContainsString(route('settings.index'), $html);

        // And the two the composer is meant to leave alone must survive, so the
        // assertions above cannot pass because the sidebar rendered empty.
        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringContainsString(route('profile.show'), $html);
    }

    // -----------------------------------------------------------------
    // Scenario B — role `staff`: users.view + users.update + settings.manage
    // -----------------------------------------------------------------

    public function test_scenario_b_reaches_what_its_permissions_allow(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);

        $this->actingAs($staff, 'web');

        $this->get(route('users.index'))->assertOk();

        // settings.manage implies nothing about settings.view at the route, so
        // the read is asserted separately: the two are different permissions
        // and the brief's staff account holds only the write.
        $this->assertTrue($staff->can('users.view'));
        $this->assertTrue($staff->can('settings.manage'));
    }

    /**
     * The brief's staff account is refused the role screens. `roles.view` is
     * what it lacks, and the refusal has to be that — not an accident of some
     * other missing permission.
     */
    public function test_scenario_b_is_refused_the_role_screens(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);

        $this->assertFalse($staff->can('roles.view'));
        $this->assertFalse($staff->can('permissions.view'));

        $this->actingAs($staff, 'web');

        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('permissions.index'))->assertForbidden();
    }

    public function test_scenario_b_is_offered_users_but_not_roles(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);

        $this->actingAs($staff, 'web');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('users.index'), $html);
        $this->assertStringNotContainsString(route('roles.index'), $html);
        $this->assertStringNotContainsString(route('permissions.index'), $html);
    }

    /**
     * Scenario B holds `settings.manage` but not `settings.view`. Since D8
     * splits the page, this states what actually happens rather than assuming
     * the brief's two permissions cover the screen: the write alone does not
     * open the read.
     *
     * Pinned because it is the one place where the brief's two scenarios do not
     * quite meet the shipped permission split, and the difference should be a
     * decision someone made rather than an accident.
     */
    public function test_scenario_b_settings_manage_alone_does_not_open_the_settings_page(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);

        $this->assertTrue($staff->can('settings.manage'));
        $this->assertFalse($staff->can('settings.view'));

        $this->actingAs($staff, 'web');

        $this->get(route('settings.index'))->assertForbidden();

        // ...while the WRITE endpoint, which is the permission actually held,
        // remains reachable. Otherwise this test would be reporting a lockout
        // rather than the split.
        $this->post(route('settings.update'), ['login_max_attempts' => 9])
            ->assertRedirect();
    }

    /**
     * With BOTH settings permissions the page opens and carries the form — the
     * other half of the split, so the previous test cannot pass merely because
     * the route is broken for everyone.
     */
    public function test_a_settings_reader_with_the_write_permission_gets_the_form(): void
    {
        $staff = $this->userWith(['users.view', 'settings.view', 'settings.manage']);

        $this->actingAs($staff, 'web');

        $html = $this->get(route('settings.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('settings.update'), $html);
    }

    /**
     * The in-page layer, closing the loop with the routes: a staff account may
     * edit users, so the edit form opens — and the role picker is absent,
     * because `users.assign_roles` is a separate permission it does not hold.
     */
    public function test_scenario_b_may_edit_a_user_without_the_role_picker(): void
    {
        $staff = $this->userWith(['users.view', 'users.update', 'settings.manage']);
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($staff, 'web');

        $html = $this->get(route('users.edit', $target))->assertOk()->getContent();

        // The picker and its hidden input are one unit: without users.assign_roles
        // the form must post no `roles` key at all, or an unrelated save would
        // strip the target's roles.
        $this->assertStringNotContainsString('name="roles[]"', $html);
        $this->assertStringNotContainsString('name="confirm_superadmin"', $html);
    }
}
