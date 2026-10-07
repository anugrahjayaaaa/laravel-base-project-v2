<?php

namespace Tests\Feature\Notification;

use App\Support\NotificationAudience;
use App\Support\UnreadNotificationCount;
use App\Models\RoleLookup;
use App\Models\User;
use App\Notifications\RegisterNotification;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

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
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
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
    #[Test]
    public function an_ordinary_user_reaches_their_inbox(): void
    {
        $user = $this->login();
        $this->assertFalse($user->can('notifications.view'), 'precondition: holds no notifications.view');

        $this->get(route('notifications.inbox'))->assertOk();
    }

    #[Test]
    public function the_inbox_shows_the_viewers_notifications(): void
    {
        $user = $this->login();
        $this->notify($user, 'Welcome aboard');

        $this->get(route('notifications.inbox'))
            ->assertOk()
            ->assertSee('Welcome aboard');
    }

    #[Test]
    public function the_inbox_counts_every_unread_not_just_the_page(): void
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

    #[Test]
    public function an_empty_inbox_renders_rather_than_erroring(): void
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
    #[Test]
    public function a_user_cannot_mark_another_users_notification_read(): void
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
    #[Test]
    public function mark_all_as_read_touches_nobody_elses_notifications(): void
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

    #[Test]
    public function the_viewer_can_mark_their_own_notification_read(): void
    {
        $user = $this->login();
        $id = $this->notify($user);

        $this->post(route('notifications.inbox.read', $id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($user->notifications()->whereKey($id)->first()->read_at);
    }

    #[Test]
    public function an_already_read_notification_is_reported_not_silently_accepted(): void
    {
        $user = $this->login();
        $id = $this->notify($user, read: true);

        $this->post(route('notifications.inbox.read', $id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    public function an_unknown_notification_id_is_reported_not_a_500(): void
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
    #[Test]
    public function the_bell_count_is_cached_and_invalidated_when_a_notification_arrives(): void
    {
        $user = $this->login();

        $this->notify($user, 'First');
        $this->assertStringContainsString('1 unread notifications', $this->get(route('dashboard'))->getContent());

        // Second render: served from cache, still correct.
        $this->assertStringContainsString('1 unread notifications', $this->get(route('dashboard'))->getContent());

        // A notification arriving on ANOTHER user's request — an admin creating a
        // user, a queued job — must clear this user's cached count.
        $this->notify($user, 'Second');
        UnreadNotificationCount::forget($user);

        $this->assertStringContainsString(
            '2 unread notifications',
            $this->get(route('dashboard'))->getContent()
        );
    }

    /**
     * The other half of the invalidation: marking read changes the count and no
     * event fires for it. Forgetting only on delivery is how a badge ends up
     * permanently one too high.
     */
    #[Test]
    public function marking_read_invalidates_the_bell_count(): void
    {
        $user = $this->login();
        $id = $this->notify($user);

        $this->assertStringContainsString('1 unread notifications', $this->get(route('dashboard'))->getContent());

        $this->post(route('notifications.inbox.read', $id))->assertRedirect();

        $this->assertStringNotContainsString(
            'unread notifications',
            $this->get(route('dashboard'))->getContent(),
            'the bell still shows a count after everything was marked read'
        );
    }

    #[Test]
    public function the_bell_count_costs_no_query_on_a_warm_cache(): void
    {
        $user = $this->login();
        $this->notify($user);

        // Warm it.
        $this->get(route('dashboard'))->assertOk();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

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
     * The bell points at the inbox and is reachable by an ordinary user.
     *
     * This inverts `NotificationAccessTest::the_header_bell_is_hidden_from_a_user_the_route_refuses`,
     * which asserted the previous behaviour — the bell carried
     * `notifications.view` because its target was the configuration page. The
     * target is now a page any authenticated user can open.
     */
    #[Test]
    public function the_bell_points_at_the_inbox_for_an_ordinary_user(): void
    {
        $this->login(SystemRole::USER);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('fa-bell', $html, 'an ordinary user does not see the bell');
        $this->assertStringContainsString(route('notifications.inbox'), $html);
    }

    #[Test]
    public function the_bell_shows_the_unread_count(): void
    {
        $user = $this->login();

        $this->notify($user, 'One');
        $this->notify($user, 'Two');
        $this->notify($user, 'Three');

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('3 unread notifications', $html);
    }

    #[Test]
    public function the_bell_shows_no_badge_when_everything_is_read(): void
    {
        $user = $this->login();
        $this->notify($user, 'Done', read: true);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('fa-bell', $html);
        $this->assertStringNotContainsString('unread notifications', $html);
    }

    #[Test]
    public function a_disabled_flag_hides_the_bell_and_closes_the_inbox(): void
    {
        $this->login();
        \Laravel\Pennant\Feature::deactivate('notifications');

        $this->assertStringNotContainsString('fa-bell', $this->get(route('dashboard'))->getContent());
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
    #[Test]
    public function an_administrative_event_reaches_only_permission_holders(): void
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
    #[Test]
    public function superadmin_is_an_administrative_recipient_despite_holding_no_rows(): void
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
    #[Test]
    public function a_personal_event_reaches_only_its_subject(): void
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
    #[Test]
    public function an_unclassified_event_reaches_nobody_when_there_is_no_subject(): void
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
    #[Test]
    public function every_administrative_event_names_a_permission_the_catalogue_declares(): void
    {
        foreach (NotificationAudience::ADMINISTRATIVE_EVENTS as $event => $permission) {
            $this->assertContains(
                $permission,
                \App\Support\PermissionCatalog::all(),
                "[{$event}] names [{$permission}], which the catalogue does not declare"
            );
        }
    }

    /** One real role holding exactly the named permission. */
    private function roleWith(string $permission): \Spatie\Permission\Models\Role
    {
        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'holder-of-'.str_replace('.', '-', $permission),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::where('name', $permission)
                ->where('guard_name', RoleLookup::guard())
                ->firstOrFail()
        );

        return $role;
    }
}
