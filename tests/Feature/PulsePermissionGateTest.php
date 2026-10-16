<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11 B4: `/pulse` sits behind BOTH gates — `pulse.view` and the `pulse`
 * flag — and the two are independent.
 *
 * Five cases per route (the health mirror lives in
 * `tests/Feature/Health/HealthPermissionGateTest.php`):
 *
 * 1. permission held + flag on        → 200 (the gate override lets a
 *    non-local, non-superadmin through — Pulse's own definition would not)
 * 2. permission missing               → 403
 * 3. superadmin, NO permission rows   → 200 (Gate::before; the case that
 *    catches a lost `before` — cases 1, 2, 4 and 5 all pass without it)
 * 4. flag off + permission held       → 403 (pins the two gates as
 *    independent: the kill switch answers even for an authorized holder)
 * 5. flag off + superadmin            → 403 (D-3, closed 2026-10-16: the flag
 *    is a kill switch, not a role, and `EnsureFeatureIsEnabled` has no bypass
 *    for the before-rule to walk through)
 *
 * Case 1 is why `AuthServiceProvider::configurePulseGate()` exists at all:
 * Pulse defines `viewPulse` as `environment('local')`, which is false in
 * testing — without the override, an admin holding the permission gets 403.
 *
 * This file is what Phase 10's P10-B5 says to copy for the audit-trail gate.
 */
class PulsePermissionGateTest extends TestCase
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

    public function test_a_permission_holder_reaches_pulse_when_the_flag_is_on(): void
    {
        $admin = $this->login('admin');

        $this->assertTrue($admin->can('pulse.view'), 'precondition: admin holds pulse.view');
        $this->assertTrue(Feature::active('pulse'), 'precondition: the flag is on');

        $this->get('/pulse')->assertOk();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $user = $this->login('user');

        $this->assertFalse($user->can('pulse.view'), 'precondition: user role holds nothing');

        $this->get('/pulse')->assertForbidden();
    }

    /**
     * The case the other three cannot catch: superadmin holds NO permission
     * rows at all, so access must come from `Gate::before`. A refactor that
     * replaces the before-rule with a real grant leaves cases 1, 2 and 4 green
     * and fails only here.
     */
    public function test_superadmin_holding_no_permission_rows_reaches_pulse(): void
    {
        $superadmin = $this->login('superadmin');

        $this->assertSame(
            0,
            $superadmin->getAllPermissions()->count(),
            'precondition: superadmin holds no permission rows (direct or via roles)'
        );

        $this->get('/pulse')->assertOk();
    }

    public function test_the_flag_off_refuses_even_a_permission_holder(): void
    {
        $admin = $this->login('admin');
        $this->assertTrue($admin->can('pulse.view'), 'precondition: permission held');

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->get('/pulse')->assertForbidden();
    }

    /**
     * D-3, closed by owner decision 2026-10-16: superadmin respects the flag.
     *
     * `Gate::before` answers the PERMISSION question, not the flag one —
     * `EnsureFeatureIsEnabled` reads `FeatureCatalog::isActive()` and has no
     * actor check at all (`app/Http/Middleware/EnsureFeatureIsEnabled.php:35`).
     * So the one actor who never needs `pulse.view` still cannot reach a
     * dashboard whose module is switched off, which is what makes this a kill
     * switch rather than a role.
     */
    public function test_the_flag_off_refuses_superadmin_too(): void
    {
        $superadmin = $this->login('superadmin');
        $this->assertTrue($superadmin->can('pulse.view'), 'precondition: the before-rule answers yes');

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->get('/pulse')->assertForbidden();
    }
}
