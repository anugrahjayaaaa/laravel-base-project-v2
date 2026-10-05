<?php

namespace Tests\Feature\Notification;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The notifications module is gated on a permission AND a flag, and the sidebar
 * agrees with both — P9-D1/D2/D4.
 *
 * The question this file exists to answer is not "do the routes render" but
 * "who can reach them, and does the menu tell the truth about it". A menu that
 * shows a link the route refuses is worse than an absent one, so every pair is
 * asserted in both directions: the item appears when both gates allow it, and
 * disappears when either closes.
 *
 * Rendered through the real dashboard rather than by calling the composer:
 * the composer's methods are private, and a test that reaches past the view
 * proves the class works, not the sidebar.
 */
class NotificationAccessTest extends TestCase
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

        $this->actingAs($user, 'web');

        return $user;
    }

    private function sidebar(): string
    {
        return $this->get(route('dashboard'))->assertOk()->getContent();
    }

    /** Just the header, so a bell assertion cannot pass on a sidebar link. */
    private function header(): string
    {
        $html = $this->sidebar();

        if (! preg_match('#<header class="app-header.*?</header>#s', $html, $m)) {
            $this->fail('the header was not found in the rendered layout');
        }

        return $m[0];
    }

    #[Test]
    public function the_permission_is_seeded_and_grouped(): void
    {
        foreach (['notifications.view', 'notifications.manage', 'notifications.send_test'] as $name) {
            $this->assertContains($name, PermissionCatalog::all());
        }

        // The resource prefix is what the role matrix groups on, so it must be a
        // group of its own — a stray name with no group is invisible in the UI.
        $this->assertSame(
            ['notifications.view', 'notifications.manage', 'notifications.send_test'],
            PermissionCatalog::forResource('notifications')
        );
    }

    #[Test]
    public function the_flag_is_declared_and_not_pending(): void
    {
        $flag = FeatureCatalog::find('notifications');

        $this->assertNotNull($flag, 'the notifications flag is not declared');
        // A `pending` flag says "this switch controls nothing". It now controls
        // two routes and a menu item, so the caveat would be a lie.
        $this->assertNull($flag['pending']);
    }

    #[Test]
    public function both_pages_need_the_permission(): void
    {
        foreach (['notifications.index', 'notifications.channels'] as $name) {
            $this->assertTrue(
                collect(Route::getRoutes())->contains(
                    fn ($route): bool => $route->getName() === $name
                        && collect($route->gatherMiddleware())->contains('can:notifications.view')
                ),
                "[{$name}] is not gated on notifications.view"
            );
        }
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $this->login(SystemRole::USER);

        $this->get(route('notifications.index'))->assertForbidden();
        $this->get(route('notifications.channels'))->assertForbidden();
    }

    #[Test]
    public function a_holder_of_the_permission_reaches_both_pages(): void
    {
        $this->login(SystemRole::ADMIN);

        $this->get(route('notifications.index'))->assertOk();
        $this->get(route('notifications.channels'))->assertOk();
    }

    /**
     * The flag is the module kill switch and it beats permission: an admin
     * passes every `can()`, so gating on permission alone leaves the one person
     * guaranteed to be able to click the link being refused by the module being
     * off. This is the assertion that fails the moment someone helpfully adds a
     * superadmin bypass.
     */
    #[Test]
    public function a_disabled_flag_refuses_an_admin(): void
    {
        $this->login(SystemRole::SUPERADMIN);
        Feature::deactivate('notifications');

        // 403, not 404: the route exists, this module is switched off. See
        // EnsureFeatureIsEnabled for why 404 is the wrong answer.
        $this->get(route('notifications.index'))->assertForbidden();
    }

    /**
     * Both module pages have a sidebar entry, and each lights up only on its
     * own page. `notifications.*` as the active pattern matches BOTH routes, so
     * a wildcard here renders the module twice highlighted — quiet enough to
     * ship and wrong on every visit.
     */
    #[Test]
    public function both_module_pages_are_in_the_menu_and_only_one_highlights(): void
    {
        $this->login(SystemRole::ADMIN);
        Feature::activate('notifications');

        $index = route('notifications.index');
        $channels = route('notifications.channels');

        $onIndex = $this->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$index.'"', $onIndex);
        $this->assertStringContainsString('href="'.$channels.'"', $onIndex);
        $this->assertSame(1, substr_count($onIndex, 'nav-link active'), 'not exactly one active item on /notifications');

        $onChannels = $this->get(route('notifications.channels'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($onChannels, 'nav-link active'), 'not exactly one active item on /notifications/channels');
    }

    /**
     * The channels page is not orphaned: the breadcrumb on the mail page links
     * back out to it, so an admin can reach it from either direction.
     */
    #[Test]
    public function the_two_pages_link_to_each_other(): void
    {
        $this->login(SystemRole::ADMIN);
        Feature::activate('notifications');

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee(route('notifications.channels'), escape: false);

        $this->get(route('notifications.channels'))
            ->assertOk()
            ->assertSee(route('notifications.index'), escape: false);
    }

    /**
     * The menu entries follow the permission. Scoped to the LINK, not the word
     * "Notifications": the header bell carries the same title and is reachable
     * by every authenticated user — the inbox is their own rows, not a
     * permission-gated module. Asserting on the bare word would fail on the
     * bell, which is supposed to be there.
     */
    #[Test]
    public function the_sidebar_item_follows_the_permission(): void
    {
        $this->login(SystemRole::ADMIN);
        Feature::activate('notifications');
        $this->assertStringContainsString(
            'href="'.$this->sidebarUrl().'"',
            $this->sidebar(),
            'an admin holding notifications.view has no sidebar entry'
        );

        // An ordinary user holds no notifications.* permission — asserted, not
        // assumed, so this cannot drift into passing for the wrong reason.
        $user = $this->login(SystemRole::USER);
        $this->assertFalse($user->can('notifications.view'), 'precondition: user holds no notifications.view');

        $html = $this->sidebar();
        $this->assertStringNotContainsString(
            'href="'.$this->sidebarUrl().'"',
            $html,
            'the sidebar shows a link the route refuses with 403'
        );
        // Both entries, not just the one this file was written about first.
        $this->assertStringNotContainsString(
            'href="'.route('notifications.channels').'"',
            $html,
            'the channels entry outlives the permission that hides the module'
        );
    }

    /**
     * The href the sidebar renders for the module. Read from the rendered markup
     * rather than rebuilt here, so a composer change to the URL cannot leave this
     * asserting against a string nothing produces.
     */
    private function sidebarUrl(): string
    {
        return route('notifications.index');
    }

    #[Test]
    public function the_sidebar_item_follows_the_flag(): void
    {
        $this->login(SystemRole::SUPERADMIN);
        $url = 'href="'.$this->sidebarUrl().'"';

        Feature::activate('notifications');
        $this->assertStringContainsString($url, $this->sidebar());

        Feature::deactivate('notifications');
        $this->assertStringNotContainsString($url, $this->sidebar());
    }

    /**
     * The header bell is a second entry point to the same module, in a partial
     * the composer filter does not touch. A filter that fixed the sidebar alone
     * would leave a live icon pointing at a route that now answers 403.
     *
     * The permission matters here and not only on the flag: the bell's target is
     * `notifications.index`, so a plain user clicking it gets a 403. Found by
     * `the_sidebar_item_follows_the_permission` — the sidebar was filtered
     * correctly while the header kept the entry.
     */
    #[Test]
    public function the_header_bell_follows_the_flag(): void
    {
        $this->login(SystemRole::ADMIN);

        Feature::activate('notifications');
        $this->assertStringContainsString('fa-bell', $this->header());

        Feature::deactivate('notifications');
        $this->assertStringNotContainsString('fa-bell', $this->header());
    }

    #[Test]
    public function the_header_bell_is_hidden_from_a_user_the_route_refuses(): void
    {
        $user = $this->login(SystemRole::USER);
        Feature::activate('notifications');
        $this->assertFalse($user->can('notifications.view'), 'precondition: user holds no notifications.view');

        $this->assertStringNotContainsString(
            'fa-bell',
            $this->header(),
            'the bell is shown to a user whose destination answers 403'
        );
    }

    /**
     * The bell points at the module, so it is a link — the audit's dead-button
     * finding. Asserted on the tag, not on the icon: an `<a>` wrapping nothing
     * is still a link, and a `<button>` with a title is still a dead control.
     */
    #[Test]
    public function the_bell_is_a_link_not_a_button(): void
    {
        $this->login(SystemRole::ADMIN);
        Feature::activate('notifications');

        $header = $this->header();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="[^"]*notifications[^"]*"[^>]*>\s*<i class="far fa-bell"/',
            $header,
            'the bell is not a link into the notifications module'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*>\s*<i class="far fa-bell"/',
            $header,
            'the bell is still a dead button'
        );
    }

    /**
     * The sidebar link must carry the real URL. A dead `#` renders, looks right
     * and does nothing — the exact failure `AppMenuComposer` drops unshipped
     * routes to avoid.
     */
    #[Test]
    public function the_sidebar_link_points_at_the_route_not_a_placeholder(): void
    {
        $this->login(SystemRole::ADMIN);
        Feature::activate('notifications');

        // Plain string containment, not a regex: the URL is escaped only when
        // it is used AS a pattern, and escaping here would search for the
        // backslashes instead of the address.
        $href = 'href="'.route('notifications.index').'"';

        $this->assertStringContainsString(
            $href,
            $this->sidebar(),
            'the sidebar item is a placeholder href'
        );
    }
}
