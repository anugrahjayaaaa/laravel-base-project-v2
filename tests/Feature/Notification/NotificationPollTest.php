<?php

namespace Tests\Feature\Notification;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use App\Support\UnreadNotificationCount;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The bell's count updates without a reload.
 *
 * ## Why this is polling
 *
 * Blade renders once per request, so the header's number is true for that
 * request and stale the moment the next notification lands. Something has to ask
 * again. Push (Reverb/Pusher/WebSockets) is a daemon and a deployment surface for
 * a badge; a poll is one GET on an endpoint that already answers from cache.
 *
 * ## What these cases pin
 *
 * - the endpoint answers the CURRENT count, not a cached-at-render number;
 * - `latest_id` changes when something new arrives, which is what stops the
 *   script from replacing the dropdown's markup every 30 seconds;
 * - the list comes from the SERVER as the same Blade partial the page renders —
 *   one renderer, not a second copy of the markup in JavaScript;
 * - it is rate limited, because a poll that retries into a dead endpoint is a
 *   feature becoming a load generator.
 */
class NotificationPollTest extends TestCase
{
    use RefreshDatabase;

    protected function shouldFakeNotifications(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $this->actingAs($user, 'web');

        return $user;
    }

    private function notify(User $user, string $subject): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\RegisterNotification',
            'data' => ['subject' => $subject, 'lines' => []],
            'read_at' => null,
        ]);

        // Delivery invalidates the cached views; a row inserted behind its back
        // would leave the endpoint answering with the pre-insert number, which is
        // the same staleness this feature exists to remove.
        UnreadNotificationCount::forgetAllFor($user);

        return $id;
    }

    public function test_the_count_endpoint_answers_the_current_count(): void
    {
        $user = $this->login();
        $this->notify($user, 'First');
        $this->notify($user, 'Second');

        $this->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_the_count_endpoint_follows_a_read(): void
    {
        $user = $this->login();
        $id = $this->notify($user, 'First');

        $this->post(route('notifications.inbox.read', $id))->assertRedirect();

        $this->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_latest_id_changes_only_when_something_new_arrives(): void
    {
        $user = $this->login();

        $first = $this->getJson(route('notifications.unread-count'));
        $first->assertOk()->assertJson(['count' => 0, 'latest_id' => null]);

        $id = $this->notify($user, 'First');

        $this->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 1, 'latest_id' => $id]);

        // Opening it marks it read, which changes the COUNT but not the newest
        // row. Re-rendering the list for that would replace the DOM of a dropdown
        // the reader may be hovering.
        $this->get(route('notifications.inbox.show', $id))->assertOk();

        $this->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 0, 'latest_id' => $id]);
    }

    public function test_one_users_count_is_never_another_users(): void
    {
        $user = $this->login();
        $other = User::factory()->create();

        $this->notify($other, 'Somebody else');

        $this->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_the_list_fragment_is_the_same_blade_partial_the_page_renders(): void
    {
        $user = $this->login();
        $this->notify($user, 'Feature flag changed');

        $fragment = $this->get(route('notifications.preview'))->assertOk()->getContent();

        $this->assertStringContainsString('notification-preview-list', $fragment);
        $this->assertStringContainsString('Feature flag changed', $fragment);
        $this->assertStringContainsString(
            route('notifications.inbox.show', $user->notifications()->first()->id),
            $fragment
        );
    }

    public function test_the_poll_script_ships_with_the_bell(): void
    {
        $this->login();

        $response = $this->get(route('dashboard'));

        // Not "the page mentions notifications" — the SCRIPT and the endpoints it
        // calls, because a bell that renders perfectly while the script never
        // arrived is the exact failure this feature is meant to remove.
        $response->assertOk();
        $response->assertSee(route('notifications.unread-count'), escape: false);
        $response->assertSee(route('notifications.preview'), escape: false);
        $response->assertSee('setInterval', escape: false);
        $response->assertSee('notification-badge', escape: false);
    }

    public function test_the_poll_endpoints_require_authentication(): void
    {
        // A count endpoint that answers for a guest is a count of somebody's data.
        auth()->logout();

        $this->getJson(route('notifications.unread-count'))->assertUnauthorized();
    }

    public function test_the_poll_endpoints_are_rate_limited(): void
    {
        $this->login();

        // The ceiling is what stops a stuck retry loop from becoming load. This
        // asserts the limiter is ATTACHED, not that 120 requests were made.
        $route = collect(app('router')->getRoutes())->first(
            fn ($route): bool => $route->getName() === 'notifications.unread-count'
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:120,1',
            $route->gatherMiddleware(),
            'the poll endpoint has no ceiling, so a broken client can hammer it'
        );
    }
}
