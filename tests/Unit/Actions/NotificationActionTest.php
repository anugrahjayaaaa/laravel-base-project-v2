<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Notification\NotificationChannelUpdateAction;
use App\Actions\V1\Notification\NotificationTestMailSendAction;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The two notification actions that do not send a real notification: the
 * channel switches, and the transport probe.
 *
 * The probe is the one action in this module whose failure path is the
 * interesting one — a broken transport must arrive as a sentence, never a 500.
 */
class NotificationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    // === channel switches ===

    public function test_a_save_writes_every_channel_and_audits_the_result(): void
    {
        app(NotificationChannelUpdateAction::class)->run(
            ['in_app' => '0', 'mail' => '1', 'database' => '1'],
            $this->admin(),
        );

        $this->assertFalse(SystemSetting::getBool('notification_channel_in_app'));
        $this->assertTrue(SystemSetting::getBool('notification_channel_mail'));

        $row = Activity::query()->where('event', 'notification_channels.updated')->sole();
        $this->assertSame('false', $row->properties['channels']['notification_channel_in_app']);
        $this->assertSame('true', $row->properties['channels']['notification_channel_mail']);
    }

    public function test_an_absent_channel_keeps_its_stored_value(): void
    {
        // Every switch in the form posts a hidden '0', so a genuinely missing key
        // is a partial payload — and turning the rest off would be a change the
        // caller never asked for.
        app(NotificationChannelUpdateAction::class)->run(['mail' => '0'], $this->admin());

        $this->assertFalse(SystemSetting::getBool('notification_channel_mail'));
        $this->assertTrue(SystemSetting::getBool('notification_channel_in_app'));
        $this->assertTrue(SystemSetting::getBool('notification_channel_database'));
    }

    // === transport probe ===

    public function test_a_delivered_probe_is_recorded_against_the_settings_row(): void
    {
        Mail::shouldReceive('raw')->once();

        $result = app(NotificationTestMailSendAction::class)->run('ops@example.com', $this->admin());

        $this->assertTrue($result['ok']);

        $row = Activity::query()->where('event', 'test_mail.sent')->sole();
        $this->assertSame('ops@example.com', $row->properties['recipient']);
    }

    public function test_a_broken_transport_returns_a_sentence_instead_of_throwing(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('Connection refused'));

        $result = app(NotificationTestMailSendAction::class)->run('ops@example.com', $this->admin());

        $this->assertFalse($result['ok']);

        // The exception text stays in the log; the page gets the actionable half.
        $this->assertStringNotContainsString('Connection refused', $result['message']);

        $row = Activity::query()->where('event', 'test_mail.failed')->sole();
        $this->assertSame('ops@example.com', $row->properties['recipient']);
    }

    public function test_a_probe_with_no_causer_writes_no_audit_row(): void
    {
        Mail::shouldReceive('raw')->once();

        app(NotificationTestMailSendAction::class)->run('ops@example.com');

        $this->assertSame(0, Activity::query()->whereIn('event', ['test_mail.sent', 'test_mail.failed'])->count());
    }

    // === transaction boundary ===

    public function test_the_channel_switches_are_rolled_back_when_the_audit_write_fails(): void
    {
        Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit write failed'));

        try {
            app(NotificationChannelUpdateAction::class)->run(
                ['in_app' => '0', 'mail' => '0', 'database' => '0'],
                $this->admin(),
            );
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        // Read the table, not SystemSetting::get*: the static request cache holds
        // the values the rolled-back transaction wrote.
        $this->assertSame('true', DB::table('system_settings')->where('key', 'notification_channel_in_app')->value('value'));
    }

    private function admin(): User
    {
        return User::factory()->create();
    }
}
