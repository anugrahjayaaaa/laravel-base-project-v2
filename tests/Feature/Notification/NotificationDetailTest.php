<?php

namespace Tests\Feature\Notification;

use App\Enums\AuditExportStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\AuditExport;
use App\Models\RoleLookup;
use App\Models\User;
use App\Notifications\AuditExportReadyNotification;
use App\Support\UnreadNotificationCount;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Opening a notification opens THAT notification (Phase 10, Group E follow-up).
 *
 * ## What this pins
 *
 * Every bell and inbox row used to link at `/notifications/inbox`, so clicking one
 * of thirty notifications dropped the reader into the list with nothing marking
 * which one they picked — and the row they picked stayed unread. Two clicks to
 * read, one of them aimed at nothing.
 *
 * These cases are the HTTP-level assertions for that: the link target, the read
 * state afterwards, the isolation, and the 404 for somebody else's row.
 */
class NotificationDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real delivery for this file.
     *
     * `TestCase` fakes notifications by default, and a faked notification never
     * reaches the database — so a detail page with nothing in it is not a page
     * under test. `Mail::fake()` in `setUp()` replaces the transport instead: the
     * row is written for real, and nothing renders a mail template.
     */
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

    /**
     * A notification carrying the export payload, delivered for real.
     */
    private function exportNotificationFor(User $user): AuditExport
    {
        $export = AuditExport::query()->create([
            'user_id' => $user->getKey(),
            'filters' => [],
            'status' => AuditExportStatus::READY,
            'path' => 'audit-exports/'.$user->getKey().'/file.csv',
            'row_count' => 7,
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
            'completed_at' => now(),
        ]);

        $user->notify(new AuditExportReadyNotification($export));

        return $export;
    }

    public function test_opening_a_notification_marks_it_read(): void
    {
        $user = $this->login();
        $this->exportNotificationFor($user);

        $id = $user->notifications()->sole()->getKey();

        $this->assertSame(1, UnreadNotificationCount::for($user), 'precondition: it arrived unread');

        $this->get(route('notifications.inbox.show', $id))->assertOk();

        $this->assertNotNull($user->notifications()->find($id)->read_at);
        $this->assertSame(0, UnreadNotificationCount::for($user), 'the badge still counts a read row');
    }

    public function test_opening_it_shows_that_notification_and_its_action(): void
    {
        $user = $this->login();
        $export = $this->exportNotificationFor($user);

        $id = $user->notifications()->sole()->getKey();

        $response = $this->get(route('notifications.inbox.show', $id));

        $response->assertOk();
        $response->assertSee('Audit export ready');
        $response->assertSee('Download CSV');
        $response->assertSee(route('activity-logs.exports.download', $export, absolute: false), escape: false);

        // The download button is the page's one primary action, and it is the
        // contrast fix: `btn-outline-secondary` is `--lbp-muted` on `--lbp-border`,
        // which reads as disabled in dark mode.
        $response->assertSee('btn btn-primary', escape: false);
    }

    public function test_opening_one_twice_is_harmless(): void
    {
        $user = $this->login();
        $this->exportNotificationFor($user);

        $id = $user->notifications()->sole()->getKey();

        $this->get(route('notifications.inbox.show', $id))->assertOk();
        $this->get(route('notifications.inbox.show', $id))->assertOk();

        $this->assertSame(0, UnreadNotificationCount::for($user));
    }

    public function test_another_users_notification_is_not_found(): void
    {
        $user = $this->login();
        $other = User::factory()->create();

        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => AuditExportReadyNotification::class,
            'notifiable_type' => $other->getMorphClass(),
            'notifiable_id' => $other->getKey(),
            'data' => json_encode(['subject' => 'Somebody else\'s export', 'lines' => []]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 404, not 403: the inbox has no permission, so a 403 would confirm the
        // row exists on somebody else's account.
        $this->get(route('notifications.inbox.show', $id))->assertNotFound();
    }

    public function test_every_bell_and_inbox_row_links_at_its_own_notification(): void
    {
        $user = $this->login();
        $this->exportNotificationFor($user);

        $id = $user->notifications()->sole()->getKey();

        $detail = route('notifications.inbox.show', $id);

        $this->get(route('notifications.inbox'))->assertSee('href="'.$detail.'"', escape: false);

        // The bell is in the layout, so the dropdown partial is rendered by the
        // same response — one request, both surfaces asserted.
        $this->get(route('dashboard'))->assertSee('href="'.$detail.'"', escape: false);
    }
}
