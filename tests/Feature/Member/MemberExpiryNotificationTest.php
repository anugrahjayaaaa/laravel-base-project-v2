<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\SendMemberExpiryNotificationJob;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\MemberExpiryWarningNotification;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The bulk expiry warning run (Phase 18, P18-C2).
 *
 * Two halves. The trigger: `members.notify` alone can queue the run — mass
 * mailing members is deliberately its own permission, not folded into the
 * member edit slugs (D-6) — and the request returns without touching SMTP,
 * because all it does is push one job.
 * The run: one message per MEMBER with a period inside the window, never one
 * per subscription, and nothing at all while the flag is off.
 */
class MemberExpiryNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The bulk run is the subject here: it must push real
     * `SendQueuedNotifications` jobs under `Queue::fake()`, which the global
     * notification fake in `Tests\TestCase` would silently swallow. Tests
     * that inspect the notification itself call `Notification::fake()` inline.
     */
    protected function shouldFakeNotifications(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWith(string ...$permissions): User
    {
        $role = Role::create(['name' => 'broadcaster-'.uniqid(), 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->syncRoles([$role->name]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A member with one live period ending inside the alert window. */
    private function expiringMember(int $inDays = 3): array
    {
        $tier = MemberTier::factory()->create();
        $member = Member::factory()->create();
        $subscription = MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'expires_at' => now()->addDays($inDays),
        ]);

        return [$member->fresh(), $subscription];
    }

    public function test_the_dashboard_button_queues_the_run_with_the_saved_window(): void
    {
        Queue::fake();
        SystemSetting::set('member_expiry_alert_window_days', '30');

        $this->actingAs($this->userWith('members.notify'));

        $this->post(route('membership.broadcast-expiry'))->assertRedirect();

        Queue::assertPushed(
            SendMemberExpiryNotificationJob::class,
            fn (SendMemberExpiryNotificationJob $job): bool => $job->alertWindowDays === 30
        );
    }

    public function test_an_edit_permission_alone_cannot_mail_every_member(): void
    {
        // `members.notify` is its own permission (D-6): being able to edit
        // members must not buy the mass-mail trigger.
        Queue::fake();
        $this->actingAs($this->userWith('members.update'));

        $this->post(route('membership.broadcast-expiry'))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_api_twin_queues_the_same_run_under_the_same_permission(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userWith('members.notify'));

        $this->postJson(route('api.v1.members.broadcast-expiry'))->assertOk();

        Queue::assertPushed(SendMemberExpiryNotificationJob::class);
    }

    public function test_the_api_twin_answers_403_with_the_flag_off(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userWith('members.notify'));
        Feature::deactivate('member_loyalty');

        $this->postJson(route('api.v1.members.broadcast-expiry'))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_member_with_two_expiring_periods_is_mailed_once_not_twice(): void
    {
        Notification::fake();

        [$member] = $this->expiringMember();
        $secondTier = MemberTier::factory()->create();
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $secondTier->id,
            'expires_at' => now()->addDays(6),
        ]);

        (new SendMemberExpiryNotificationJob(14))->handle();

        Notification::assertSentToTimes($member->user, MemberExpiryWarningNotification::class, 1);
    }

    public function test_periods_outside_the_window_are_not_mailed(): void
    {
        Notification::fake();

        [$member] = $this->expiringMember(inDays: 40);

        (new SendMemberExpiryNotificationJob(14))->handle();

        Notification::assertSentToTimes($member->user, MemberExpiryWarningNotification::class, 0);
    }

    public function test_the_run_sends_nothing_while_the_flag_is_off(): void
    {
        Notification::fake();

        [$member] = $this->expiringMember();

        Feature::deactivate('member_loyalty');
        (new SendMemberExpiryNotificationJob(14))->handle();

        Notification::assertSentToTimes($member->user, MemberExpiryWarningNotification::class, 0);
    }

    public function test_the_earliest_expiry_wins_when_the_run_collapses_a_members_periods(): void
    {
        Notification::fake();

        [$member, $first] = $this->expiringMember(inDays: 2);
        $secondTier = MemberTier::factory()->create();
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $secondTier->id,
            'expires_at' => now()->addDays(9),
        ]);

        (new SendMemberExpiryNotificationJob(14))->handle();

        Notification::assertSentTo(
            $member->user,
            MemberExpiryWarningNotification::class,
            fn (MemberExpiryWarningNotification $notification, array $channels): bool => str_contains(
                json_encode($notification->toArray($member->user), true),
                $first->expires_at->format('F j, Y')
            )
        );
    }

    public function test_one_thousand_expiring_members_become_one_thousand_small_queued_sends(): void
    {
        // The acceptance line this pins: the bulk run never opens SMTP in its
        // own process. Under `Queue::fake()` every recipient must arrive as a
        // separate `SendQueuedNotifications` job — 1 000 recipients, 1 000
        // queued deliveries, one job total for the scan.
        Queue::fake();

        $tier = MemberTier::factory()->create();
        Member::factory()->count(1000)->create()->each(
            fn (Member $member) => MemberSubscription::factory()->create([
                'member_id' => $member->id,
                'member_tier_id' => $tier->id,
                'expires_at' => now()->addDays(3),
            ])
        );

        (new SendMemberExpiryNotificationJob(14))->handle();

        // The count IS the acceptance line: 1 000 recipients, 1 000 queued
        // mail deliveries (`SendQueuedNotifications` is per channel, so the
        // database copy doubles the total) — the worker dries them out, this
        // process sends none.
        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']
        );
        Queue::assertPushed(SendQueuedNotifications::class, 2000);
    }
}
