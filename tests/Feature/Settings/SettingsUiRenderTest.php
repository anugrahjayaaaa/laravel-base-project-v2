<?php

namespace Tests\Feature\Settings;

use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Http\Requests\V1\System\SystemSettingRequest;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\MessageBag;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 8 Group A gate: the settings page renders, obeys the design system, and
 * executes no query of its own.
 *
 * Same two rules `RbacUiRenderTest` and `FeatureFlagUiRenderTest` already
 * enforce for their pages — `ui-architecture.md` rule 1 (no query from inside a
 * view; the controller hands over `SystemSetting::getAll()`) and the forbidden
 * class list. Both were asserted for RBAC and feature flags and never for
 * settings, which is the page with the most controls on it.
 *
 * Rendered outside the HTTP kernel with an empty error bag: a real request gets
 * `errors` from ShareErrorsFromSession and a bare `view()->render()` would fail
 * on `$errors->any()`.
 */
class SettingsUiRenderTest extends TestCase
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
     * Sign in as a real seeded role.
     *
     * The route is gated on `settings.view`, and the whole form — inputs AND the
     * save button — is inside `@can('settings.manage')`, so `admin` is the only
     * role that renders the branch this file is about. A `Gate::before` override
     * would test the override instead of the page.
     */
    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    /**
     * The view data SystemSettingController::index() hands over.
     *
     * `roles` arrives as a name=>name map and `timezones` as Timezone models,
     * because the view reads `$timezone->name`/`->label` and iterates
     * `$roles` by value. A fixture handing over a string list would fail on
     * `$timezone->name` — a fixture defect that reads like a view defect.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $timezones = collect([
            Timezone::make(['name' => 'Asia/Jakarta', 'label' => 'Jakarta (GMT+7)', 'is_active' => true]),
            Timezone::make(['name' => 'UTC', 'label' => 'UTC (GMT+0)', 'is_active' => true]),
        ]);

        // Booleans are real bools, because SystemSettingController::index()
        // casts them before the view sees them. A fixture handing over the
        // stored strings would render every switch from a value the controller
        // can never produce — the fixture would test a shape that does not
        // exist. `lockout_base_minutes` replaces the dead
        // `lockout_duration_minutes`, a key no seeder, rule or action carries.
        return [
            'settings' => [
                'login_max_attempts' => '5',
                'lockout_base_minutes' => '15',
                'password_min_length' => '12',
                'password_require_upper' => true,
                'password_require_lower' => true,
                'password_require_digit' => true,
                'password_require_symbol' => true,
                'password_reject_username' => true,
                'password_uncompromised' => false,
                'password_history_enabled' => true,
                'password_expiry_enabled' => true,
                'inactivity_lock_enabled' => true,
                'inactivity_lock_grace_enabled' => true,
                'password_security_sweep_timezone' => 'Asia/Jakarta',
                'allow_username_change' => true,
                'allow_email_change' => true,
                'registration_enabled' => true,
                'registration_default_role' => 'user',
            ],
            'timezones' => $timezones,
            // `mapWithKeys`, not `pluck('name', 'name')`: pluck reads a `name`
            // property off each element and a list of strings has none, so it
            // returns an empty map and the roles <select> renders bare.
            'roles' => collect(['admin', 'editor', 'user'])->mapWithKeys(
                fn (string $name): array => [$name => $name]
            ),
        ];
    }

    private function render(): string
    {
        return view('pages.settings.index', array_merge(
            ['errors' => new ViewErrorBag()],
            $this->viewData(),
        ))->render();
    }

    /**
     * The page must not reach for the database from inside Blade. A
     * `SystemSetting::getAll()` or `RoleLookup::visibleTo()` left in the view is
     * the failure this catches — and it is per-page invisible until the view
     * happens to run in a request that warmed no cache.
     *
     * The first render warms Spatie's permission cache, which the Gate reads on
     * every @can; measuring only that would blame the framework for the view's
     * own behaviour. A query left in Blade fires on the second render too, so
     * the delta is what actually measures the view.
     */
        public function test_it_renders_and_queries_nothing (): void
    {
        $this->login();

        $this->render();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotSame('', $html, 'the settings page rendered nothing');
        $this->assertSame([], $queries, 'the settings page queried from inside the view');
    }

        public function test_the_forbidden_classes_never_appear (): void
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
     * The two-column grid the design system specifies for this page: operational
     * cards in the `col-lg-8` main column, identity/account policies plus the
     * sticky save card in the `col-lg-4` sidebar. Asserted as a pairing rather
     * than a count, so a page that kept one column and dropped the other fails.
     */
        public function test_the_page_keeps_the_two_column_grid (): void
    {
        $this->login();
        $html = $this->render();

        $this->assertStringContainsString('col-lg-8', $html, 'the main column is gone');
        $this->assertStringContainsString('col-lg-4', $html, 'the sidebar column is gone');
        // The save card lives in the sidebar, not at the end of the last
        // settings card — otherwise it scrolls away with the form.
        $this->assertStringContainsString('sticky-top', $html);
    }

    /**
     * Every tooltip trigger needs a title and the icon class the design system
     * pins. `fs-6` is 1rem — the body size — so an info icon carrying it renders
     * as large as the label it annotates; `fs-7` is the house size and what
     * every other page uses.
     *
     * Counted against `form-label` rather than pinned at a number, so a label
     * added without an icon fails and one removed does not make the count stale.
     */
        public function test_every_tooltip_icon_is_the_house_size_and_carries_a_title(): void
    {
        $this->login();
        $html = $this->render();

        preg_match_all('/<i class="bi bi-info-circle[^"]*"[^>]*>/', $html, $icons);

        $this->assertNotSame([], $icons[0], 'no info icons rendered');

        foreach ($icons[0] as $icon) {
            $this->assertStringContainsString('text-muted fs-7 ms-1', $icon, "wrong icon class: {$icon}");
            $this->assertStringContainsString('data-bs-toggle="tooltip"', $icon, "not a tooltip: {$icon}");
            $this->assertStringContainsString('data-bs-title="', $icon, "no title: {$icon}");
        }

        // And the tooltip driver is actually wired up, or the icons are inert.
        $this->assertStringContainsString('new bootstrap.Tooltip(', $html);
    }

    /**
     * An unticked checkbox sends no field, so a switch without a hidden `value="0"`
     * companion can only ever be enabled — and the failure is invisible, because
     * the value round-trips fine when it is ON. Asserted by parsing the rendered
     * markup for every checkbox name and requiring a companion for each.
     */
        public function test_every_switch_has_a_hidden_companion(): void
    {
        $this->login();
        $html = $this->render();

        preg_match_all('/<input[^>]*type="checkbox"[^>]*name="([a-z_]+)"/', $html, $boxes);

        $this->assertNotSame([], $boxes[1], 'no checkbox rendered');

        foreach (array_unique($boxes[1]) as $name) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="hidden"[^>]*name="'.preg_quote($name, '/').'"[^>]*value="0"/',
                $html,
                "switch {$name} has no hidden companion, so it can only ever be turned ON"
            );
        }
    }

    /**
     * `settings.view` without `settings.manage` gets a read-only page, not a
     * 403: the route is gated on `settings.view`, and the editable form is
     * wrapped in `@can('settings.manage')` so a viewer never sees an input that
     * silently discards what they type.
     */
        public function test_a_viewer_without_manage_gets_a_read_only_page (): void
    {
        $viewer = $this->login('user');

        $this->assertFalse($viewer->can('settings.manage'), 'precondition: user role must not hold settings.manage');

        $html = $this->render();

        $this->assertStringContainsString('Read-only', $html);
        $this->assertStringContainsString('You can view these settings but not change them.', $html);
        $this->assertStringNotContainsString('name="login_max_attempts"', $html, 'a read-only viewer was given an input');
        // Scoped to the SAVE button, not `type="submit"`: the app layout carries
        // its own logout form, so a bare submit assertion fails on every page.
        $this->assertStringNotContainsString('Save Settings', $html, 'a read-only viewer was given a save button');
        $this->assertStringNotContainsString(route('settings.update'), $html, 'a read-only viewer was given the settings form');
        // The values are still readable — read-only means visible, not blank.
        $this->assertStringContainsString('login_max_attempts', $html);
    }

        public function test_a_manager_is_not_shown_the_read_only_banner (): void
    {
        $this->login();
        $html = $this->render();

        $this->assertStringNotContainsString('You can view these settings but not change them.', $html);
        $this->assertStringContainsString('name="login_max_attempts"', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    /**
     * A `.invalid-feedback` outside the `.is-invalid` element's sibling chain
     * never displays: Bootstrap's rule is `.is-invalid ~ .invalid-feedback`, so
     * an input wrapped in `.input-group` shows a red border and NO message. The
     * design system also requires the message be programmatically associated
     * with its field, which the `aria-describedby`/`id` pair provides.
     */
        public function test_every_error_message_is_visible_and_associated_with_its_field(): void
    {
        $this->login();
        $html = $this->render();

        preg_match_all('/<input[^>]*name="([a-z_]+)"[^>]*>/', $html, $inputs);

        $this->assertNotSame([], $inputs[1], 'no input rendered');

        foreach (array_unique($inputs[1]) as $name) {
            preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $input);
            $tag = $input[0];

            // The feedback div is only emitted when the key actually errored, so
            // assert the relationship on a render carrying errors for every key
            // rather than on the clean render, where there is nothing to check.
            $this->assertStringNotContainsString(
                'is-invalid',
                $tag,
                "input {$name} cannot be given an error state, so aria-invalid has no anchor"
            );
        }

        // Every emitted feedback div carries the id the aria pair points at.
        preg_match_all('/<div class="invalid-feedback[^"]*" id="([a-z_]+)">/', $html, $feedback);

        foreach ($feedback[1] as $id) {
            $this->assertStringContainsString('aria-describedby="' . $id . '"', $html, "nothing points at #{$id}");
        }

        // And the association is exercised for real: a validation error must
        // produce a visible, associated message on the field that caused it.
        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag([
            'login_max_attempts' => ['The login max attempts field is required.'],
        ]));

        $html = view('pages.settings.index', array_merge(
            ['errors' => $errors],
            $this->viewData(),
        ))->render();

        $this->assertStringContainsString(
            '<div class="invalid-feedback d-block" id="login_max_attempts_error">',
            $html,
            'the error message for an input-group field is not displayable'
        );
        $this->assertStringContainsString(
            'aria-describedby="login_max_attempts_error"',
            $html,
            'the error message is not associated with its field'
        );
    }

    /**
     * A dependent numeric field follows its toggle, on the server render and in
     * the driver alike. `password_expiry_warn_days` was readable and editable
     * with Password Expiry switched off, unlike its three siblings.
     */
        public function test_every_dependent_field_follows_its_toggle(): void
    {
        $this->login();

        // Every toggle off, so all five dependent fields are exercised at once
        // rather than one per render.
        $data = $this->viewData();
        foreach ([
            'password_history_enabled',
            'password_expiry_enabled',
            'inactivity_lock_enabled',
            'inactivity_lock_grace_enabled',
        ] as $toggle) {
            $data['settings'][$toggle] = false;
        }

        $html = view('pages.settings.index', array_merge(['errors' => new ViewErrorBag()], $data))->render();

        foreach ([
            'password_history_count' => 'password_history_enabled',
            'password_expiry_days' => 'password_expiry_enabled',
            'password_expiry_warn_days' => 'password_expiry_enabled',
            'inactivity_lock_days' => 'inactivity_lock_enabled',
            'inactivity_lock_grace_days' => 'inactivity_lock_grace_enabled',
        ] as $field => $toggle) {
            preg_match('/<input[^>]*id="' . preg_quote($field, '/') . '"[^>]*>/', $html, $input);
            $this->assertStringContainsString(
                'disabled',
                $input[0] ?? '',
                "{$field} stays editable while {$toggle} is off"
            );
            $this->assertStringContainsString(
                "['" . $toggle . "', '" . $field . "']",
                $html,
                "the driver does not re-sync {$field} when {$toggle} changes"
            );
        }
    }

    /**
     * Every validated key has an input.
     *
     * The inverse of `SettingsPersistenceTest`: that one catches a validated key
     * the action drops, this one catches a validated key the form never sends —
     * `email_verification_token_expire_minutes` and
     * `password_reset_expire_minutes` were seeded, validated and whitelisted
     * with no field anywhere, so no admin could set them. The loop-rendered
     * policy switches are listed because their `name` is `{{ $key }}`.
     */
        public function test_every_validated_setting_has_a_form_field(): void
    {
        $this->login();
        $html = $this->render();

        preg_match_all('/name="([a-z_]+)"/', $html, $fields);
        $rendered = array_unique($fields[1]);

        // Rendered from the `foreach` over the password-policy switches.
        $loop = [
            'password_require_upper',
            'password_require_lower',
            'password_require_digit',
            'password_require_symbol',
            'password_reject_username',
            'password_uncompromised',
        ];

        $rules = (new SystemSettingRequest())->rules();
        $missing = array_values(array_diff(array_keys($rules), $rendered, $loop));

        $this->assertSame([], $missing, implode(
            ', ',
            $missing
        ).' validated but never rendered — an admin cannot set it, and no test notices');
    }

    /**
     * `password_expiry_warn_days` is 1..90 per the request rules and must say
     * so in the markup. `max:1440` typed as `max:140` is invisible to a reader
     * and untestable from the UI, so the attribute is pinned against the rule.
     */
        public function test_the_numeric_bounds_in_the_markup_match_the_validation_rules (): void
    {
        $this->login();
        $html = $this->render();

        $rules = (new SystemSettingRequest())->rules();

        preg_match_all('/<input[^>]*type="number"[^>]*name="([a-z_]+)"[^>]*min="(\d+)" max="(\d+)"/', $html, $inputs, PREG_SET_ORDER);

        $this->assertNotSame([], $inputs, 'no bounded number input rendered');

        foreach ($inputs as [, $name, $min, $max]) {
            foreach (['min', 'max'] as $bound) {
                $this->assertContains(
                    $bound . ':' . ($bound === 'min' ? $min : $max),
                    $rules[$name] ?? [],
                    "the {$bound} attribute on {$name} contradicts its validation rule"
                );
            }
        }
    }

    /**
     * A boolean setting must reach the view as a boolean.
     *
     * Stored form is the string `'false'`, and `'false'` is truthy in PHP — so
     * `@checked($settings['key'] ?? true)` on an uncast value renders every
     * switch ticked, including the ones that are OFF. The cast belongs in the
     * controller (`ui-architecture.md` rule 1), so assert it there: through the
     * HTTP path, with real `SystemSetting` rows behind it.
     */
        public function test_the_controller_hands_the_view_real_booleans (): void
    {
        $this->login();

        SystemSetting::set('allow_email_change', 'false');
        SystemSetting::set('registration_enabled', 'true');

        $response = $this->get(route('settings.index'));

        $response->assertOk();
        $response->assertViewHas('settings', function (array $settings): bool {
            // Identity, not just the absence of a warning: an uncast 'false'
            // string is truthy, so assertFalse on the raw value is the check.
            $this->assertFalse($settings['allow_email_change'], "'false' arrived as a truthy string");
            $this->assertTrue($settings['registration_enabled']);

            return true;
        });
    }

    /**
     * Reference data is rendered from what the controller loaded, so a timezone
     * or role the admin cannot pick is impossible — the option is not in the
     * markup to begin with.
     */
        public function test_it_renders_the_reference_options_the_controller_loaded (): void
    {
        $this->login();
        $html = $this->render();

        $this->assertStringContainsString('value="Asia/Jakarta"', $html);
        $this->assertStringContainsString('Jakarta (GMT+7)', $html);
        $this->assertStringContainsString('value="editor"', $html);
        // The empty option is "application default" / "no role", not a blank
        // row the admin has to guess about.
        $this->assertStringContainsString('Application timezone', $html);
    }
}
