<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 7 Group A gate: the feature-flags view renders, obeys the design
 * system, and executes no query and no route() of its own.
 *
 * The "no query in a view" rule is asserted by counting queries around the
 * render — the same way `RbacUiRenderTest` does it, for the same reason
 * (`ui-architecture.md` rule 1). The route rule is the one this file exists
 * mostly for: `route('features.toggle')` did not exist when Group A shipped,
 * and a view that calls it is a view that cannot be rendered until Group D
 * lands. Building the URL in the action keeps the render honest.
 *
 * The page has no route until Group D, so it is rendered outside the kernel
 * from the view data `FeatureIndexAction` is contracted to hand over. That is
 * also why the fixture carries `toggle_url`: a fixture that skipped it would
 * pass while the real page broke.
 */
class FeatureFlagUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sign in as a real seeded role.
     *
     * Real roles, not a `Gate::before` override. That override was there while
     * `features.manage` did not exist, so the answer had to come from a
     * variable. It is seeded now, and `Gate::before` returning `null` defers to
     * Spatie — which correctly says an admin DOES hold it. A fixture that
     * pretends otherwise tests the override instead of the page.
     */
    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(\App\Models\RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    /**
     * The view data FeatureIndexAction hands over.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $groups = [
            'Users' => [
                [
                    'slug' => 'users',
                    'label' => 'User Management',
                    'description' => 'Accounts, roles and profile.',
                    'enabled' => true,
                    // An ON flag's toggle turns it OFF.
                    'toggle_url' => '/features/users/toggle?enabled=0',
                ],
                [
                    'slug' => 'pulse',
                    'label' => 'Pulse Dashboard',
                    'description' => '',
                    'enabled' => false,
                    'toggle_url' => '/features/pulse/toggle?enabled=1',
                ],
            ],
            'Monitoring' => [
                [
                    'slug' => 'activity_logs',
                    'label' => 'Activity Logs',
                    'description' => 'Audit trail viewer.',
                    'enabled' => true,
                    'toggle_url' => '/features/activity_logs/toggle?enabled=0',
                ],
            ],
        ];

        $flags = collect($groups)->flatten(1);

        return [
            'featureGroups' => $groups,
            'totalFeatures' => $flags->count(),
            'enabledCount' => $flags->where('enabled', true)->count(),
            'disabledCount' => $flags->where('enabled', false)->count(),
        ];
    }

    /**
     * Render outside the HTTP kernel with an empty error bag — a real request
     * gets `errors` from ShareErrorsFromSession, and a bare render would fail
     * on a line every page shares.
     *
     * No `$manageable` argument: the view asks `auth()->user()?->can()` itself,
     * so manageability is decided by the signed-in role, never by the caller.
     */
    private function render(): string
    {
        return view('pages.features.index', array_merge(['errors' => new ViewErrorBag()], $this->viewData()))
            ->render();
    }

    /**
     * @return array<int, string>
     */
    private function switches(string $html): array
    {
        preg_match_all('/<input\b[^>]*data-bs-target="#confirmModal"[^>]*>/', $html, $matches);

        return $matches[0];
    }

    #[Test]
    public function it_renders_and_queries_nothing(): void
    {
        $this->login();

        // Warm Spatie's permission cache first: the Gate reads it on every
        // @can, and counting only the first render would blame the framework
        // for the view's own behaviour. A query left in Blade fires on the
        // second render too, so the delta is what measures the view.
        $this->render();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotSame('', $html, 'the features page rendered nothing');
        $this->assertSame([], $queries, 'the features page queried from inside the view');
    }

    /**
     * The view must not reference any `features.*` route — none exists until
     * Group D, so a view calling one is a view that cannot be rendered at all.
     *
     * `route('dashboard')` in the breadcrumb is deliberately NOT this rule: it
     * is a navigation link, the same one `pages/roles/index` (9 calls) and
     * `pages/permissions/index` (2) already make. What is forbidden is the
     * view deriving DATA from the router, which is what the toggle URL is.
     */
    #[Test]
    public function it_never_builds_a_features_route_from_the_view(): void
    {
        $this->login();

        $source = file_get_contents(resource_path('views/pages/features/index.blade.php'))
            .file_get_contents(resource_path('views/components/ui/feature-toggle.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/route\(\s*[\'"]features\./',
            $source,
            'a view building a features.* URL is controller logic in a view — the action hands it over'
        );
    }

    #[Test]
    public function the_forbidden_classes_never_appear(): void
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

    #[Test]
    public function the_header_and_metric_strip_render(): void
    {
        $this->login();
        $html = $this->render();

        $this->assertStringContainsString('Feature Flags', $html);
        $this->assertStringContainsString('Manage global module and feature availability', $html);
        // breadcrumb: Dashboard > Feature Flags
        $this->assertStringContainsString('Dashboard', $html);

        foreach (['Features', 'Enabled', 'Disabled', 'Modules'] as $metric) {
            $this->assertStringContainsString($metric, $html, "the {$metric} metric is missing");
        }

        $this->assertGreaterThanOrEqual(
            4,
            substr_count($html, 'card border-0 shadow-sm'),
            'the metric strip is not built from card border-0 shadow-sm'
        );
    }

    /**
     * Every module card's table pins its columns identically.
     *
     * Separate tables default to auto layout, so each sizes its columns from its
     * OWN content — the Key column lands in a different place under Users than
     * under Monitoring and the five columns never line up. The fix is the same
     * <colgroup> on every table plus `table-fixed`, which is what makes those
     * widths authoritative rather than a suggestion content can overrule.
     *
     * Asserted as "every table carries the same colgroup", not "the first table
     * has one": a second card without it is exactly the misalignment this
     * guards, and a first-table-only assertion would stay green through it.
     */
    #[Test]
    public function every_tables_columns_are_pinned_identically(): void
    {
        $this->login();
        $html = $this->render();

        $tables = substr_count($html, '<table');
        $this->assertSame(2, $tables, 'precondition: two module cards render');

        $this->assertSame(
            $tables,
            substr_count($html, 'table-fixed'),
            'a table is missing table-fixed, so its columns are sized by content'
        );
        $this->assertSame(
            $tables,
            substr_count($html, '<colgroup'),
            'a table is missing a colgroup, so its columns are sized by content'
        );

        // The widths themselves: one shared definition, not two similar ones.
        preg_match_all('/<colgroup>(.*?)<\/colgroup>/s', $html, $groups);
        $this->assertNotEmpty($groups[1], 'no colgroup was found to compare');

        $widths = array_map(
            fn (string $group): string => preg_replace('/\s+/', '', $group),
            $groups[1]
        );

        $this->assertCount(
            1,
            array_unique($widths),
            "the tables declare different column widths:\n".implode("\n", $widths)
        );

        // Each pinned column, so a refactor cannot silently drop one.
        foreach (['width:22%', 'width:18%', 'width:12%'] as $width) {
            $this->assertStringContainsString($width, $widths[0], "the {$width} column is gone");
        }
        $this->assertSame(
            2,
            substr_count($widths[0], 'width:12%'),
            'the Toggle column should match the Status column so the switch sits centred in its own space'
        );

        // One header row per table, with the same five columns in both.
        $this->assertSame($tables, substr_count($html, '<thead>'));
        $this->assertSame(
            $tables * 5,
            substr_count($html, '<th scope="col"'),
            'the tables do not declare the same five columns'
        );
    }

    /**
     * The switch is centred, not right-aligned.
     *
     * `text-end` pushed the control to the far edge of its cell — not where the
     * eye looks for one — and left the column reading as empty space. Centring
     * needs BOTH halves: the cell is `text-center` so the wrapper centres, and
     * the wrapper is `d-inline-flex` because `.form-switch` is otherwise a
     * block box with the input positioned inside its left edge. Setting only the
     * cell looks centred and is not.
     */
    #[Test]
    public function the_toggle_column_is_centred(): void
    {
        $this->login();
        $html = $this->render();

        $this->assertStringNotContainsString('text-end', $html, 'the toggle column is right-aligned');
        // `align-middle` is the house convention on every <th> (users 7, roles 2,
        // permissions 2) and this header carries it too, so the pinned string
        // includes it. The count stays 2: two module groups in the fixture.
        $this->assertSame(
            2,
            substr_count($html, '<th scope="col" class="align-middle text-center">Toggle</th>'),
            'the Toggle header is not centred in every table'
        );
        $this->assertSame(
            3,
            substr_count($html, '<td class="text-center">'),
            'a toggle cell is not centred'
        );
        $this->assertSame(
            3,
            substr_count($html, 'form-switch d-inline-flex'),
            'the switch wrapper is not inlined, so a centred cell would not centre it'
        );
    }

    #[Test]
    public function every_flag_row_carries_its_key_and_status(): void
    {
        $this->login();
        $html = $this->render();

        foreach (['users', 'pulse', 'activity_logs'] as $slug) {
            $this->assertStringContainsString($slug, $html, "flag key {$slug} is missing");
        }

        // Colour is not the only state indicator (design-system §Accessibility).
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('Inactive', $html);
    }

    /**
     * The switch is a confirmation-modal trigger, so it has to carry every
     * attribute the shared driver reads. A missing one falls back to "Confirm"
     * in danger red, silently — which is the failure this asserts.
     */
    #[Test]
    public function every_switch_carries_the_attributes_the_modal_driver_reads(): void
    {
        $this->login();
        $switches = $this->switches($this->render());

        $this->assertCount(3, $switches, 'each flag row renders exactly one switch');

        foreach ($switches as $i => $tag) {
            foreach ([
                'data-bs-toggle="modal"',
                'data-bs-target="#confirmModal"',
                'data-action=',
                'data-method="POST"',
                'data-item-name=',
                'data-label=',
            ] as $required) {
                $this->assertStringContainsString($required, $tag, "switch #{$i} is missing {$required}");
            }

            $this->assertStringContainsString('type="checkbox"', $tag, "switch #{$i} is not a checkbox");
            $this->assertStringContainsString('role="switch"', $tag, "switch #{$i} has no role=switch");
            $this->assertStringContainsString('aria-label=', $tag, "switch #{$i} has no accessible label");
            $this->assertStringContainsString('form-check-input', $tag, "switch #{$i} is not a form-switch");
        }
    }

    #[Test]
    public function the_switch_targets_the_intended_new_state(): void
    {
        $this->login();
        $switches = $this->switches($this->render());

        // An ON flag must offer `enabled=0`, an OFF one `enabled=1`. Getting
        // this backwards would make the toggle report the state it was already
        // in, and the modal copy ("Disabling …") would be a lie.
        $this->assertStringContainsString('/features/users/toggle?enabled=0', $switches[0]);
        $this->assertStringContainsString('data-label="Disable"', $switches[0]);
        $this->assertStringContainsString('data-variant="warning"', $switches[0]);

        $this->assertStringContainsString('/features/pulse/toggle?enabled=1', $switches[1]);
        $this->assertStringContainsString('data-label="Enable"', $switches[1]);
        $this->assertStringContainsString('data-variant="success"', $switches[1]);
    }

    /**
     * A viewer without `features.manage` gets badges, not dead switches — the
     * read-only shape `pages/settings` uses, for the same reason: a control
     * that looks editable and silently discards input is worse than none.
     */
    #[Test]
    public function a_viewer_without_manage_gets_badges_and_no_switch(): void
    {
        // `user` holds no permissions at all, so this is a real 403-shaped
        // viewer rather than a stubbed Gate answer.
        $viewer = $this->login('user');
        $this->assertFalse(
            $viewer->can('features.manage'),
            'precondition: the user role must not hold features.manage'
        );

        $html = $this->render();

        $this->assertSame([], $this->switches($html), 'a viewer was given an editable switch');
        $this->assertStringNotContainsString('form-check form-switch', $html);

        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('Inactive', $html);
        $this->assertStringContainsString('You can view these flags but not change them.', $html);
    }

    #[Test]
    public function a_manager_is_not_shown_the_read_only_banner(): void
    {
        $this->login();

        $this->assertStringNotContainsString(
            'You can view these flags but not change them.',
            $this->render()
        );
    }

    /**
     * An empty catalogue is a real state — Phase 7 Group B has not declared a
     * flag yet, and the page must render rather than fatal on `count([])`.
     */
    #[Test]
    public function an_empty_catalogue_renders_an_empty_state(): void
    {
        $this->login();

        $html = view('pages.features.index', [
            'errors' => new ViewErrorBag(),
            'featureGroups' => [],
            'totalFeatures' => 0,
            'enabledCount' => 0,
            'disabledCount' => 0,
        ])->render();

        $this->assertStringContainsString('No feature flags are declared.', $html);
        $this->assertStringNotContainsString('<table', $html, 'an empty catalogue rendered a table');
    }
}
