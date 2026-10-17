<?php

namespace Tests\Feature\Vantage;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11 §13: `/vantage` sits behind BOTH gates — `queues.view` and the
 * `vantage` flag — and the two are independent, exactly as `/pulse` and
 * `/health` do (`PulsePermissionGateTest`, `HealthPermissionGateTest`).
 *
 * Five cases, same order as the other two dashboards:
 *
 * 1. permission held + flag on      → 200
 * 2. permission missing             → 403
 * 3. superadmin, NO permission rows → 200 (Gate::before)
 * 4. flag off + permission held     → 403 (the kill switch answers even for an
 *    authorized holder)
 * 5. flag off + superadmin          → 403 (the flag is a kill switch, not a
 *    role — `EnsureFeatureIsEnabled` has no actor check)
 *
 * Two more the other dashboards do not need, because Vantage is the first
 * module with a permission SPLIT across its routes (`audit.view`/`audit.export`
 * is the other):
 *
 * 6. `queues.view` without `queues.retry` reaches the dashboard but is refused
 *    the retry POST — pins that retry is a separate act, not a subset of view
 * 7. the overridden `viewVantage` gate answers to `queues.view`, not to the
 *    package default ("any authenticated user")
 */
class VantagePermissionGateTest extends TestCase
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

    public function test_a_permission_holder_reaches_vantage_when_the_flag_is_on(): void
    {
        $admin = $this->login('admin');

        $this->assertTrue($admin->can('queues.view'), 'precondition: admin holds queues.view');
        $this->assertTrue(Feature::active('vantage'), 'precondition: the flag is on');

        $this->get('/vantage')->assertOk();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $user = $this->login('user');

        $this->assertFalse($user->can('queues.view'), 'precondition: user role holds nothing');

        $this->get('/vantage')->assertForbidden();
    }

    public function test_superadmin_holding_no_permission_rows_reaches_vantage(): void
    {
        $superadmin = $this->login('superadmin');

        $this->assertSame(
            0,
            $superadmin->getAllPermissions()->count(),
            'precondition: superadmin holds no permission rows (direct or via roles)'
        );

        $this->get('/vantage')->assertOk();
    }

    public function test_the_flag_off_refuses_even_a_permission_holder(): void
    {
        $admin = $this->login('admin');
        $this->assertTrue($admin->can('queues.view'), 'precondition: permission held');

        Feature::deactivate('vantage');
        Feature::flushCache();

        $this->get('/vantage')->assertForbidden();
    }

    public function test_the_flag_off_refuses_superadmin_too(): void
    {
        $superadmin = $this->login('superadmin');
        $this->assertTrue($superadmin->can('queues.view'), 'precondition: the before-rule answers yes');

        Feature::deactivate('vantage');
        Feature::flushCache();

        $this->get('/vantage')->assertForbidden();
    }

    /**
     * Retry is a separate permission from view. A viewer holding only
     * `queues.view` reaches the dashboard but cannot re-queue a job — the same
     * widening the audit viewer avoids by keeping `audit.export` apart from
     * `audit.view`.
     */
    public function test_view_permission_does_not_grant_retry(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $viewer->givePermissionTo('queues.view');
        $this->actingAs($viewer, 'web');

        $this->assertTrue($viewer->can('queues.view'), 'precondition: holds queues.view');
        $this->assertFalse($viewer->can('queues.retry'), 'precondition: does not hold queues.retry');

        $this->get('/vantage')->assertOk();

        // CSRF is enforced in tests (VerifyCsrfToken::runningUnitTests() is
        // hardcoded true here); the subject is the permission gate, so skip CSRF
        // rather than carry a token — same approach as RoleManagementTest.
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->post('/vantage/jobs/1/retry')->assertForbidden();
    }

    /**
     * The override `AuthServiceProvider::configureVantageGate()` is the point of
     * this case: the package defines `viewVantage` as "any authenticated user",
     * so without the override a plain `user` would pass it.
     */
    public function test_the_view_vantage_gate_follows_the_view_permission(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $viewer->givePermissionTo('queues.view');

        $this->assertTrue(
            Gate::forUser($viewer)->allows('viewVantage'),
            'a queues.view holder passes the overridden gate'
        );

        $plain = $this->login('user');

        $this->assertFalse(
            Gate::forUser($plain)->allows('viewVantage'),
            'a plain authenticated user does NOT — the package default would let them'
        );
    }
}
