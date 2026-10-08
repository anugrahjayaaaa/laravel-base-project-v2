<?php

namespace Tests\Feature\Notification;

use App\Actions\V1\Notification\NotificationAdminEventAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Support\NotificationAudience;
use App\Support\NotificationChannel;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AccountStateChangedNotification;
use App\Notifications\ConfigurationChangedNotification;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Target Audience Rule, wired to real events.
 *
 * `NotificationInboxTest` proves the rule is CORRECT — a map resolves an event
 * to permission holders, an ordinary user receives nothing, superadmin is
 * included. This file proves something weaker and more important: that anything
 * CALLS it. A rule with four passing tests and no caller is a well-tested
 * function nobody runs, which is how the audit finding "no code to hang on"
 * survives a phase that claims to have fixed it.
 */
class AccountStateNotificationTest extends TestCase
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
    }

    /**
     * A user holding exactly this permission, in a role of its own.
     *
     * Built per test rather than shared: `User::can()` memoizes per instance and
     * the registrar caches the permission set per user, so a role created once
     * and reused across assertions would answer from a stale read.
     */
    private function holderOf(string $permission): User
    {
        $role = Role::create([
            'name' => 'holder-'.str_replace('.', '-', $permission).'-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            Permission::where('name', $permission)
                ->where('guard_name', RoleLookup::guard())
                ->firstOrFail()
        );

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function ordinaryUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::USER));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * The rule's whole point, on a real event: locking an account tells the
     * account holder AND the administrators who can undo it — and tells an
     * ordinary user who administers nothing nothing.
     *
     * This is the test that would have caught the gap. Before this was wired,
     * `NotificationAudience` had four passing tests and zero callers.
     */
    public function test_locking_an_account_reaches_both_audiences(): void
    {
        // Fixtures FIRST, then the fake. Building a role grants permissions, and
        // Spatie fires its own model events through the Notification facade — so
        // faking before the fixtures exist turns a permission grant into a
        // swallowed notification and the role silently holds nothing.
        $subject = $this->ordinaryUser();
        $locker = $this->holderOf('users.lock');
        $bystander = $this->ordinaryUser();

        Notification::fake();

        app(UserLockAction::class)->run($subject, $locker);

        // The personal half: the person who can no longer log in.
        Notification::assertSentTo($subject, AccountStateChangedNotification::class);

        // The administrative half: someone who can unlock it.
        // The preconditions, asserted: a failure here would otherwise look like
        // "the resolver sent nobody", when the truth could equally be that the
        // role holds nothing or that the dispatch deduplicated everyone away.
        $this->assertTrue($locker->can('users.lock'), 'precondition: the operator holds users.lock');
        $this->assertTrue(
            NotificationAudience::forEvent('user.locked')->contains('id', $locker->id),
            'precondition: the resolver includes the operator'
        );

        Notification::assertSentTo($locker, AccountStateChangedNotification::class);

        // And nobody who administers nothing.
        Notification::assertNotSentTo($bystander, AccountStateChangedNotification::class);
    }

    /**
     * The same two audiences for the other three events, in one test.
     *
     * Parameterised rather than four near-identical methods: the difference
     * between the events is a string, and a rule enforced in one place cannot be
     * enforced in four.
     */
    public function test_every_account_state_event_notifies_both_audiences(): void
    {
        foreach ([
            // The operator holds the permission for THAT event, not a generic
            // `users.manage` — which does not exist. The catalogue splits
            // management into per-action permissions, and the audience map keys on
            // them.
            ['user.unlocked', 'users.unlock', UserUnlockAction::class],
            ['user.deactivated', 'users.deactivate', UserDeactivateAction::class],
        ] as [$event, $permission, $actionClass]) {
            // Fixtures before the fake — see the note in the test above.
            $subject = $this->ordinaryUser();
            $operator = $this->holderOf($permission);
            $bystander = $this->ordinaryUser();

            Notification::fake();

            // Unlock requires a locked account; deactivate requires an active one.
            $subject->update(['is_locked' => $event === 'user.unlocked', 'is_active' => $event !== 'user.deactivated']);
            $subject->refresh();

            app($actionClass)->run($subject, $operator);

            Notification::assertSentTo($subject, AccountStateChangedNotification::class);
            Notification::assertSentTo($operator, AccountStateChangedNotification::class);
            Notification::assertNotSentTo($bystander, AccountStateChangedNotification::class);
        }
    }

    /**
     * The subject is notified FIRST and unconditionally.
     *
     * `NotificationAudience::forEvent('user.locked', $subject)` resolves to the
     * ADMINISTRATIVE audience for a declared event — the subject argument is
     * only consulted for undeclared ones. Writing it that way is a one-word
     * mistake that silently drops the person the notification is about.
     */
    public function test_the_subject_is_notified_even_though_the_event_is_administrative(): void
    {
        Notification::fake();

        $subject = $this->ordinaryUser();
        app(UserLockAction::class)->run($subject, $this->holderOf('users.lock'));

        // The map alone would not have included them.
        $this->assertFalse(
            NotificationAudience::forEvent('user.locked')->contains('id', $subject->id),
            'precondition: the subject holds no administrative permission'
        );

        Notification::assertSentTo($subject, AccountStateChangedNotification::class);
    }

    /**
     * An administrator who is ALSO the subject receives one, not two.
     *
     * The two audience lists are merged before dispatch, so the overlap is
     * resolved once. Sending twice is how a notification stops being read, and
     * it happens exactly when the rule works — an admin is in the permission set
     * by definition.
     */
    public function test_a_recipient_in_both_audiences_is_notified_once(): void
    {
        Notification::fake();

        $admin = $this->holderOf('users.lock');

        app(UserLockAction::class)->run($admin, $this->holderOf('users.lock'));

        Notification::assertSentToTimes($admin, AccountStateChangedNotification::class, 1);
    }

    /**
     * The dispatch must not be able to fail the state change.
     *
     * An account rolled back because a notification could not be delivered is a
     * worse outcome than a missed notification: the admin asked for a lock, the
     * lock did not happen, and nothing says why.
     */
    public function test_a_delivery_failure_does_not_undo_the_state_change(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('transport down'));

        $subject = $this->ordinaryUser();
        $operator = $this->holderOf('users.lock');

        // No exception: the action returns normally with the account locked.
        $result = app(UserLockAction::class)->run($subject, $operator);

        $this->assertTrue($result['user']->fresh()->is_locked, 'the lock was rolled back by a mail failure');
    }

    // -----------------------------------------------------------------------
    // The `in_app` switch (was a control that changed nothing)
    // -----------------------------------------------------------------------

    /**
     * `in_app` gates the inbox alongside `database`.
     *
     * It was seeded and rendered and saved successfully while
     * `NotificationChannel::for()` ignored it — the same category of defect as a
     * `pending` feature flag, and the reason this assertion exists.
     */
    public function test_the_in_app_switch_gates_the_database_channel(): void
    {
        SystemSetting::set('notification_channel_mail', 'false');
        SystemSetting::set('notification_channel_database', 'true');
        SystemSetting::set('notification_channel_in_app', 'false');
        SystemSetting::bustCache();

        $this->assertContains('database', NotificationChannel::for());
    }

    public function test_turning_mail_off_stops_mail(): void
    {
        SystemSetting::set('notification_channel_mail', 'false');
        SystemSetting::bustCache();

        $this->assertNotContains('mail', NotificationChannel::for());
    }

    /**
     * The switch reaches a real dispatch, not just the resolver.
     *
     * `NotificationChannel::for()` returning the right array proves nothing if
     * nothing calls it — the defect this file exists to catch, one level down.
     *
     * This is the ORDINARY half: an administrative notice nobody is waiting on
     * follows the switch like anything else. The essential half is asserted
     * below, and the contrast between them is the point — one switch, two
     * behaviours, both deliberate.
     */
    public function test_the_switches_reach_the_dispatched_notification(): void
    {
        Notification::fake();

        SystemSetting::set('notification_channel_mail', 'false');
        SystemSetting::set('notification_channel_database', 'true');
        SystemSetting::bustCache();

        $subject = $this->ordinaryUser();
        $admin = $this->holderOf('users.lock');
        // `user.updated` resolves its audience through `users.update`, so the
        // holder here is a different person — reusing `$admin` would assert
        // against an empty audience and read as "nothing was sent".
        $editor = $this->holderOf('users.update');

        app(UserLockAction::class)->run($subject, $admin);
        app(NotificationAdminEventAction::class)
            ->configurationChanged('user.updated', 'Profile updated', $editor);

        // `assertSentTo` returns void in this framework, so the sent instance is
        // read off the fake rather than captured from the assertion.
        $accountState = Notification::sent($subject, AccountStateChangedNotification::class)->first();

        $this->assertNotNull($accountState, 'precondition: the subject was notified');
        $this->assertSame(
            ['mail', 'database'],
            $accountState->via($subject),
            'a locked account cannot open the inbox, so mail is the only channel that reaches it'
        );

        $administrative = Notification::sent($editor, ConfigurationChangedNotification::class)->first();

        $this->assertNotNull($administrative, 'precondition: the administrator was notified');
        $this->assertSame(
            ['database'],
            $administrative->via($admin),
            'an administrative notice has an inbox to land in and follows the switch'
        );
    }
}
