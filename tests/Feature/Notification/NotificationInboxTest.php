<?php

namespace Tests\Feature\Notification;

use App\Support\NotificationAudience;
use App\Support\UnreadNotificationCount;
use App\Models\RoleLookup;
use App\Models\User;
use App\Notifications\AccountStateChangedNotification;
use App\Notifications\RegisterNotification;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Actions\V1\Notification\NotificationAccountStateAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Support\PermissionCatalog;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 9 Group C gate: the inbox shows a user their own notifications and
 * nobody else's, the audience rule routes each event to the right people, and
 * the bell points at the inbox.
 *
 * The inbox carries no permission, which makes user isolation the only thing
 * between two users' data. That is why so much of this file is about one user
 * not being able to touch another's notification — it is the property that has
 * no permission to enforce it.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false in this app, so
        // every POST here would 419 before reaching the thing under test.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(string $role = SystemRole::USER): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    /** A notification in the shape `toArray()` stores it. */
    private function notify(User $user, string $subject = 'Test notification', bool $read = false): string
    {
        $id = (string) str()->uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'database',
            'data' => ['subject' => $subject, 'lines' => ['Something happened.']],
            'read_at' => $read ? now() : null,
        ]);

        return $id;
    }

    /**
     * The inbox is reachable by an ordinary user. No permission is checked on
     * the route, and that is the point: an inbox only its administrators can
     * open is not an inbox.
     */
    #
    public function test_an_ordinary_user_reaches_their_inbox(): void
    {
        $user = $this->login();
        $this->assertFalse($user->can('notifications.view'), 'precondition: holds no notifications.view');

        $this->get(route('notifications.inbox'))->assertOk();
    }

    #
    public function test_the_inbox_shows_the_viewers_notifications(): void
    {
        $user = $this->login();
        $this->notify($user, 'Welcome aboard');

        $this->get(route('notifications.inbox'))
            ->assertOk()
            ->assertSee('Welcome aboard');
    }

    #
    public function test_the_inbox_counts_every_unread_not_just_the_page(): void
    {
        $user = $this->login();

        for ($i = 0; $i < 5; $i++) {
            $this->notify($user, "Notification {$i}");
        }
        $this->notify($user, 'Already read', read: true);

        $this->get(route('notifications.inbox'))
            ->assertOk()
            ->assertViewHas('unreadCount', 5);
    }

    #
    public function test_an_empty_inbox_renders_rather_than_erroring(): void
    {
        $this->login();

        $this->get(route('notifications.inbox'))
            ->assertOk()
            ->assertSee('No notifications yet.');
    }

    /**
     * The isolation that the missing permission makes this module's job.
     *
     * `markAsRead($id)` written the obvious way is `Notification::findOrFail($id)`
     * then `markAsRead()` — which marks ANY user's notification read. The id is
     * a UUID, so it is not guessable, but a logged-in user has a valid UUID from
     * their own inbox to try against this endpoint.
     */
    #
    public function test_a_user_cannot_mark_another_users_notification_read(): void
    {
        $this->login(SystemRole::ADMIN);

        $victim = User::factory()->create(['email_verified_at' => now()]);
        $victimId = $this->notify($victim);

        $this->post(route('notifications.inbox.read', $victimId))->assertRedirect();

        $this->assertNull(
            $victim->notifications()->whereKey($victimId)->first()->read_at,
            "user B marked user A's notification read"
        );
    }

    /** The same hole, through the bulk write — which is the wider of the two. */
    #
    public function test_mark_all_as_read_touches_nobody_elses_notifications(): void
    {
        $this->login(SystemRole::ADMIN);

        $victim = User::factory()->create(['email_verified_at' => now()]);
        $victimId = $this->notify($victim);
        $mineId = $this->notify(auth()->user());

        $this->post(route('notifications.inbox.read-all'))->assertRedirect();

        // The victim's stays unread: this is the hole a bulk write without a
        // scope would open, and it is the whole reason this test exists.
        $this->assertNull(
            $victim->notifications()->whereKey($victimId)->first()->read_at,
            "mark-all-as-read reached user B's notification"
        );

        // And the viewer's own is marked, so the guard is not passing by doing
        // nothing at all.
        $this->assertNotNull(
            auth()->user()->notifications()->whereKey($mineId)->first()->read_at,
            'the viewers own unread notification was not marked'
        );
    }

    #
    public function test_the_viewer_can_mark_their_own_notification_read(): void
    {
        $user = $this->login();
        $id = $this->notify($user);

        $this->post(route('notifications.inbox.read', $id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($user->notifications()->whereKey($id)->first()->read_at);
    }

    #
    public function test_an_already_read_notification_is_reported_not_silently_accepted(): void
    {
        $user = $this->login();
        $id = $this->notify($user, read: true);

        $this->post(route('notifications.inbox.read', $id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #
    public function test_an_unknown_notification_id_is_reported_not_a_500(): void
    {
        $this->login();

        // A stale tab is the ordinary case: the page rendered, the row was
        // deleted or the user already marked it in another tab. `findOrFail`
        // would 500 for something the user did not do wrong.
        $this->post(route('notifications.inbox.read', 'no-such-id'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * The bell's count is cached, so a page refresh costs no query — and the
     * cache is invalidated when a notification ARRIVES, not when the inbox is
     * opened. Invalidating on the open would leave the badge stale until the owner
     * clicked it, which is the number people notice least and complain about
     * most.
     */
    #
    public function test_the_bell_count_is_cached_and_invalidated_when_a_notification_arrives(): void
    {
        $user = $this->login();

        $this->notify($user, 'First');
        $html = $this->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('badge-notification-unread', $html);
        $this->assertStringContainsString('>1</span>', $html);

        // Second render: served from cache, still correct.
        $html = $this->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('>1</span>', $html);

        // A notification arriving on ANOTHER user's request — an admin creating a
        // user, a queued job — must clear this user's cached count.
        $this->notify($user, 'Second');
        UnreadNotificationCount::forget($user);

        $this->assertStringContainsString(
            '>2</span>',
            $this->get(route('dashboard'))->getContent()
        );
    }

    /**
     * The other half of the invalidation: marking read changes the count and no
     * event fires for it. Forgetting only on delivery is how a badge ends up
     * permanently one too high.
     */
    #
    public function test_marking_read_invalidates_the_bell_count(): void
    {
        $user = $this->login();
        $id = $this->notify($user);

        $this->assertStringContainsString('>1</span>', $this->get(route('dashboard'))->getContent());

        $this->post(route('notifications.inbox.read', $id))->assertRedirect();

        $html = $this->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString(
            'badge-notification-unread',
            $html,
            'the bell still shows a count after everything was marked read'
        );
    }

    /**
     * The badge and the list under it are two cache entries, cached by the same
     * class and read from the same page. Invalidation that clears only the count
     * is the worst version of this bug: the badge updates instantly and the list
     * beside it still shows the previous five, which reads as the delivery
     * having failed.
     *
     * Asserted through rendered HTML, because the defect is that the two views
     * disagree — asserting two cache keys would pass with a bug that made the
     * composer read the wrong one.
     */
    #
    public function test_the_bell_list_refreshes_with_the_badge(): void
    {
        $user = $this->login();
        $this->notify($user, 'First notice');

        // Warm both entries, then deliver. `Notification::send` is what fires
        // NotificationSent in production; creating the row directly does not, so
        // the invalidation under test is the one a delivery triggers.
        $this->get(route('dashboard'))->assertOk()->assertSee('First notice');

        $this->notify($user, 'Second notice');
        $this->notify($user, 'Third notice');

        // Firing the event rather than sending: `Notification::fake()` is on for
        // every test in this repository, so a real send writes no row and fires
        // nothing. The event is what production delivery raises, and the listener
        // under test is the one that drops the cache — the transport that raised
        // it is not the subject here.
        event(new NotificationSent($user, new RegisterNotification('someone', 'https://example.test', 60), 'database'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Third notice', $html, 'the list did not pick up a new notification');
        $this->assertStringContainsString('>3</span>', $html, 'the badge and the list disagree on the same page');
    }

    /**
     * The read half. `markAsRead` fires no notification event, so the action has
     * to clear both entries itself.
     *
     * Asserted on the read STATE, not on the row's presence: the dropdown lists
     * the five most recent notifications whether they are read or not, so a row
     * disappearing would be the wrong expectation. What must change is the icon
     * beside it — and with a stale `recent()` entry that icon stays on the unread
     * marker for the five minutes the TTL allows, next to a badge that already
     * dropped.
     */
    #
    public function test_marking_read_clears_the_bell_list_too(): void
    {
        $user = $this->login();
        $id = $this->notify($user, 'Only notice');

        // Warm both entries while the notification is unread.
        $before = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('bi-check2-circle', $before, 'precondition: rendered as unread');

        $this->post(route('notifications.inbox.read', $id))->assertRedirect();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'badge-notification-unread',
            $html,
            'the badge still shows a count after everything was marked read'
        );
        $this->assertStringNotContainsString(
            'bi-check2-circle',
            $html,
            'the dropdown still renders the row as unread after it was marked read'
        );
    }

    /**
     * The `notifications` table has no ceiling on it. The inbox paginates, which
     * is exactly what hides this: every screen that reads the table looks fine
     * while it keeps filling. The scheduled sweep is the only thing that deletes
     * from it.
     *
     * Asserted on the SCHEDULE rather than on the closure, because a pruning
     * query that is never registered passes every test that runs the query by
     * hand — the same shape as the audience rule that shipped with four passing
     * tests and no caller.
     */
    #
    public function test_read_notifications_are_pruned_and_unread_ones_are_not(): void
    {
        $user = $this->login();

        $staleRead = $this->notify($user, 'Read long ago', read: true);
        $freshRead = $this->notify($user, 'Read yesterday', read: true);
        $staleUnread = $this->notify($user, 'Unread for months');

        // Age the rows by writing the timestamps directly: the model casts
        // `created_at`, so a mass update through Eloquent would fight it, and
        // these three rows differ only in WHEN they were read.
        foreach ([$staleRead, $freshRead, $staleUnread] as $id) {
            DB::table('notifications')->where('id', $id)->update([
                'created_at' => now()->subDays(200),
            ]);
        }

        DB::table('notifications')->where('id', $staleRead)->update([
            'read_at' => now()->subDays(200),
        ]);

        $event = $this->scheduledEvent('notification-retention');

        $this->assertNotNull(
            $event,
            'nothing prunes the notifications table; it grows one row per delivery, forever'
        );

        // The event's own callback, run the way the scheduler would — not a
        // query copied into this test, which would keep passing if the sweep were
        // unregistered.
        $event->run(app(Container::class));

        $remaining = DB::table('notifications')->pluck('id')->all();

        $this->assertNotContains($staleRead, $remaining, 'a read notification past the window survived');
        $this->assertContains($freshRead, $remaining, 'a read notification inside the window was deleted');
        $this->assertContains(
            $staleUnread,
            $remaining,
            'an unread notification was deleted — that is a badge the user was never shown'
        );
    }

    /**
     * The registered event with this name, or null.
     *
     * Read out of the schedule rather than inferred from a delete that happened,
     * so a sweep that runs but is never scheduled fails here rather than passing
     * because the test invoked the closure by hand.
     */
    private function scheduledEvent(string $name): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->description === $name) {
                return $event;
            }
        }

        return null;
    }

    #
    public function test_the_bell_count_costs_no_query_on_a_warm_cache(): void
    {
        $user = $this->login();
        $this->notify($user);

        // Warm it.
        $this->get(route('dashboard'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $countQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['query'], 'read_at')
        );

        // Not a general zero-query assertion — the sidebar's own gates legitimately
        // query. This asks the narrower question: does the bell re-read the count
        // on every render?
        $this->assertSame(
            [],
            array_values($countQueries),
            'the bell re-reads the unread count on every page render'
        );
    }

    /**
     * A permission granted DIRECTLY on the person, rather than through a role,
     * still makes them an audience member.
     *
     * Spatie grants both ways and `can()` honours both. The resolver read only
     * `roles.permissions`, so such a holder passed `can('users.lock')` and was
     * resolved to nobody — the one operator qualified to undo an action was never
     * told it happened. Phase 6 grants through roles only, so this could not be
     * reached from the UI; it is asserted here because "holds the permission" has
     * to keep meaning what the Gate says it means.
     */
    #
    public function test_a_directly_granted_permission_holder_is_an_audience_member(): void
    {
        $subject = $this->login();
        $direct = User::factory()->create(['email_verified_at' => now()]);

        // Not a role: the permission sits on the pivot for the user itself.
        $direct->givePermissionTo('users.lock');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($direct->can('users.lock'), 'precondition: the Gate grants it');

        $audience = NotificationAudience::administratorsFor('user.locked');

        $this->assertTrue(
            $audience->contains('id', $direct->getKey()),
            'a holder the Gate recognises is not in the audience — the resolver and the gate disagree'
        );

        // And the event still reaches them, not just the resolver.
        NotificationFacade::fake();

        app(NotificationAccountStateAction::class)
            ->run($subject, 'user.locked', $direct);

        NotificationFacade::assertSentTo($direct, AccountStateChangedNotification::class);
    }

    /**
     * The inbox and the bell are the same number about the same inbox.
     *
     * They were two different reads: the bell's came from a cache invalidated on
     * delivery, the inbox's was counted live on every visit. A row written between
     * them left one screen saying 3 and the other saying 4, with nothing on screen
     * to explain it.
     *
     * Written straight to the table, deliberately: that is what makes the two
     * reads disagree, because no `NotificationSent` fires and the cached number
     * stays where it was. A normal delivery would invalidate and hide this.
     */
    #
    public function test_the_inbox_and_the_bell_agree_on_the_unread_count(): void
    {
        $user = $this->login();
        $this->notify($user, 'Before the gap');

        // Warm the bell's cached number.
        $this->get(route('dashboard'))->assertOk();

        // A row that bypasses the dispatch path.
        $this->notify($user, 'After the gap');

        $bell = $this->get(route('dashboard'))->assertOk()->getContent();
        $inbox = $this->get(route('notifications.inbox'))->assertOk()->getContent();

        preg_match('/badge-notification-unread[^>]*>(\d+)</', $bell, $badge);
        preg_match('/(\d+) unread\./', $inbox, $body);

        $this->assertNotEmpty($badge, 'precondition: the bell shows a count');
        $this->assertNotEmpty($body, 'precondition: the inbox states a count');

        $this->assertSame(
            $badge[1],
            $body[1],
            'the bell and the inbox report different unread counts for the same inbox'
        );
    }

    /**
     * The bell points at the inbox and is reachable by an ordinary user.
     *
     * This inverts `NotificationAccessTest::the_header_bell_is_hidden_from_a_user_the_route_refuses`,
     * which asserted the previous behaviour — the bell carried
     * `notifications.view` because its target was the configuration page. The
     * target is now a page any authenticated user can open.
     */
    #
    public function test_the_bell_points_at_the_inbox_for_an_ordinary_user(): void
    {
        $this->login(SystemRole::USER);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('bi-bell', $html, 'an ordinary user does not see the bell');
        $this->assertStringContainsString(route('notifications.inbox'), $html);
    }

    #
    public function test_the_bell_shows_the_unread_count(): void
    {
        $user = $this->login();

        $this->notify($user, 'One');
        $this->notify($user, 'Two');
        $this->notify($user, 'Three');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('>3</span>', $html);
    }

    #
    public function test_the_bell_shows_no_badge_when_everything_is_read(): void
    {
        $user = $this->login();
        $this->notify($user, 'Done', read: true);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('bi-bell', $html);
        $this->assertStringNotContainsString('badge-notification-unread', $html);
    }

    #
    public function test_a_disabled_flag_hides_the_bell_and_closes_the_inbox(): void
    {
        $this->login();
        Feature::deactivate('notifications');

        $this->assertStringNotContainsString('bi-bell', $this->get(route('dashboard'))->getContent());
        $this->get(route('notifications.inbox'))->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // The Target Audience Rule (P9-C3)
    // -----------------------------------------------------------------------

    /**
     * The rule's load-bearing half: an ordinary user receives nothing from an
     * administrative dispatch. Without this assertion the rule is a comment, and
     * a comment does not keep a mail-out from going to everyone in the table.
     */
    #
    public function test_an_administrative_event_reaches_only_permission_holders(): void
    {
        $operator = User::factory()->create(['email_verified_at' => now()]);
        $operator->assignRole($this->roleWith('users.create'));

        $ordinary = User::factory()->create(['email_verified_at' => now()]);
        $ordinary->assignRole(RoleLookup::find(SystemRole::USER));

        NotificationFacade::fake();

        NotificationAudience::forEvent('user.registered')->each(
            fn (User $admin) => $admin->notify(new RegisterNotification('someone', 'https://example.test', 60))
        );

        NotificationFacade::assertSentTo($operator, RegisterNotification::class);
        NotificationFacade::assertNotSentTo($ordinary, RegisterNotification::class);
    }

    /**
     * Superadmin holds no permission rows — its access comes from the
     * `Gate::before` rule — so a permission-only audience query would silently
     * exclude the one role guaranteed to see everything.
     */
    #
    public function test_superadmin_is_an_administrative_recipient_despite_holding_no_rows(): void
    {
        $superadmin = User::factory()->create(['email_verified_at' => now()]);
        $superadmin->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        $this->assertTrue(
            NotificationAudience::forEvent('user.registered')->contains('id', $superadmin->id),
            'superadmin is not an administrative recipient'
        );
    }

    /**
     * Personal events go to the subject and nobody else — not even an
     * administrator, who is not opted out of their own account's alerts.
     */
    #
    public function test_a_personal_event_reaches_only_its_subject(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $subject = User::factory()->create(['email_verified_at' => now()]);

        $recipients = NotificationAudience::forEvent('password.expiring', $subject);

        $this->assertTrue($recipients->contains('id', $subject->id));
        $this->assertFalse($recipients->contains('id', $admin->id));
    }

    /**
     * An unclassified event must fail toward the NARROW audience. Guessing
     * "administrative" for an unknown name would mean a typo broadcasting to
     * every administrator in the system.
     */
    #
    public function test_an_unclassified_event_reaches_nobody_when_there_is_no_subject(): void
    {
        $this->assertCount(0, NotificationAudience::forEvent('typo.in.the.event.name'));
        $this->assertCount(0, NotificationAudience::forEvent('user.registeredd'));
    }

    /**
     * The event→permission map is total and points at permissions the catalogue
     * actually declares. A map entry naming a permission nothing checks would
     * route the event to nobody — silently, because the query returns empty and
     * an empty audience looks like "no one is an admin".
     */
    #
    public function test_every_administrative_event_names_a_permission_the_catalogue_declares(): void
    {
        foreach (NotificationAudience::ADMINISTRATIVE_EVENTS as $event => $permission) {
            $this->assertContains(
                $permission,
                PermissionCatalog::all(),
                "[{$event}] names [{$permission}], which the catalogue does not declare"
            );
        }
    }

    /** One real role holding exactly the named permission. */
    private function roleWith(string $permission): Role
    {
        $role = Role::create([
            'name' => 'holder-of-'.str_replace('.', '-', $permission),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            Permission::where('name', $permission)
                ->where('guard_name', RoleLookup::guard())
                ->firstOrFail()
        );

        return $role;
    }
}
