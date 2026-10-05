<?php

namespace Tests\Feature\Notification;

use App\Http\Controllers\Web\V1\NotificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 Group A gate (P9-A3): both notifications pages render, obey the design
 * system, and execute no query of their own.
 *
 * Same two rules `SettingsUiRenderTest` and `FeatureFlagUiRenderTest` already
 * enforce for their pages — `ui-architecture.md` rule 1 (no query from inside a
 * view; the controller hands over every variable) and the forbidden class list.
 * Neither was ever asserted for the notifications pages.
 *
 * Rendered outside the HTTP kernel with an empty error bag: a real request gets
 * `errors` from ShareErrorsFromSession and a bare `view()->render()` would fail
 * on `@error`.
 *
 * PERMISSION FIXTURE — a `Gate::before` override, NOT seeded roles, and that is
 * deliberate. `notifications.view` / `.manage` / `.send_test` are seeded in P9-D1
 * and do not exist yet, so a `RoleSeeder` role cannot hold them and the whole
 * file would render the read-only branch for every case. `Gate::before` returning
 * `null` defers to Spatie, so this answers only the notifications permissions
 * under test and leaves every other gate alone — return `false` instead and it
 * denies every check in the app. When P9-D1 lands, replace this with real seeded
 * roles and assert the precondition the way `FeatureFlagUiRenderTest` does.
 */
class NotificationUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // The `notifications` flag is fail-closed on the `database` store: the
        // routes carry `feature:notifications`, and a slug with no row in
        // `features` reads as OFF — so without this every HTTP-path assertion in
        // this file is a 403 measuring the flag, not the page.
        $this->seed(\Database\Seeders\FeatureFlagSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // null = no opinion, so Spatie still decides everything that is not a
        // notifications.* permission. Captured by reference so each test states
        // exactly which permissions the viewer holds.
        $this->granted = [];
        Gate::before(function (User $user, string $ability) {
            return array_key_exists($ability, $this->granted)
                ? $this->granted[$ability]
                : null;
        });
    }

    /**
     * The `notifications.*` abilities the signed-in user holds in this test.
     *
     * An explicit map rather than one boolean, because the send-test card is
     * gated SEPARATELY from manage (P9-D1) — a single flag cannot render the
     * combination that proves the two gates are independent.
     *
     * @var array<string, bool>
     */
    private array $granted;

    /** Grant every notifications permission (the admin of this module). */
    private function grantAll(): void
    {
        $this->granted = [
            'notifications.view' => true,
            'notifications.manage' => true,
            'notifications.send_test' => true,
        ];
    }

    /** Grant view only — the read-only branch of both pages. */
    private function grantViewOnly(): void
    {
        $this->granted = ['notifications.view' => true];
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * The view data NotificationController::index() hands over.
     *
     * `mail_password` is deliberately absent: the controller must never put a
     * credential in view data, and the page reports stored-or-not through
     * `$hasPassword` instead. A fixture that included it would let a view echo
     * the SMTP password into the markup without any test turning red.
     *
     * @return array<string, mixed>
     */
    private function indexData(): array
    {
        return [
            'settings' => [
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.example.com',
                'mail_port' => '587',
                'mail_encryption' => 'smtp',
                'mail_username' => 'no-reply@example.com',
                'mail_from_address' => 'no-reply@example.com',
                'mail_from_name' => 'Laravel Base Project',
            ],
            'mailers' => ['smtp' => 'Smtp', 'log' => 'Log'],
            'hasPassword' => true,
            'updateUrl' => '/notifications',
            'sendTestUrl' => '/notifications/test-mail',
        ];
    }

    /**
     * The view data NotificationController::channels() hands over.
     *
     * `enabled` is a REAL bool, because the controller casts before the view sees
     * it. A fixture handing over the stored strings would render every switch
     * from a value the controller can never produce.
     *
     * @return array<string, mixed>
     */
    private function channelsData(): array
    {
        return [
            'channels' => [
                ['key' => 'in_app', 'label' => 'In-App', 'description' => 'Show the notification in the in-app inbox badge.', 'enabled' => true],
                ['key' => 'mail', 'label' => 'Mail', 'description' => 'Send the notification to the account email address.', 'enabled' => true],
                ['key' => 'database', 'label' => 'Database', 'description' => 'Persist the notification so it can be listed and marked as read.', 'enabled' => false],
            ],
            'updateUrl' => '/notifications/channels',
        ];
    }

    private function renderIndex(): string
    {
        return view('pages.notifications.index', array_merge(
            ['errors' => new ViewErrorBag()],
            $this->indexData(),
        ))->render();
    }

    private function renderChannels(): string
    {
        return view('pages.notifications.channels', array_merge(
            ['errors' => new ViewErrorBag()],
            $this->channelsData(),
        ))->render();
    }

    /**
     * @param  callable(string):string  $render
     */
    private function assertRendersWithoutQuerying(string $label, callable $render): void
    {
        // Warm Spatie's permission cache first: the Gate reads it on every
        // @can, and counting only the first render would blame the framework
        // for the view's own behaviour. A query left in Blade fires on the
        // second render too, so the delta is what measures the view.
        $render();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotSame('', $html, "the {$label} page rendered nothing");
        $this->assertSame([], $queries, "the {$label} page queried from inside the view");
    }

    #[Test]
    public function the_mail_configuration_page_renders_and_queries_nothing(): void
    {
        $this->login();
        $this->grantAll();
        $this->assertRendersWithoutQuerying('notifications.index', fn (): string => $this->renderIndex());
    }

    #[Test]
    public function the_channels_page_renders_and_queries_nothing(): void
    {
        $this->login();
        $this->grantAll();
        $this->assertRendersWithoutQuerying('notifications.channels', fn (): string => $this->renderChannels());
    }

    /**
     * The two-column grid the brief specifies for the mail page: the SMTP form in
     * the `col-lg-8` main column, the send-test card in the `col-lg-4` sidebar.
     * Asserted as a pairing, so a page that kept one column and dropped the other
     * fails rather than passing on a stray `col-lg-8` somewhere.
     */
    #[Test]
    public function the_mail_page_keeps_the_two_column_grid(): void
    {
        $this->login();
        $this->grantAll();
        $html = $this->renderIndex();

        $this->assertStringContainsString('col-lg-8', $html, 'the SMTP form column is gone');
        $this->assertStringContainsString('col-lg-4', $html, 'the send-test sidebar column is gone');

        // Both columns carry the card skeleton the design system pins.
        $this->assertSame(3, substr_count($html, 'card border-0 shadow-sm mb-4'));
    }

    /**
     * Every card footer on the mail page uses the theme-adaptive background.
     * `bg-white` / `bg-light` are the two forbidden classes, and on the mail page
     * a `bg-white` footer is a white bar in dark mode — visible, not cosmetic.
     */
    #[Test]
    public function the_forbidden_classes_never_appear(): void
    {
        $this->login();
        $this->grantAll();

        foreach (['notifications.index' => $this->renderIndex(), 'notifications.channels' => $this->renderChannels()] as $page => $html) {
            $this->assertDoesNotMatchRegularExpression('/\bbg-white\b/', $html, "{$page} uses bg-white");
            $this->assertDoesNotMatchRegularExpression('/\bbg-light\b/', $html, "{$page} uses bg-light");
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\bcard-body\b(?! p-4)[^"]*"/',
                $html,
                "{$page} has a card-body without p-4"
            );
        }

        // And the footer itself is the pinned class list, not just free of the
        // two banned ones — a footer with no background at all passes the negative
        // assertions above and still renders wrong.
        $this->assertStringContainsString(
            'card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2',
            $this->renderIndex(),
            'the SMTP card footer is not the design-system footer'
        );
    }

    /**
     * `filter_var` in Blade is how a boolean setting ends up wrong. Stored form is
     * the string `'false'`, and `'false'` is truthy in PHP — so a view that casts
     * lazily (or not at all) renders every switch ticked, including the OFF ones,
     * and the failure is invisible because the round trip looks fine while ON.
     * The cast belongs in the controller (`SystemSettingController::index()`).
     *
     * Scanned across the whole notifications view directory with Finder, and the
     * file count asserted: PHP's `glob()` does not treat `**` as recursive, so a
     * naive scan reads almost nothing and the ban passes vacuously.
     */
    #[Test]
    public function no_view_casts_booleans_with_filter_var(): void
    {
        $files = \Symfony\Component\Finder\Finder::create()
            ->files()
            ->in(resource_path('views/pages/notifications'))
            ->name('*.blade.php');

        $seen = 0;

        foreach ($files as $file) {
            $seen++;

            // Blade comments stripped first — a comment that documents the ban
            // names the call it forbids and trips itself.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            $this->assertDoesNotMatchRegularExpression(
                '/filter_var\s*\(/',
                $source,
                "{$file->getFilename()} casts a boolean in Blade — do it in the controller"
            );
        }

        $this->assertSame(2, $seen, 'the view scanner read the wrong number of files — the ban above is vacuous');
    }

    /**
     * No query from inside either view, asserted on the SOURCE as well as by
     * counting queries at runtime. The runtime count is the real proof; this is
     * the cheap guard that names the offender, because a query behind a cache
     * hit is invisible to the count.
     */
    #[Test]
    public function no_view_reaches_for_a_model_or_a_setting(): void
    {
        foreach (['index', 'channels'] as $page) {
            $path = resource_path("views/pages/notifications/{$page}.blade.php");
            // Blade comments stripped first: a comment documenting "no queries in
            // Blade" names the calls it forbids and trips itself.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));

            foreach ([
                '/SystemSetting::/',
                '/Notification::/',
                '/DatabaseNotification/',
                '/\bDB::/',
                '/config\s*\(/',
            ] as $forbidden) {
                $this->assertDoesNotMatchRegularExpression(
                    $forbidden,
                    $source,
                    "pages/notifications/{$page} reaches into the app instead of reading a variable"
                );
            }
        }
    }

    /**
     * An unticked checkbox sends no field, so a switch without a hidden
     * `value="0"` companion can only ever be enabled — and the failure is
     * invisible, because the value round-trips fine while it is ON. Asserted by
     * parsing the rendered markup for every checkbox name and requiring a
     * companion for each.
     */
    #[Test]
    public function every_channel_switch_has_a_hidden_companion(): void
    {
        $this->login();
        $this->grantAll();
        $html = $this->renderChannels();

        preg_match_all('/<input[^>]*type="checkbox"[^>]*name="([a-z_]+)"/', $html, $boxes);

        $this->assertNotSame([], $boxes[1], 'no channel switch rendered');

        foreach (array_unique($boxes[1]) as $name) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="hidden"[^>]*name="'.preg_quote($name, '/').'"[^>]*value="0"/',
                $html,
                "switch {$name} has no hidden companion, so it can only ever be turned ON"
            );

            // The companion must precede the checkbox, not follow it: browsers
            // submit both in document order, so a trailing hidden input overwrites
            // the '1' the checkbox just contributed.
            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="hidden"[^>]*name="'.preg_quote($name, '/').'"[^>]*value="0"[^>]*>\s*<input[^>]*type="checkbox"[^>]*name="'.preg_quote($name, '/').'"/',
                $html,
                "switch {$name} does not have its hidden companion directly BEFORE it"
            );
        }

        // And each checkbox declares value="1", so the pair always submits a clean
        // 0/1 for the boolean rule (convention §4d).
        preg_match_all('/<input[^>]*type="checkbox"[^>]*name="[a-z_]+"[^>]*>/', $html, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('value="1"', $tag, "a checkbox has no value=\"1\": {$tag}");
        }
    }

    /**
     * A centred switch needs the cell AND the wrapper. `.form-switch` is a
     * block-level box with the input positioned inside its own left edge, so
     * `text-center` on the `<td>` alone centres the wrapper and the control stays
     * hard left. Asserting both halves, or the guard passes on a page that is
     * still off-centre.
     */
    #[Test]
    public function the_channel_switches_are_centred(): void
    {
        $this->login();
        $this->grantAll();
        $html = $this->renderChannels();

        $this->assertSame(3, substr_count($html, '<td class="text-center">'), 'a channel cell is not centred');
        $this->assertSame(3, substr_count($html, 'form-switch d-inline-flex'), 'a switch wrapper is not inlined');

        // Colour is not the only state indicator (design-system §Accessibility):
        // the aria-label names the channel, so the switch is not read as an
        // anonymous "checkbox, ticked" out of context.
        foreach (['in_app', 'mail', 'database'] as $key) {
            $this->assertStringContainsString(
                "aria-label=\"{$this->labelFor($key)} notifications\"",
                $html,
                "the {$key} switch has no accessible label naming its channel"
            );
        }
    }

    private function labelFor(string $key): string
    {
        return ['in_app' => 'In-App', 'mail' => 'Mail', 'database' => 'Database'][$key];
    }

    /**
     * Every table pins its columns with a <colgroup>. Auto layout sizes each
     * column from its OWN content, so a Label column drifting with the longest
     * description is exactly what the pin prevents. Asserted per table, because a
     * first-table-only assertion stays green through a second card missing it.
     */
    #[Test]
    public function every_channels_table_pins_its_columns(): void
    {
        $this->login();
        $this->grantAll();
        $html = $this->renderChannels();

        $tables = substr_count($html, '<table');
        $this->assertSame(1, $tables, 'precondition: the editable branch renders one table');

        $this->assertSame($tables, substr_count($html, 'table-fixed'));
        $this->assertSame($tables, substr_count($html, '<colgroup'));

        // The read-only branch's table must pin its columns identically, or the
        // same page renders two different column layouts depending on permission.
        $this->grantViewOnly();
        $readOnly = $this->renderChannels();
        $this->grantAll();

        $this->assertSame(1, substr_count($readOnly, '<table'), 'precondition: the read-only branch renders one table');
        $this->assertSame(1, substr_count($readOnly, 'table-fixed'));
        $this->assertSame(1, substr_count($readOnly, '<colgroup'));

        // And the Status column is the same width in both, so the two branches
        // line up with each other rather than merely each looking tidy.
        $widths = ['<col style="width: 20%">', '<col style="width: 12%">'];
        foreach ($widths as $width) {
            $this->assertStringContainsString($width, $html, "the editable table lost {$width}");
            $this->assertStringContainsString($width, $readOnly, "the read-only table lost {$width}");
        }
    }

    /**
     * `notifications.view` without `notifications.manage` gets a read-only page,
     * not a 403: the editable form is wrapped in `@can('notifications.manage')`
     * so a viewer never sees an input that silently discards what they type.
     *
     * The read-only branch REPLACES the controls rather than disabling them — a
     * disabled input still looks editable and still invites typing.
     */
    #[Test]
    public function a_viewer_without_manage_gets_a_read_only_mail_page(): void
    {
        $this->login();
        $this->grantViewOnly();
        $html = $this->renderIndex();

        $this->assertStringContainsString('Read-only', $html);
        $this->assertStringContainsString('You can view these settings but not change them.', $html);
        $this->assertStringNotContainsString('name="mail_host"', $html, 'a read-only viewer was given an input');
        $this->assertStringNotContainsString('Save Mail Settings', $html, 'a read-only viewer was given a save button');
        // Read-only means visible, not blank.
        $this->assertStringContainsString('mail_host', $html);
    }

    #[Test]
    public function a_viewer_without_manage_gets_no_channel_switches(): void
    {
        $this->login();
        $this->grantViewOnly();
        $html = $this->renderChannels();

        $this->assertStringContainsString('Read-only', $html);
        $this->assertStringContainsString('You can view these channels but not change them.', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html, 'a read-only viewer was given a switch');
        $this->assertStringNotContainsString('Save Channels', $html);
        // Status is a badge, not a control — colour is not the only signal either.
        $this->assertStringContainsString('Enabled', $html);
        $this->assertStringContainsString('Disabled', $html);
    }

    #[Test]
    public function a_manager_is_not_shown_the_read_only_banner(): void
    {
        $this->login();
        $this->grantAll();

        $this->assertStringNotContainsString('You can view these settings but not change them.', $this->renderIndex());
        $this->assertStringContainsString('name="mail_host"', $this->renderIndex());
        $this->assertStringContainsString('type="submit"', $this->renderIndex());

        $this->assertStringNotContainsString('You can view these channels but not change them.', $this->renderChannels());
        $this->assertStringContainsString('type="submit"', $this->renderChannels());
    }

    /**
     * The SMTP password is a credential. It is never in `$settings`, so a view
     * that iterated its own view data could not leak it either — asserted here as
     * "the fixture carries no password", so a future fixture cannot quietly add
     * one and let an echo slip through.
     */
    #[Test]
    public function the_stored_smtp_password_is_never_rendered(): void
    {
        $this->login();
        $this->grantAll();

        $this->assertArrayNotHasKey(
            'mail_password',
            $this->indexData(),
            'the fixture handed the view a credential — that is the leak this guards'
        );

        $html = $this->renderIndex();

        // The field exists, but it is never bound to the stored value.
        $this->assertStringContainsString('name="mail_password"', $html);
        preg_match('/<input[^>]*name="mail_password"[^>]*>/', $html, $input);
        $this->assertStringContainsString('value=""', $input[0] ?? '', 'the password field is bound to a value');
        // And its presence is reported without disclosing anything.
        $this->assertStringContainsString('A password is stored. Leave this empty to keep it.', $html);
    }

    /**
     * The send-test card is gated SEPARATELY from manage (P9-D1): sending mail
     * to an address a user types is an abuse vector, not a subset of configuring
     * the transport. Without this card being its own `@can`, holding
     * `notifications.manage` alone is enough to mail anyone.
     *
     * The gate NARROWS manage — send_test is required on top of it, not an
     * alternative to it. `send_test` alone therefore renders neither the card nor
     * the form: without manage there is no configured transport to test, and
     * without view there is no page to be on.
     */
    #[Test]
    public function the_send_test_card_is_gated_separately_from_manage(): void
    {
        $this->login();

        // manage WITHOUT send_test: the SMTP form renders, the send-test card
        // does not. That gap is the whole point of the separate permission.
        $this->granted = [
            'notifications.view' => true,
            'notifications.manage' => true,
        ];
        $html = $this->renderIndex();

        $this->assertStringContainsString('name="mail_host"', $html, 'the SMTP form should still render');
        $this->assertStringNotContainsString('test_mail_email', $html, 'manage alone must not surface the send-test card');

        // send_test ALONE renders neither — the card requires manage as well, so
        // the gate cannot be satisfied by holding only the narrower permission.
        $this->granted = [
            'notifications.view' => true,
            'notifications.send_test' => true,
        ];
        $html = $this->renderIndex();

        $this->assertStringNotContainsString('test_mail_email', $html, 'send_test alone must not surface the card');
        $this->assertStringNotContainsString('name="mail_host"', $html, 'send_test alone must not grant the edit form');

        // And all three together render both.
        $this->grantAll();
        $html = $this->renderIndex();

        $this->assertStringContainsString('test_mail_email', $html);
        $this->assertStringContainsString('name="mail_host"', $html);
    }

    /**
     * A `.invalid-feedback` outside the `.is-invalid` element's sibling chain
     * never displays: Bootstrap's rule is `.is-invalid ~ .invalid-feedback`, so
     * the message is invisible while the field is red. Asserted by rendering with
     * errors and checking the pairing, the way `SettingsUiRenderTest` does.
     */
    #[Test]
    public function every_error_message_is_visible_and_associated_with_its_field(): void
    {
        $this->login();
        $this->grantAll();

        $errors = new ViewErrorBag();
        $errors->put('default', new \Illuminate\Support\MessageBag([
            'mail_host' => ['The mail host field is required.'],
            'mail_port' => ['The mail port must be between 1 and 65535.'],
        ]));

        $html = view('pages.notifications.index', array_merge(
            ['errors' => $errors],
            $this->indexData(),
        ))->render();

        foreach (['mail_host', 'mail_port'] as $field) {
            $this->assertStringContainsString(
                '<div class="invalid-feedback d-block" id="'.$field.'_error">',
                $html,
                "the error message for {$field} is not displayable"
            );
            $this->assertStringContainsString(
                'aria-describedby="'.$field.'_error"',
                $html,
                "the error message for {$field} is not associated with its field"
            );
            $this->assertStringContainsString(
                'aria-invalid="true"',
                $html,
                'an errored field is not marked aria-invalid'
            );
        }
    }

    /**
     * The pages are reachable through the routes the brief specifies, behind
     * `auth` — a guest gets the login redirect, not the mail configuration.
     */
    #[Test]
    public function both_pages_are_reachable_and_require_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.channels'))->assertRedirect(route('login'));

        $this->login();
        $this->grantAll();

        $this->get(route('notifications.index'))->assertOk();
        $this->get(route('notifications.channels'))->assertOk();
    }

    /**
     * The controller is the only place that assembles view data, and the two
     * methods hand over exactly the variables their views read. Asserted through
     * the HTTP path so a variable renamed in one file and not the other fails
     * here rather than as an undefined-variable fatal on the page.
     */
    #[Test]
    public function the_controller_hands_over_every_variable_its_view_reads(): void
    {
        $this->login();
        $this->grantAll();

        $this->get(route('notifications.index'))->assertViewHasAll([
            'settings', 'mailers', 'hasPassword', 'updateUrl', 'sendTestUrl',
        ]);

        $this->get(route('notifications.channels'))->assertViewHasAll([
            'channels', 'updateUrl',
        ]);

        // And the controller itself must stay a stub: Group A renders, it does
        // not persist. A write here would be Group B/C work landing unasked.
        $this->assertSame(['index', 'channels'], array_values(array_diff(
            get_class_methods(NotificationController::class),
            ['__construct']
        )));
    }
}
