<?php

namespace Tests\Feature\Audit;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit viewer needs BOTH the flag and the permission (Phase 10, P10-B5).
 *
 * Two independent gates answering two different questions: `activity_logs` is the
 * deploy-time kill switch, `audit.view` is the per-role authorization. Both fail
 * as 403, so a caller cannot tell which fired — the deliberate trade Phase 7 made
 * for feature availability and Phase 11 repeated for Pulse.
 *
 * ## Superadmin is the case that catches a mistake the others cannot
 *
 * They pass every `can()` through `Gate::before`, with no permission row
 * anywhere. Lose that before-rule and cases 1, 2 and 4 all still pass — only this
 * one goes red. That is why it is here and not an afterthought.
 *
 * ## Real roles, not a `Gate::before` stub
 *
 * Phase 9 found that faking permissions through the Gate leaves a test passing
 * for the wrong reason: the stub returns `null`, defers to Spatie, and Spatie
 * correctly says the admin DOES hold it. Every case here seeds the real
 * catalogue and assigns a real role.
 */
class AuditViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        // The Pennant store is fail-closed: a declared flag with no row reads as
        // FALSE, so without this seeder every route below 403s for the wrong
        // reason and the permission cases prove nothing.
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Both gates satisfied — the baseline the other three cases are read against.
     */
    #[Test]
    public function test_the_permission_and_the_flag_open_the_viewer(): void
    {
        $viewer = $this->login(SystemRole::ADMIN);

        // Assert the precondition rather than trusting the fixture: a test that
        // passes because the permission was never granted proves nothing.
        $this->assertTrue($viewer->can('audit.view'));
        $this->assertTrue(FeatureCatalog::isActive('activity_logs'));

        $this->get(route('activity-logs.index'))->assertOk();
        $this->get(route('activity-logs.show', 1))->assertNotFound();
    }

    /**
     * The flag alone is not enough. An operator who flipped `activity_logs` off
     * must not be able to read the trail by holding the permission.
     */
    #[Test]
    public function test_the_flag_off_refuses_even_with_the_permission(): void
    {
        $this->login(SystemRole::ADMIN);

        Feature::deactivate('activity_logs');
        Feature::flushCache();

        $this->assertFalse(FeatureCatalog::isActive('activity_logs'));

        $this->get(route('activity-logs.index'))->assertForbidden();
    }

    /**
     * The permission alone is not enough. `user` is seeded with an EMPTY
     * permission set by design (see `PermissionSeeder::matrix()`), so this is a
     * real role holding no audit capability — not a hand-built one.
     */
    #[Test]
    public function test_the_permission_missing_refuses_even_with_the_flag_on(): void
    {
        $viewer = $this->login(SystemRole::USER);

        $this->assertFalse($viewer->can('audit.view'));
        $this->assertTrue(FeatureCatalog::isActive('activity_logs'));

        $this->get(route('activity-logs.index'))->assertForbidden();
    }

    /**
     * Superadmin reaches the viewer with NO permission row at all.
     *
     * `PermissionSeeder::matrix()` deliberately omits superadmin: its access
     * comes from the `Gate::before` rule in `AuthServiceProvider`, so adding a
     * permission to the catalogue never requires re-seeding the role and can
     * never be stripped by editing a role.
     */
    #[Test]
    public function test_superadmin_reaches_the_viewer_without_a_permission_row(): void
    {
        $superadmin = $this->login(SystemRole::SUPERADMIN);

        $this->assertSame(0, $superadmin->roles->first()->permissions()->count());

        $this->get(route('activity-logs.index'))->assertOk();
    }

    /**
     * Both gates off — the state an operator reaches by switching the module
     * off and granting the permission to nobody. Asserted so the two 403s cannot
     * be the SAME 403: if one gate were removed, this case would go 200 while
     * cases 2 and 3 still pass, because each of them only removes one gate.
     */
    #[Test]
    public function test_both_gates_off_is_still_forbidden(): void
    {
        $this->login(SystemRole::USER);

        Feature::deactivate('activity_logs');
        Feature::flushCache();

        $this->get(route('activity-logs.index'))->assertForbidden();
    }

    /**
     * The sidebar agrees with the route.
     *
     * Without this, a permission-gated page with no menu entry is invisible and
     * nobody reports it — and the reverse failure, a menu item whose route 403s,
     * is the one `AppMenuComposer` was written to prevent (a link that refuses is
     * worse than an absent link).
     */
    #[Test]
    public function test_the_sidebar_entry_is_shown_to_a_viewer_and_hidden_from_a_non_viewer(): void
    {
        $this->login(SystemRole::USER);
        $this->assertStringNotContainsString(
            route('activity-logs.index'),
            $this->get(route('dashboard'))->getContent(),
            'a user without audit.view must not be offered the audit link'
        );

        $this->login(SystemRole::ADMIN);
        $this->assertStringContainsString(
            route('activity-logs.index'),
            $this->get(route('dashboard'))->getContent(),
            'a holder of audit.view must be able to reach the viewer'
        );
    }

    /**
     * The flag hides the sidebar entry for EVERYONE, superadmin included.
     *
     * Phase 7 established no flag bypass and this pins it for this module: a
     * flag-off module leaves no icon pointing at a 403.
     */
    #[Test]
    public function test_the_flag_off_hides_the_sidebar_entry_from_superadmin_too(): void
    {
        $this->login(SystemRole::SUPERADMIN);

        $this->assertStringContainsString(
            route('activity-logs.index'),
            $this->get(route('dashboard'))->getContent()
        );

        Feature::deactivate('activity_logs');
        Feature::flushCache();

        $this->assertStringNotContainsString(
            route('activity-logs.index'),
            $this->get(route('dashboard'))->getContent()
        );
    }

    /**
     * The flag is no longer marked `pending`.
     *
     * `config/pennant.php` carried `'pending' => 'Module not built yet'` while the
     * routes did not exist. Now they do, and `/features` must not tell an operator
     * this switch "records intent only" about a module whose 403s change the
     * moment they flip it — a toggle that reports success and changes nothing is
     * worse than no toggle.
     */
    #[Test]
    public function test_the_flag_is_no_longer_marked_pending(): void
    {
        $flags = config('pennant.features');

        $this->assertArrayHasKey('activity_logs', $flags);
        $this->assertArrayNotHasKey(
            'pending',
            $flags['activity_logs'],
            'activity_logs now gates live routes and must not read as a placeholder'
        );
    }
}
