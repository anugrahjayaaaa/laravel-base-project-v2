<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every named route is declared as a capability, and a capability is on both
 * channels or has a stated reason why it is not (Phase 12, P12-1).
 *
 * ## Why this file exists
 *
 * Phase 9 shipped notifications on both channels and Phase 10 shipped the audit
 * trail on one. Both passes read as complete and the tracker recorded them as
 * such — because "shipped" was judged per channel, and the absence of a
 * capability on the other channel is invisible from inside either one. The audit
 * that found both was a person running `route:list` twice.
 *
 * A check that has to be remembered is a check that gets skipped. This makes it
 * a test.
 *
 * ## Path shape is not a gap
 *
 * `POST /login` and `POST /api/v1/auth/login` are ONE capability with two
 * addresses, and so are `notifications.inbox` and `notifications.inbox.index`.
 * That is why capabilities here are declared by NAME rather than derived from the
 * URI: comparing URIs would report twenty false gaps and get deleted.
 *
 * ## What a failure means
 *
 * A route that is not in the map is a route nobody has decided the channel
 * question for. Adding an endpoint to one channel now fails this test until it
 * is declared — which is the entire point: the question is asked at the moment
 * somebody answers it, not a phase later during a manual sweep.
 *
 * @see docs/planning/phase-12-api-v1.md §1 for the sweep that found the two gaps.
 */
