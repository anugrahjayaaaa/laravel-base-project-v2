<?php

namespace Tests\Feature\FeatureFlag;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The `pulse` flag actually gates `/pulse`.
 *
 * ## Why this file exists
 *
 * Pulse is the one module whose routes this project does not declare, so it was
 * the one module the flag silently failed to control. Measured before the fix:
 * `GET /pulse` returned **200 with the flag off**, while `/features` showed
 * "Pulse Dashboard — Inactive" and wrote a `feature.toggled` audit row. A flag
 * that reads Inactive over a live dashboard is worse than no flag, because the
 * operator believes they turned something off.
 *
 * The fix is a vendor feature used as intended — `feature:pulse` appended to
 * `pulse.middleware`, which Pulse merges into the group its routes register
 * with. `pulse` is a middleware GROUP, so `route:list` shows only `pulse` and
 * the flag is invisible there; this asserts the outcome over HTTP instead.
 *
 * The ON case asserts 403 too, and that is correct: Pulse's own `Authorize`
 * middleware refuses a user without `viewPulse`. What matters is that the flag
 * changes nothing about that — it is an additional gate, not the only one, so
 * this pins the off state (403 from OUR middleware) and proves the middleware
 * is what produced it.
 *
 * The caller here is a **permission holder**, not a guest: `config/pulse.php`
 * puts `auth` ahead of the gates, so a guest is redirected to `/login` and
 * never reaches the flag at all — that case would pass with no flag wired.
 * `PulsePermissionGateTest` carries the same pairing from the other side.
 */
class PulseFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_pulse_route_is_refused_with_the_flag_off(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));
        $this->actingAs($user, 'web');

        $this->assertTrue($user->can('pulse.view'), 'precondition: the caller holds pulse.view');

        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->assertFalse(
            FeatureCatalog::isActive('pulse'),
            'precondition: the flag is off'
        );

        $this->get('/pulse')->assertForbidden();
    }

    /**
     * The flag is wired to the route, not merely declared.
     *
     * Without this, a `pulse` slug nobody reads would look identical to a gated
     * one whenever the caller also lacks `viewPulse` — the `Authorize`
     * middleware answers 403 on its own, so the off case alone cannot tell the
     * two apart.
     */
    public function test_the_pulse_middleware_group_carries_the_feature_gate(): void
    {
        $group = app('router')->getMiddlewareGroups()['pulse'] ?? [];

        $this->assertContains(
            'feature:pulse',
            $group,
            'the `pulse` middleware group does not carry the flag, so /pulse is ungated'
        );
    }

    /**
     * The declared flag is the same slug the gate reads.
     *
     * A typo in either place — config or middleware — makes the flag decorative
     * while everything still looks wired.
     */
    public function test_the_gated_slug_is_declared_in_the_catalogue(): void
    {
        $this->assertTrue(
            FeatureCatalog::has('pulse'),
            'the middleware gates on a slug the catalogue does not declare'
        );
    }
}
