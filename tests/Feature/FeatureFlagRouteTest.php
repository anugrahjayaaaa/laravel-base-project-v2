<?php

namespace Tests\Feature;

use App\Actions\V1\Feature\FeatureToggleAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The route, the sidebar entry, and the toggle — P7-D5 and P7-D8.
 */
class FeatureFlagRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app,
        // so every POST here would 419 before reaching the thing under test.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

        public function the_index_needs_features_view(): void
    {
        $this->assertTrue(
            collect(Route::getRoutes())->contains(
                fn ($route): bool => $route->getName() === 'features.index'
                    && $route->gatherMiddleware()
            ),
            'precondition: the index route is registered'
        );

        $this->login(SystemRole::USER);
        $this->get(route('features.index'))->assertForbidden();
    }

        public function a_manager_sees_the_page(): void
    {
        $this->login();
        $this->get(route('features.index'))->assertOk();
    }

    /**
     * The toggle POST is a 403 for a viewer, not a 404.
     *
     * The permission and the feature are different things. The flag being on
     * says the page exists; `features.manage` says this user may act. A viewer
     * reaching a hidden page is a 403, and answering 404 would tell an
     * unauthorized user the feature does not exist.
     */
        public function the_toggle_needs_features_manage(): void
    {
        $this->login(SystemRole::USER);

        $this->post(route('features.toggle', 'users'), ['enabled' => 0])
            ->assertForbidden();
    }

        public function toggling_flips_the_flag_and_audits_it(): void
    {
        $admin = $this->login();

        $this->assertTrue(Feature::active('users'), 'precondition: the flag starts on');

        $this->post(route('features.toggle', 'users'), ['enabled' => 0])->assertRedirect();

        $this->assertFalse(Feature::active('users'), 'the flag did not turn off');

        $activity = Activity::where('event', FeatureToggleAction::EVENT)->latest('id')->first();
        $this->assertNotNull($activity, 'no audit row was written for a flag change');
        $this->assertTrue(
            $activity->causer->is($admin),
            'the audit row does not name the operator who made the change'
        );
        $this->assertSame('users', $activity->properties['feature']);
        $this->assertTrue($activity->properties['from'], '`from` should record the previous on state');
        $this->assertFalse($activity->properties['to'], '`to` should record the new off state');
    }

    /**
     * The audit row records the EFFECTIVE state, not the store's.
     *
     * With the config kill switch on, the flag is being served as off while the
     * row still says `true`. An audit log reading `from: true` there would say
     * the flag was live when it was not.
     */
        public function the_audit_row_records_the_effective_state(): void
    {
        $this->login();

        config(['pennant.features.users.disabled' => true]);

        $this->post(route('features.toggle', 'users'), ['enabled' => 1])->assertRedirect();

        $activity = Activity::where('event', FeatureToggleAction::EVENT)->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertFalse(
            $activity->properties['from'],
            '`from` read the store instead of the effective state: a kill-switched '
            .'flag was off to users but logged as on'
        );
    }

        public function an_unknown_slug_is_a_404(): void
    {
        $this->login();

        $this->post(route('features.toggle', 'no_such_flag'), ['enabled' => 1])
            ->assertNotFound();
    }

    /**
     * `enabled` is validated, not cast.
     *
     * Without this, `?enabled=maybe` reaches `(bool) 'maybe'` and silently means
     * "on" — a write the operator did not ask for.
     */
        public function a_non_boolean_enabled_is_rejected(): void
    {
        $this->login();

        $this->post(route('features.toggle', 'users'), ['enabled' => 'maybe'])
            ->assertSessionHasErrors('enabled');

        $this->assertTrue(Feature::active('users'), 'a rejected request still wrote the flag');
    }

        public function the_toggle_forgets_the_resolved_snapshot(): void
    {
        $this->login();

        // Warm the page's cache first, or this proves nothing.
        $this->get(route('features.index'))->assertOk();
        $this->assertTrue(Cache::has('feature_flags.resolved'), 'precondition: the snapshot is warm');

        $this->post(route('features.toggle', 'users'), ['enabled' => 0])->assertRedirect();

        $this->assertFalse(
            Cache::has('feature_flags.resolved'),
            'the resolved snapshot outlived the write, so the page would show the old state'
        );
    }

    /**
     * The rendered sidebar, not the composer's array.
     *
     * Read through a real response: the composer's `visible()` already drops an
     * item whose route or permission fails, so asserting on the array would
     * test the composer while saying nothing about what a user actually sees.
     */
        public function the_sidebar_entry_follows_features_view(): void
    {
        $this->login();
        $this->get(route('dashboard'))->assertOk()->assertSee('Feature Flags');

        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('features.index'), false);
    }

    /**
     * The catalogue and the routes must not drift.
     *
     * A flag in `config/pennant.php` with no toggle route is a switch an
     * operator can see but not flip.
     */
        public function every_declared_flag_is_reachable_from_the_page(): void
    {
        $admin = $this->login();

        $html = $this->get(route('features.index'))->assertOk()->getContent();

        foreach (FeatureCatalog::slugs() as $slug) {
            $this->assertStringContainsString(
                route('features.toggle', $slug),
                $html,
                "[{$slug}] is listed but has no toggle wired to it"
            );
        }
    }

    /**
     * The API session matrix must match the web one.
     *
     * Both `logout-all` routes call the same action and write the same audit
     * event. An ungated API twin therefore let a client mass-logout every device
     * with the sessions module switched off — the web side refused, the API did
     * not, and nothing recorded that the difference was deliberate.
     *
     * Asserted on the route's own middleware rather than by making the call, so
     * the intent is visible without needing a second logged-out device.
     */
        public function the_api_logout_all_route_carries_the_sessions_gate(): void
    {
        $gated = collect(app('router')->getRoutes())
            ->filter(fn ($r) => in_array($r->getName(), [
                'api.v1.auth.logout-all',
                'sessions.logout-all',
            ], true))
            ->mapWithKeys(fn ($r) => [
                $r->getName() => implode(' ', $r->gatherMiddleware()),
            ]);

        foreach (['api.v1.auth.logout-all', 'sessions.logout-all'] as $name) {
            $this->assertArrayHasKey($name, $gated, "route {$name} is missing");
            $this->assertStringContainsString(
                'feature:sessions',
                $gated[$name],
                "[{$name}] is not gated — the API twin can mass-logout while the module is off"
            );
        }
    }

    /**
     * `logout` itself must stay reachable with the module off, or a bad flag
     * strands the user in a session they cannot end.
     */
        public function logout_stays_reachable_with_the_sessions_module_off(): void
    {
        foreach (['logout', 'api.v1.auth.logout'] as $name) {
            $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === $name);

            $this->assertNotNull($route, "route {$name} is missing");
            $this->assertStringNotContainsString(
                'feature:sessions',
                implode(' ', $route->gatherMiddleware()),
                "[{$name}] is gated — a user cannot end their own session"
            );
        }
    }
}