class ChannelParityTest extends TestCase
{
    /**
     * Every named route, by capability.
     *
     * `web` and `api` list the route NAMES on each channel (`api.v1.` stripped
     * from API names). `only` + `why` is required when a capability is
     * deliberately one-sided — a missing reason is itself a finding, so the
     * constructor rejects it below rather than letting it pass quietly.
     *
     * @var array<string, array{web?: array<int, string>, api?: array<int, string>, only?: string, why?: string}>
     */
    private const CAPABILITIES = [
        // ---- Audit trail: both channels (P10-F, phase-12 §1) ----
        'audit reader' => [
            'web' => ['activity-logs.index', 'activity-logs.show'],
            'api' => ['activity-logs.index', 'activity-logs.show'],
        ],
        'audit export' => [
            'web' => ['activity-logs.exports.store', 'activity-logs.exports.download'],
            'api' => ['activity-logs.exports.store', 'activity-logs.exports.download', 'activity-logs.exports.index'],
        ],
        'audit export list refresh' => [
            'only' => 'web',
            'web' => ['activity-logs.exports.recent'],
            'why' => 'A poll fragment the index page swaps in place. It returns part of a page `audit.view` already opens; an API client polls `activity-logs.exports.index` for the same rows.',
        ],

        // ---- Users ----
        'user list' => ['web' => ['users.index'], 'api' => ['users.index']],
        'user read' => ['web' => ['users.show'], 'api' => ['users.show']],
        'user create' => ['web' => ['users.create', 'users.store'], 'api' => ['users.store']],
        'user update' => ['web' => ['users.edit', 'users.update'], 'api' => ['users.update']],
        'user delete' => ['web' => ['users.destroy'], 'api' => ['users.destroy']],
        'user restore' => ['web' => ['users.restore'], 'api' => ['users.restore']],
        'user force delete' => ['web' => ['users.force-delete'], 'api' => ['users.force-delete']],
        'user lock' => ['web' => ['users.lock'], 'api' => ['users.lock']],
        'user unlock' => ['web' => ['users.unlock'], 'api' => ['users.unlock', 'auth.unlock']],
        'user activate' => ['web' => ['users.activate'], 'api' => ['users.activate']],
        'user deactivate' => ['web' => ['users.deactivate'], 'api' => ['users.deactivate']],
        'user bulk action' => ['web' => ['users.bulk-action'], 'api' => ['users.bulk-action']],
        'user email change' => [
            'web' => ['users.request-email-change', 'users.cancel-email-change', 'email.verify-change'],
            'api' => ['users.request-email-change', 'users.cancel-email-change', 'email.verify-change'],
        ],
        'user verification resend' => [
            'web' => ['users.resend-verification'],
            'api' => ['users.resend-verification'],
        ],

        // ---- Roles & permissions ----
        'role list' => ['web' => ['roles.index'], 'api' => ['roles.index']],
        'role create' => ['web' => ['roles.create', 'roles.store'], 'api' => ['roles.store']],
        'role update' => ['web' => ['roles.edit', 'roles.update'], 'api' => ['roles.update']],
        'role delete' => ['web' => ['roles.destroy'], 'api' => ['roles.destroy']],
        'role restore' => ['web' => ['roles.restore'], 'api' => ['roles.restore']],
        'role force delete' => ['web' => ['roles.force-delete'], 'api' => ['roles.force-delete']],
        'role bulk action' => ['web' => ['roles.bulk-action'], 'api' => ['roles.bulk-action']],
        'role read' => [
            'only' => 'api',
            'api' => ['roles.show'],
            'why' => '`roles.edit` IS the detail view on web — the page reads one role and offers its form, so a second read endpoint would be the same facts at two addresses.',
        ],
        'permission list' => ['web' => ['permissions.index'], 'api' => ['permissions.index']],

        // ---- Feature flags ----
        'feature list' => [
            'only' => 'web',
            'web' => ['features.index'],
            'why' => 'No API read endpoint for the flag list. Both ACTIONS on it — toggle and bulk-action — are on both channels, which is the part that carries risk. Declared here so the gap is visible rather than forgotten.',
        ],
        'feature toggle' => ['web' => ['features.toggle'], 'api' => ['features.toggle']],
        'feature bulk action' => ['web' => ['features.bulk-action'], 'api' => ['features.bulk-action']],

        // ---- Settings ----
        'settings read' => ['web' => ['settings.index'], 'api' => ['settings.index']],
        'settings update' => ['web' => ['settings.update'], 'api' => ['settings.update']],

        // ---- Profile ----
        'profile read' => ['web' => ['profile.show'], 'api' => ['profile.show']],
        'profile update' => ['web' => ['profile.update'], 'api' => ['profile.update']],

        // ---- Sessions ----
        'session list' => ['web' => ['sessions'], 'api' => ['sessions']],
        'session logout all' => ['web' => ['sessions.logout-all'], 'api' => ['auth.logout-all']],

        // ---- Notifications ----
        'notification list' => ['web' => ['notifications.index'], 'api' => ['notifications.index']],
        'notification update' => ['web' => ['notifications.update'], 'api' => ['notifications.update']],
        'notification channels' => ['web' => ['notifications.channels'], 'api' => ['notifications.channels.update']],
        'notification test mail' => ['web' => ['notifications.test-mail'], 'api' => ['notifications.test-mail']],
        'notification inbox' => [
            'web' => ['notifications.inbox'],
            'api' => ['notifications.inbox.index'],
        ],
        'notification unread count' => [
            'web' => ['notifications.unread-count'],
            'api' => ['notifications.inbox.unread-count'],
        ],
        'notification read' => ['web' => ['notifications.inbox.read'], 'api' => ['notifications.inbox.read']],
        'notification read all' => ['web' => ['notifications.inbox.read-all'], 'api' => ['notifications.inbox.read-all']],
        'notification detail' => [
            'only' => 'web',
            'web' => ['notifications.inbox.show', 'notifications.preview'],
            'why' => 'The inbox expands a notification in place and previews a channel. Neither is a fact an API client needs; both are presentation of a row the list already returned.',
        ],

        // ---- Auth (form pages are web; the actions are on both) ----
        'auth login' => ['web' => ['login', 'login.submit'], 'api' => ['auth.login']],
        'auth logout' => ['web' => ['logout'], 'api' => ['auth.logout']],
        'auth register' => ['web' => ['register', 'register.submit'], 'api' => ['auth.register']],
        'auth password change' => ['web' => ['password.change', 'password.change.update'], 'api' => ['auth.password.change']],
        'auth password forgot' => ['web' => ['password.request', 'password.email'], 'api' => ['auth.password.forgot']],
        'auth password reset' => ['web' => ['password.reset', 'password.update'], 'api' => ['auth.password.reset']],
        'auth email verification' => [
            'web' => ['verification.notice', 'verification.resend', 'verification.verify'],
            'api' => ['auth.email.resend'],
        ],
        'password expired' => [
            'only' => 'web',
            'web' => ['password.expired'],
            'why' => 'A dead end a password policy redirects to, telling the user their link expired. It carries no fact an API client can act on; the capability that matters is `auth password reset`, which is on both channels.',
        ],

        // ---- Admin dashboards: browser surfaces with no API concept ----
        'dashboard' => [
            'only' => 'web',
            'web' => ['dashboard'],
            'why' => 'A landing page. There is no fact on it that a client cannot read from the list endpoints it aggregates.',
        ],
        'pulse dashboard' => [
            'only' => 'web',
            'web' => ['pulse'],
            'why' => 'Laravel Pulse renders its own UI and owns its own authorization (`pulse.view`). An API wrapper would be a second surface over a third-party dashboard.',
        ],
    ];

