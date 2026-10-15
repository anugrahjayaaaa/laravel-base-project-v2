<?php

namespace Tests\Feature\Api\V1\Notification;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use App\Support\UnreadNotificationCount;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The notification inbox over the API (web/API gap audit).
 *
 * ## What this pins
 *
 * The gap: the API could read a user's notification SETTINGS and could not read
 * a single notification. So these cases exist to prove the inbox is scoped the
 * way the web inbox is — by the token's own relation, not by a permission name
 * nobody holds — and that the one thing the payload adds to a response (a link
 * out) is filtered the same way the web filters it.
 *
 * The link filter is the one worth having a test for. `data` is a JSON column,
 * `url` reaches an `href`, and `//evil.example` has the syntax of a path and the
 * destination of a link somewhere else. If this channel shipped the raw value,
 * the API would be a wider hole than the web UI it mirrors.
 */
class NotificationInboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::USER));

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(User $user, string $subject, array $data = []): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\RegisterNotification',
            'data' => array_merge(['subject' => $subject, 'lines' => []], $data),
            'read_at' => null,
        ]);

        UnreadNotificationCount::forgetAllFor($user);

        return $id;
    }

    public function test_the_inbox_returns_the_viewers_own_notifications(): void
    {
        $user = $this->login();
        $this->notify($user, 'Audit export ready');

        $this->getJson(route('api.v1.notifications.inbox.index'))
            ->assertOk()
            ->assertJsonPath('data.notifications.0.subject', 'Audit export ready')
            ->assertJsonPath('data.unread_count', 1);
    }

    /**
     * Someone else's notification is not merely filtered out of this one — it is
     * in a different table row attached to a different user, and nothing here can
     * reach it. The web inbox scopes the same way, for the same reason.
     */
    public function test_another_users_notifications_are_never_returned(): void
    {
        $this->login();

        $other = User::factory()->create();
        $this->notify($other, 'Somebody elses secret');

        $this->getJson(route('api.v1.notifications.inbox.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications')
            ->assertDontSee('Somebody elses secret', false);
    }

    public function test_the_unread_count_endpoint_carries_the_change_detection_id(): void
    {
        $user = $this->login();

        $this->getJson(route('api.v1.notifications.inbox.unread-count'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.latest_id', null);

        $id = $this->notify($user, 'Audit export ready');

        // `latest_id` is what lets a polling client skip the expensive half:
        // the count moved but no row did means nothing new arrived.
        $this->getJson(route('api.v1.notifications.inbox.unread-count'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.latest_id', $id);
    }

    public function test_marking_read_clears_the_badge_and_the_list(): void
    {
        $user = $this->login();
        $id = $this->notify($user, 'Audit export ready');

        $this->postJson(route('api.v1.notifications.inbox.read', $id))->assertOk();

        // BOTH cached views, not just the count. Clearing only the count is the
        // worst version of this bug: the badge updates instantly and the list
        // beside it still shows the previous five, which reads as delivery having
        // failed.
        $this->getJson(route('api.v1.notifications.inbox.unread-count'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $readAt = $user->notifications()->sole()->read_at;
        $this->assertNotNull($readAt, 'the row itself is marked read, not just the badge');
        $this->getJson(route('api.v1.notifications.inbox.index'))
            ->assertOk()
            ->assertJsonPath('data.notifications.0.read_at', $readAt->toIso8601String());
    }

    public function test_marking_all_read_zeroes_the_badge(): void
    {
        $user = $this->login();

        $this->notify($user, 'One');
        $this->notify($user, 'Two');
        $this->notify($user, 'Three');

        $this->postJson(route('api.v1.notifications.inbox.read-all'))
            ->assertOk()
            ->assertJsonPath('data.message', '3 notification(s) marked as read.');

        $this->getJson(route('api.v1.notifications.inbox.unread-count'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_marking_someone_elses_notification_read_is_refused(): void
    {
        $this->login();

        $other = User::factory()->create();
        $theirs = $this->notify($other, 'Not yours');

        $this->postJson(route('api.v1.notifications.inbox.read', $theirs))->assertNotFound();

        // And the other side of it is unchanged.
        $this->assertNull($other->notifications()->whereKey($theirs)->first()->read_at);
    }

    /**
     * The link out is filtered, not passed through.
     *
     * `data` is a JSON column whose writer is the application, but nothing
     * structural stops a payload carrying `javascript:alert(1)` or
     * `//evil.example`, and on this channel the value lands in an `href` on a
     * client nobody controls. `SafeUrl` is the same filter the web inbox
     * applies — extracted so the two cannot drift.
     */
    #[DataProvider('unsafeUrlProvider')]
    public function test_an_unsafe_action_url_is_dropped_from_the_api_payload(string $url): void
    {
        $user = $this->login();

        $this->notify($user, 'Click me', [
            'action_label' => 'Download CSV',
            'url' => $url,
        ]);

        $this->getJson(route('api.v1.notifications.inbox.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.notifications.0.action');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            // The syntax of a path and the destination of a link elsewhere.
            'protocol relative' => ['//evil.example/steal'],
            'absolute elsewhere' => ['https://evil.example/steal'],
            'relative, no leading slash' => ['notifications/inbox'],
            'not a string' => [''],
        ];
    }

    public function test_a_safe_relative_path_survives(): void
    {
        $user = $this->login();

        $this->notify($user, 'Audit export ready', [
            'action_label' => 'Download CSV',
            'url' => '/activity-logs/exports/1/download',
        ]);

        $this->getJson(route('api.v1.notifications.inbox.index'))
            ->assertOk()
            ->assertJsonPath('data.notifications.0.action.url', '/activity-logs/exports/1/download')
            ->assertJsonPath('data.notifications.0.action.label', 'Download CSV');
    }

    public function test_the_routes_require_authentication(): void
    {
        $this->getJson(route('api.v1.notifications.inbox.index'))->assertUnauthorized();
        $this->getJson(route('api.v1.notifications.inbox.unread-count'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.inbox.read-all'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.inbox.read', Str::uuid()))->assertUnauthorized();
    }

    /**
     * The real ceiling is the API group limiter, not the web's 120.
     *
     * `throttle:120,1` was on this route at first, copied from the web twin — and
     * it could never refuse anything, because the whole protected API group
     * already carries `throttle:user-state-actions` at 15/minute. Whichever runs
     * first wins, and 15 is tighter. Dead middleware on a route reads as a
     * control that is not there.
     *
     * So this asserts 15, and says so: a 30-second poll spends 2 of those, a
     * 5-second one would not fit, and that is a number to know before writing a
     * client that polls faster than the web UI does.
     */
    public function test_the_unread_count_endpoint_is_capped_by_the_api_group_limiter(): void
    {
        $this->login();

        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $this->getJson(route('api.v1.notifications.inbox.unread-count'))->assertOk();
        }

        $this->getJson(route('api.v1.notifications.inbox.unread-count'))->assertStatus(429);
    }
}
