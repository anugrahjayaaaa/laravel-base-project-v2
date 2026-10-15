<?php

namespace Tests\Feature\Notification;

use App\Enums\AuditExportStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\AuditExport;
use App\Notifications\AuditExportReadyNotification;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A notification that leaves something to DO must show how to do it (Phase 10, Group E).
 *
 * ## The gap this exists for
 *
 * The export shipped working end to end — row, job, file, notification — and the
 * notification rendered its subject and its two lines. Nothing rendered `url`, so
 * the file existed, the notice arrived, and no screen in the application led to
 * it. The operator had to reconstruct the URL. `test_the_export_notification_renders_a_download_button`
 * is the case that would have caught it.
 *
 * ## Why the URL is resolved in the Action and not the view
 *
 * `data` is a JSON column that whatever wrote the notification controls, and the
 * value lands in an `href`. The two hostile cases below are the reason the inbox
 * is not simply `href="{{ $notification->data['url'] }}"`: `javascript:` executes
 * on click, and `//evil.example` is protocol-relative — same syntax as a safe
 * path, different destination. Both are written straight into the payload here,
 * which is the only honest way to test the filter: a hand-written payload proves
 * the sanitizer, a generated one only proves the happy path.
 */
class NotificationActionLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Storage::fake('local');
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * A notification row carrying an arbitrary payload, as any writer might.
     */
    private function notify(User $user, array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\AuditExportReadyNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode($data),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_the_export_notification_renders_a_download_button(): void
    {
        $user = $this->login();

        $export = AuditExport::query()->create([
            'user_id' => $user->getKey(),
            'filters' => [],
            'status' => AuditExportStatus::READY,
            'path' => 'audit-exports/'.$user->getKey().'/file.csv',
            'row_count' => 12,
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
            'completed_at' => now(),
        ]);

        // The payload comes from the REAL notification class rather than a literal
        // written here: the point being pinned is that the inbox renders what the
        // producer emits, and a hand-written copy would keep passing after the
        // producer changed its shape. The job's own dispatch is asserted in
        // `AuditExportTest`, which runs it with the fake off.
        $payload = (new AuditExportReadyNotification($export))->toArray($user);

        $this->notify($user, $payload);

        $url = route('activity-logs.exports.download', $export, absolute: false);

        $response = $this->get(route('notifications.inbox'));

        $response->assertOk();
        $response->assertSee('Audit export ready');
        $response->assertSee('Download CSV');
        $response->assertSee('href="'.$url.'"', escape: false);

        // The link is a PATH, which is what lets the inbox refuse anything that is
        // not same-origin. Asserted here because the assertion belongs to the
        // producer: an absolute URL here would still render, and would be refused
        // by the filter for the wrong reason.
        $this->assertStringStartsWith('/activity-logs/exports/', $url);
        $this->assertSame($url, $payload['url']);
    }

    public function test_a_notification_without_an_action_renders_no_button(): void
    {
        $user = $this->login();

        $this->notify($user, [
            'subject' => 'Feature flag changed',
            'lines' => ['Changed by an operator.'],
        ]);

        $response = $this->get(route('notifications.inbox'));

        $response->assertOk();
        $response->assertSee('Feature flag changed');
        $response->assertDontSee('Download CSV');

        // Sanity: the page rendered its table rather than the assertion passing
        // because the view threw and the response body was empty.
        $response->assertSee('/notifications/inbox/');
    }

    public function test_a_javascript_url_in_the_payload_is_not_linked(): void
    {
        $user = $this->login();

        $this->notify($user, [
            'subject' => 'Something happened',
            'lines' => ['Read me.'],
            'action_label' => 'Download CSV',
            'url' => 'javascript:alert(document.cookie)',
        ]);

        $response = $this->get(route('notifications.inbox'));

        $response->assertOk();
        $response->assertDontSee('javascript:', escape: false);
        $response->assertDontSee('Download CSV');
    }

    public function test_a_protocol_relative_url_in_the_payload_is_not_linked(): void
    {
        $user = $this->login();

        $this->notify($user, [
            'subject' => 'Something happened',
            'lines' => ['Read me.'],
            'action_label' => 'Download CSV',
            'url' => '//evil.example/steal',
        ]);

        $response = $this->get(route('notifications.inbox'));

        $response->assertOk();
        $response->assertDontSee('evil.example', escape: false);
        $response->assertDontSee('Download CSV');
    }

    public function test_an_absolute_url_from_another_host_is_not_linked(): void
    {
        $user = $this->login();

        $this->notify($user, [
            'subject' => 'Something happened',
            'lines' => ['Read me.'],
            'action_label' => 'Download CSV',
            'url' => 'https://evil.example/steal',
        ]);

        $response = $this->get(route('notifications.inbox'));

        $response->assertOk();
        $response->assertDontSee('evil.example', escape: false);
    }

    public function test_one_users_notification_cannot_be_linked_by_another(): void
    {
        $user = $this->login();

        // The row belongs to somebody else entirely — the inbox must not even
        // render it, let alone its action.
        $other = User::factory()->create();

        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\AuditExportReadyNotification',
            'notifiable_type' => $other->getMorphClass(),
            'notifiable_id' => $other->getKey(),
            'data' => json_encode([
                'subject' => 'Audit export ready',
                'lines' => ['Ready.'],
                'action_label' => 'Download CSV',
                'url' => '/activity-logs/exports/1/download',
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('notifications.inbox'))
            ->assertOk()
            ->assertDontSee('Audit export ready');
    }
}