    /**
     * Route names that are not application capabilities.
     *
     * Framework and vendor surfaces: the doc generator and Livewire's endpoint
     * are infrastructure, not features, and a parity claim about them is
     * meaningless.
     *
     * @var array<int, string>
     */
    private const NOT_CAPABILITIES = [
        'scramble.dev-tools.asset',
        'scramble.docs.document',
        'scramble.docs.ui',
        'default-livewire.update',
        'livewire.update',
        'livewire.upload-file',
        'livewire.preview-file',
        'storage.local',
        'storage.local.upload',
        'sanctum.csrf-cookie',
    ];

    /**
     * Every named application route is declared as a capability.
     *
     * This is the assertion that catches the next half-phase ship. A new
     * endpoint appears in `route:list`, nobody diffs the tables, and the
     * capability exists on one channel only — the exact shape of the Phase 9 and
     * Phase 10 gaps. Here it fails until it is declared.
     */
    public function test_every_named_route_is_declared_as_a_capability(): void
    {
        [$web, $api] = $this->channelRouteNames();

        $declared = [];

        foreach (self::CAPABILITIES as $capability) {
            $declared = array_merge($declared, $capability['web'] ?? [], $capability['api'] ?? []);
        }

        $undeclared = array_values(array_diff(
            array_unique(array_merge($web, $api)),
            array_unique(array_merge($declared, self::NOT_CAPABILITIES))
        ));

        $this->assertSame(
            [],
            $undeclared,
            "routes nobody has decided the channel question for. Add them to ChannelParityTest::CAPABILITIES "
            .'with both channels, or with `only` + `why` if one-sided: '.implode(', ', $undeclared)
        );
    }

    /**
     * A declared capability's routes all exist.
     *
     * The other direction: a rename that breaks half the map fails here rather
     * than leaving a claim in a test that nothing checks.
     */
    public function test_every_declared_capability_route_exists(): void
    {
        [$web, $api] = $this->channelRouteNames();

        $missing = [];

        foreach (self::CAPABILITIES as $label => $capability) {
            foreach ($capability['web'] ?? [] as $name) {
                if (! in_array($name, $web, true)) {
                    $missing[] = $label.' (web: '.$name.')';
                }
            }

            foreach ($capability['api'] ?? [] as $name) {
                if (! in_array($name, $api, true)) {
                    $missing[] = $label.' (api: '.$name.')';
                }
            }
        }

        $this->assertSame([], $missing, 'declared routes that no longer exist: '.implode(', ', $missing));
    }

    /**
     * A one-sided capability carries a reason.
     *
     * Otherwise "only => web" is a shrug with a type on it, and the next person
     * cannot tell a decision from an oversight.
     */
    public function test_a_one_sided_capability_states_why(): void
    {
        $unexplained = [];

        foreach (self::CAPABILITIES as $label => $capability) {
            if (! isset($capability['only'])) {
                continue;
            }

            if (empty($capability['why'])) {
                $unexplained[] = $label;

                continue;
            }

            // And the reason must match the shape: `only => web` with api routes
            // listed is a contradiction the test should catch.
            $declaredOn = ($capability['web'] ?? []) === [] ? 'api' : 'web';

            if ($declaredOn !== $capability['only']) {
                $unexplained[] = $label.' (says only '.$capability['only'].' but lists routes on '.$declaredOn.')';
            }
        }

        $this->assertSame(
            [],
            $unexplained,
            'one-sided capabilities with no reason, or a reason that contradicts the routes: '.implode(', ', $unexplained)
        );
    }

    /**
     * A two-sided capability is genuinely two-sided.
     *
     * A capability declared with both channels but listing routes on one is the
     * most dangerous entry in the map: it reads as parity and is not. One test
     * over the whole map rather than a data provider, because the assertion is
     * "collect every offender and report them together" — a provider would
     * stop at the first and hide the rest.
     */
    public function test_a_two_sided_capability_has_routes_on_both(): void
    {
        $hollow = [];

        foreach (self::CAPABILITIES as $label => $capability) {
            if (isset($capability['only'])) {
                continue;
            }

            if (($capability['web'] ?? []) === []) {
                $hollow[] = $label.' (no web route)';
            }

            if (($capability['api'] ?? []) === []) {
                $hollow[] = $label.' (no api route)';
            }
        }

        $this->assertSame(
            [],
            $hollow,
            'capabilities declared on both channels but listing routes on one: '.implode(', ', $hollow)
        );
    }

    /**
     * The two route-name sets, `api.v1.` stripped from API names.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function channelRouteNames(): array
    {
        $web = [];
        $api = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if (str_starts_with($name, 'api.v1.')) {
                $api[] = substr($name, strlen('api.v1.'));

                continue;
            }

            // `api/` routes registered without the version prefix would land here
            // and be silently counted as a web capability.
            if (str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $web[] = $name;
        }

        return [array_values(array_unique($web)), array_values(array_unique($api))];
    }
}
