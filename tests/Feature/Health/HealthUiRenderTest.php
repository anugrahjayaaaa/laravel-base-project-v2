<?php

namespace Tests\Feature\Health;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11 A4 gate: the system health page renders, obeys the design system,
 * executes no query of its own, and both new sidebar entries sit behind their
 * permission AND their flag.
 *
 * Same rules `SettingsUiRenderTest` enforces for the settings page:
 * `ui-architecture.md` rule 1 (no query from inside a view — the controller
 * hands over the checks array) and the forbidden class list from
 * `design-system.md`.
 *
 * The A4 checklist also names "every feedback div d-block + aria-describedby":
 * that assertion is scoped to forms, and this page is read-only with no input
 * anywhere — `HealthPermissionGateTest` covers the route, and there is no
 * field to carry an error. Stated here so the omission reads as a decision
 * rather than a skipped copy step.
 *
 * Rendered outside the HTTP kernel with an empty error bag: a real request
 * gets `errors` from ShareErrorsFromSession and a bare `view()->render()`
 * would fail on `$errors->any()`.
 */
class HealthUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sign in as a real seeded role — not a Gate::before override, which would
     * test the override instead of the page.
     */
    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    /**
     * The view data HealthController::index() hands over: one card per check,
     * a page-level badge derived from isHealthy().
     *
     * One check deliberately `fail` so BOTH badge branches are exercised by
     * rendering with a variant, not by asserting the healthy render twice.
     *
     * @return array<string, mixed>
     */
    private function viewData(bool $healthy = true): array
    {
        return [
            'checks' => $healthy
                ? ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok']
                : ['database' => 'ok', 'cache' => 'ok', 'queue' => 'fail', 'storage' => 'ok'],
            'isHealthy' => $healthy,
        ];
    }

    private function render(bool $healthy = true): string
    {
        return view('pages.health.index', array_merge(
            ['errors' => new ViewErrorBag()],
            $this->viewData($healthy),
        ))->render();
    }

    /**
     * The page must not reach for the database from inside Blade — the
     * controller already hands over the checks array. Measured on the warm
     * second render, because the first warms Spatie's permission cache and the
     * sidebar composer's flag read: a query left in Blade fires on the second
     * render too, so the delta is what actually measures the view.
     */
    public function test_it_renders_and_queries_nothing(): void
    {
        $this->login();

        $this->render();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotSame('', $html, 'the health page rendered nothing');
        $this->assertSame([], $queries, 'the health page queried from inside the view');
    }

    public function test_the_forbidden_classes_never_appear(): void
    {
        $this->login();
        $html = $this->render();

        $this->assertDoesNotMatchRegularExpression('/\bbg-white\b/', $html, 'uses bg-white');
        $this->assertDoesNotMatchRegularExpression('/\bbg-light\b/', $html, 'uses bg-light');
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bcard-body\b(?! p-4)[^"]*"/',
            $html,
            'has a card-body without p-4'
        );
    }

    /**
     * One card per check — a check dropped from the loop is a service nobody
     * is told is down. Asserted per name against the rendered markup, not on
     * the fact that something rendered.
     */
    public function test_it_renders_one_card_per_check(): void
    {
        $this->login();
        $html = $this->render();

        foreach (['Database', 'Cache', 'Queue', 'Storage'] as $label) {
            $this->assertStringContainsString($label, $html, "no card rendered for {$label}");
        }

        $this->assertSame(
            4,
            substr_count($html, 'card-body p-4'),
            'expected exactly one status card per check'
        );
    }

    /**
     * Both page-level badge branches: the healthy render must not carry the
     * failure copy, and a single failing check must flip it. Colour alone
     * never carries the state — the badge text does (ui-ux-pro-max: no
     * color-only status).
     */
    public function test_the_page_badge_follows_the_overall_status(): void
    {
        $this->login();

        $healthy = $this->render(true);
        $this->assertStringContainsString('All systems operational', $healthy);
        $this->assertStringNotContainsString('One or more checks failing', $healthy);

        $degraded = $this->render(false);
        $this->assertStringContainsString('One or more checks failing', $degraded);
        // The icon and the word travel together: colour never carries the state.
        $this->assertStringContainsString('</i>Fail', $degraded, 'the failing check shows no fail badge');
        $this->assertStringNotContainsString('All systems operational', $degraded);
    }

    /**
     * The Pulse sidebar entry needs `pulse.view` AND the `pulse` flag — the
     * same two gates its route has, so the sidebar cannot advertise a screen
     * the route refuses. Flag independence is the point of the third case:
     * hiding both entries on either flag would be one switch pretending to be
     * two.
     */
    public function test_the_pulse_sidebar_entry_needs_its_permission_and_its_flag(): void
    {
        $this->login('admin');
        $this->assertStringContainsString('fa-heart-pulse', $this->sidebar(), 'admin with the flag on must see the entry');

        Feature::deactivate('pulse');
        Feature::flushCache();
        $this->assertStringNotContainsString(
            'fa-heart-pulse',
            $this->sidebar(),
            'flag off must drop the Pulse entry even for a permission holder'
        );
        // The other entry stays: `health` is a separate switch.
        $this->assertStringContainsString('fa-stethoscope', $this->sidebar(), 'the health entry fell with the pulse flag');

        Feature::activate('pulse');
        Feature::flushCache();
    }

    /**
     * The health sidebar entry, same two gates. The permissionless case needs
     * a role holding nothing at all — a superadmin would pass every `can()`
     * and render the branch this asserts against.
     */
    public function test_the_health_sidebar_entry_needs_its_permission_and_its_flag(): void
    {
        $this->login('admin');
        $this->assertStringContainsString('fa-stethoscope', $this->sidebar(), 'admin with the flag on must see the entry');

        Feature::deactivate('health');
        Feature::flushCache();
        $this->assertStringNotContainsString(
            'fa-stethoscope',
            $this->sidebar(),
            'flag off must drop the health entry even for a permission holder'
        );
        // The other entry stays: `pulse` is a separate switch.
        $this->assertStringContainsString('fa-heart-pulse', $this->sidebar(), 'the pulse entry fell with the health flag');

        Feature::activate('health');
        Feature::flushCache();
    }

    public function test_both_entries_are_absent_for_a_role_holding_no_permissions(): void
    {
        $viewer = $this->login('user');

        $this->assertFalse($viewer->can('pulse.view'), 'precondition: user role holds pulse.view?');
        $this->assertFalse($viewer->can('health.view'), 'precondition: user role holds health.view?');

        $html = $this->sidebar();

        $this->assertStringNotContainsString('fa-heart-pulse', $html, 'a permissionless role was shown the Pulse entry');
        $this->assertStringNotContainsString('fa-stethoscope', $html, 'a permissionless role was shown the health entry');
        // And the sidebar itself still renders — the entries are filtered,
        // not the page.
        $this->assertStringContainsString('Dashboard', $html);
    }

    /**
     * The rendered dashboard, so an assertion cannot pass on the page body.
     *
     * Reuses the already-authenticated user: a second `actingAs` mid-test
     * swaps the identity the composer reads from `Auth::user()`.
     */
    private function sidebar(): string
    {
        return $this->get(route('dashboard'))->assertOk()->getContent();
    }
}
