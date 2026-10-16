<?php

namespace Tests\Feature\Health;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11 B4, health mirror of `PulsePermissionGateTest`: `/health` sits
 * behind BOTH gates — `health.view` and the `health` flag — and the two are
 * independent.
 *
 * Five cases, same order as the pulse file:
 *
 * 1. permission held + flag on      → 200
 * 2. permission missing             → 403
 * 3. superadmin, NO permission rows → 200 (Gate::before)
 * 4. flag off + permission held     → 403 (the kill switch answers even for
 *    an authorized holder)
 * 5. flag off + superadmin          → 403 (D-3, closed 2026-10-16 — same
 *    reason as the pulse file: the flag is a kill switch, not a role)
 *
 * The counterpart to the API twin staying ungated (phase-11 F3): the web
 * dashboard renders who-can-see-what inside the admin, so it takes the full
 * treatment here.
 */
class HealthPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_a_permission_holder_reaches_health_when_the_flag_is_on(): void
    {
        $admin = $this->login('admin');

        $this->assertTrue($admin->can('health.view'), 'precondition: admin holds health.view');
        $this->assertTrue(Feature::active('health'), 'precondition: the flag is on');

        $this->get('/health')->assertOk();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $user = $this->login('user');

        $this->assertFalse($user->can('health.view'), 'precondition: user role holds nothing');

        $this->get('/health')->assertForbidden();
    }

    public function test_superadmin_holding_no_permission_rows_reaches_health(): void
    {
        $superadmin = $this->login('superadmin');

        $this->assertSame(
            0,
            $superadmin->getAllPermissions()->count(),
            'precondition: superadmin holds no permission rows (direct or via roles)'
        );

        $this->get('/health')->assertOk();
    }

    public function test_the_flag_off_refuses_even_a_permission_holder(): void
    {
        $admin = $this->login('admin');
        $this->assertTrue($admin->can('health.view'), 'precondition: permission held');

        Feature::deactivate('health');
        Feature::flushCache();

        $this->get('/health')->assertForbidden();
    }

    /**
     * D-3, closed by owner decision 2026-10-16: superadmin respects the flag.
     *
     * `Gate::before` answers the PERMISSION question, not the flag one —
     * `feature:health` is `EnsureFeatureIsEnabled`, which has no actor check.
     * Superadmin walks through `can:health.view` and is refused here, exactly
     * as a permission holder is.
     */
    public function test_the_flag_off_refuses_superadmin_too(): void
    {
        $superadmin = $this->login('superadmin');
        $this->assertTrue($superadmin->can('health.view'), 'precondition: the before-rule answers yes');

        Feature::deactivate('health');
        Feature::flushCache();

        $this->get('/health')->assertForbidden();
    }
}
