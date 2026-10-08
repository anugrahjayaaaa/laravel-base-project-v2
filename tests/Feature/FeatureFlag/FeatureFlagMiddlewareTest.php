<?php

namespace Tests\Feature\FeatureFlag;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Spatie\Permission\PermissionRegistrar;

/**
 * The kill switch itself — P7-C1, C2 and C5.
 *
 * Everything else in Phase 7 is the management surface: a catalogue, a page, a
 * controller, an audit trail. This is the only group that decides whether a
 * request is allowed, so these assert the matrix that the rest of the phase
 * assumes.
 *
 * The negative cases carry the weight. "Flag off → 403" is easy to get right by
 * accident; "superadmin also gets 403" and "`features.manage` holder also gets
 * 403" are the assertions that fail the moment someone helpfully adds a bypass.
 */
class FeatureFlagMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    /**
     * A throwaway route carrying the middleware, registered per test.
     *
     * A real route would be better, but every one of them is also gated by
     * `can:` and the account-state middleware, and this middleware answers 403 —
     * the same status both of those return. On a real route a 403 could not be
     * attributed: permission refused, account disabled, or module killed. One
     * route, one middleware, one answer.
     */
    private function gatedRoute(string $flag): string
    {
        Route::middleware(['web', 'auth', 'feature:'.$flag])
            ->get('/_test/feature-gated', fn () => 'reachable')
            ->name('test.feature-gated');

        // The URL generator caches its route set, so a route registered after
        // the app booted is invisible to `route()` until it is refreshed —
        // without this the helper's own return value throws RouteNotFound.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        return 'test.feature-gated';
    }

        public function test_an_active_flag_lets_the_request_through (): void
    {
        $this->login();
        $name = $this->gatedRoute('users');

        $this->assertSame(200, $this->get(route($name))->getStatusCode());
    }

        public function test_an_inactive_flag_returns_403 (): void
    {
        $this->login();
        Feature::deactivate('users');
        Feature::flushCache();
        $name = $this->gatedRoute('users');

        $this->assertSame(403, $this->get(route($name))->getStatusCode());
    }

    /**
     * The kill switch has no privileged escape.
     *
     * A bypass is the tempting middle ground and it is wrong: "the feature is off
     * but the CEO can still see it" is a state nobody asked for, and a superadmin
     * who cannot reach a killed module is exactly what an operator needs during
     * an incident.
     *
     * @return array<string, array{0: string}>
     */
        #[DataProvider('roles')]
    public function test_an_inactive_flag_returns_403_for_every_role (string $role): void
    {
        $this->login($role);
        Feature::deactivate('users');
        Feature::flushCache();
        $name = $this->gatedRoute('users');

        $this->assertSame(
            403,
            $this->get(route($name))->getStatusCode(),
            "a disabled feature must be invisible to {$role} too — no bypass"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roles(): array
    {
        return [
            'admin' => [SystemRole::ADMIN],
            'superadmin' => [SystemRole::SUPERADMIN],
        ];
    }

    /**
     * Holding `features.manage` is not a licence to walk through a killed
     * module. The manager re-enables from `/features` — a page that is itself
     * deliberately left ungated — and comes back.
     */
        public function test_a_features_manage_holder_still_gets_403 (): void
    {
        $manager = $this->login();
        $manager->givePermissionTo('features.manage');

        Feature::deactivate('users');
        Feature::flushCache();
        $name = $this->gatedRoute('users');

        $this->assertSame(403, $this->get(route($name))->getStatusCode());
    }

    /**
     * Fail-closed on a slug nobody declared.
     *
     * A typo in a route's flag must not become a route with no gate on it. The
     * catalogue has no such slug, so `isActive()` is false and the request is
     * refused — the opposite failure would be a live endpoint protected by
     * nothing.
     */
        public function test_an_undeclared_slug_is_refused_rather_than_allowed_through (): void
    {
        $this->login();
        $name = $this->gatedRoute('not_a_real_flag');

        $this->assertSame(403, $this->get(route($name))->getStatusCode());
    }

    /**
     * The config kill switch wins over a stored `true`.
     *
     * This is the case Pennant's own middleware cannot serve: it resolves through
     * `Feature::active()`, which asks the store, so a row an operator set earlier
     * reads as active and `Feature::someAreInactive()` reports the flag as fine.
     * `FeatureCatalog::isActive()` consults config first — which is why this
     * project's middleware is its own class rather than an alias of Pennant's.
     */
        public function test_a_config_kill_switch_beats_a_stored_active_row (): void
    {
        $this->login();

        $this->assertTrue(
            Feature::active('users'),
            'precondition: the store row says active'
        );

        config(['pennant.features.users.disabled' => true]);
        Feature::flushCache();
        $name = $this->gatedRoute('users');

        $this->assertSame(
            403,
            $this->get(route($name))->getStatusCode(),
            'disabled => true must win over a stored true, or the kill switch is decorative'
        );
    }

    /**
     * Several flags on one route are ANDed — the route needs all of them.
     *
     * The alias appears ONCE. `feature:users,feature:roles` splits on the first
     * colon and then on commas, giving the literal 'feature:roles', which is an
     * undeclared slug: it fails closed into a 403 that reads like a working kill
     * switch rather than a typo. That is the failure this test exists to keep
     * the correct spelling honest.
     */
        public function test_several_flags_must_all_be_active(): void
    {
        $this->login();
        Route::middleware(['web', 'auth', 'feature:users,roles'])
            ->get('/_test/feature-multi', fn () => 'reachable')
            ->name('test.feature-multi');

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        $this->assertSame(200, $this->get(route('test.feature-multi'))->getStatusCode());

        Feature::deactivate('roles');
        Feature::flushCache();

        $this->assertSame(403, $this->get(route('test.feature-multi'))->getStatusCode());
    }

    /**
     * `/features` must never be gated on the module it controls.
     *
     * A gate there would remove the only page that can bring a flag back, and the
     * fix would be a redeploy. The comment at `routes/web.php:171-174` says so;
     * this fails if someone adds the gate anyway.
     */
        public function test_the_management_page_is_never_itself_gated (): void
    {
        $this->login();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->getName() === 'features.index'
        );

        $this->assertNotNull($route, 'features.index route is gone');
        $this->assertNotContains(
            'feature:users',
            $route->gatherMiddleware(),
            'gating the flag management page on a flag removes the way back'
        );
    }
}
