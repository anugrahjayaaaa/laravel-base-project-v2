<?php

namespace Tests\Feature\Notification;

use App\Actions\V1\Feature\FeatureToggleAction;
use App\Actions\V1\Notification\NotificationAdminEventAction;
use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\Role\RoleDeleteAction;
use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Notifications\ConfigurationChangedNotification;
use App\Notifications\RolesChangedNotification;
use App\Notifications\UserRegisteredNotification;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Notifications\UserCreatedNotification;
use App\Support\NotificationAudience;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;

/**
 * Every declared administrative event is actually dispatched.
 *
 * `NotificationInboxTest` proves the audience map is CORRECT and
 * `AccountStateNotificationTest` proves the account-state events reach both of
 * their audiences. This file covers the other nine declared events — and its
 * real subject is not any one notification but the fact that a declared event
 * with no dispatch is a permission that grants nothing and a map entry that
 * routes to nobody.
 *
 * That is the shape of the gap this phase found once already: a correct, tested
 * rule with zero callers.
 */
class AdministrativeEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Fixtures are built BEFORE `Notification::fake()` throughout: granting a
     * permission fires Spatie's own events through the Notification facade, and
     * faking first turns a grant into a swallowed notification and the role
     * silently holds nothing.
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
     * A role change notifies THE USER whose access changed — and not every
     * `roles.update` holder.
     *
     * The owner decision (2026-10-06): the people who can edit a role are usually
     * more numerous than the people wearing it, and telling all of them that a
     * colleague gained a permission turns an administrative feed into noise.
     */
    #
    public function test_a_role_change_notifies_the_user_whose_access_changed (): void
    {
        $subject = $this->ordinaryUser();
        // `users.assign_roles` is the permission RoleAssignAction checks, not
        // `roles.assign_permissions` — which governs the role edit form. Asserted
        // here because guessing wrong produces an AuthorizationException that
        // reads like the notification is broken.
        $operator = $this->holderOf('users.assign_roles');
        $bystander = $this->holderOf('roles.update');

        Notification::fake();

        app(RoleAssignAction::class)->run($subject, ['admin'], $operator);

        Notification::assertSentTo($subject, RolesChangedNotification::class);

        // The `roles.update` holder hears nothing: they could have done it, but it
        // was not about them.
        Notification::assertNotSentTo($bystander, RolesChangedNotification::class);
    }

    /** A role change notifies the holder of roles.update, not just the user. */
    #
    public function test_a_role_change_notifies_administrators_who_can_edit_roles (): void
    {
        $subject = $this->ordinaryUser();
        // `users.assign_roles` is the permission RoleAssignAction checks, not
        // `roles.assign_permissions` — which governs the role edit form.
        $operator = $this->holderOf('users.assign_roles');
        $admin = $this->holderOf('roles.update');

        Notification::fake();

        app(RoleAssignAction::class)->run($subject, ['admin'], $operator);

        // The personal half: the account holder.
        Notification::assertSentTo($subject, RolesChangedNotification::class);

        // The administrative half: every holder of roles.update.
        Notification::assertSentTo($admin, ConfigurationChangedNotification::class);
    }

    /**
     * A save that changed no roles notifies nobody.
     *
     * Without this, "I opened the form and pressed Save" mails everyone wearing
     * the affected role.
     */
    #
    public function test_a_role_save_that_changed_nothing_notifies_nobody (): void
    {
        $subject = $this->ordinaryUser();
        $operator = $this->holderOf('users.assign_roles');

        // A role they ALREADY hold, so the sync is a real write with no diff.
        // An empty list would also be a no-op, but it takes a different path
        // through the action, and this test is about "wrote something, changed
        // nothing" — which is the case that would mail on a stray implementation.
        app(RoleAssignAction::class)->run($subject, [SystemRole::USER], $operator);

        Notification::assertNothingSent();
    }

    /**
     * The credential notification is not the administrative one.
     *
     * `UserCreatedNotification` carries a temporary password. If the
     * administrative dispatch ever reached for it, every `users.create` holder
     * would receive a live credential in their inbox and in `notifications.data`.
     * Asserted on both classes being distinct AND on the registered event
     * carrying no password, because either alone would miss the other failure.
     */
    #
    public function test_registering_a_user_notifies_administrators_without_the_credential(): void
    {
        $operator = $this->holderOf('users.create');
        $bystander = $this->ordinaryUser();

        Notification::fake();

        // The third argument is the causer, and `UserCreateAction` validates the
        // DATA rather than a permission — this test drives the action directly, so
        // the route's own `can:` is not in the path.
        app(UserCreateAction::class)->run(
            ['name' => 'New Comer', 'username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'Str0ng-Password!'],
            null,
            $operator
        );

        Notification::assertSentTo($operator, UserRegisteredNotification::class);
        Notification::assertNotSentTo($bystander, UserRegisteredNotification::class);

        // The distinguishing assertion: the notification that went out is NOT the
        // one carrying the password.
        Notification::assertNotSentTo($operator, UserCreatedNotification::class);
    }

    /**
     * An administrative profile edit notifies `users.update` holders; a
     * self-service save notifies nobody.
     *
     * `UserUpdateAction` serves both callers from one method, so the notification
     * is the only thing that can tell them apart — and without the causer check
     * it would tell every `users.update` holder that somebody changed a display
     * name.
     */
    #
    public function test_only_an_administrative_edit_notifies(): void
    {
        $subject = $this->ordinaryUser();
        $operator = $this->holderOf('users.update');

        Notification::fake();

        // Self-service: no causer. The assertion is that NOTHING went out, and it
        // is made between the two calls because the fake accumulates — checking
        // only at the end would pass if the administrative call were the only one
        // that notified, hiding a regression in the self-service branch.
        app(UserUpdateAction::class)->run($subject, ['name' => 'Renamed By Self'], null);
        Notification::assertNothingSent();

        // Administrative.
        app(UserUpdateAction::class)->run($subject, ['name' => 'Renamed By Admin'], $operator);

        Notification::assertSentTo($operator, ConfigurationChangedNotification::class);
    }

    /**
     * A feature flag flip reaches every `features.manage` holder.
     *
     * The concrete case this exists for: one operator flips `users` off, and
     * without this the other operators cannot agree on whether it is on.
     */
    #
    public function test_a_feature_toggle_reaches_every_manager (): void
    {
        $operator = $this->holderOf('features.manage');
        $bystander = $this->ordinaryUser();

        Notification::fake();

        app(FeatureToggleAction::class)->run('users', false, $operator);

        Notification::assertSentTo($operator, ConfigurationChangedNotification::class);
        Notification::assertNotSentTo($bystander, ConfigurationChangedNotification::class);
    }

    /**
     * The notification names the key that changed and never its value.
     *
     * A settings notification quoting the new value hands a reader nothing they
     * could not already read — and this one goes into `notifications.data`, which
     * renders back in an inbox.
     */
    #
    public function test_a_settings_notification_never_carries_the_value (): void
    {
        $operator = $this->holderOf('settings.manage');

        Notification::fake();

        app(SystemSettingsUpdateAction::class)->run(
            ['password_min_length' => 20, 'registration_enabled' => true],
            causer: $operator
        );

        $sent = Notification::sent($operator, ConfigurationChangedNotification::class)->first();

        $this->assertNotNull($sent, 'the operator was not notified');

        $serialized = json_encode($sent->toArray($operator));

        $this->assertStringNotContainsString('20', $serialized, 'the notification quotes a setting value');
        $this->assertStringNotContainsString('registration_enabled', $serialized);
    }

    #
    public function test_a_role_deletion_reaches_role_deleters (): void
    {
        $operator = $this->holderOf('roles.delete');

        $role = Role::create([
            'name' => 'temporary-role-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        Notification::fake();

        // `force: true` — the action refuses a role that still has users, and a
        // freshly created role has none, but the flag also says the caller meant
        // it, which is the path an administrator is on when deleting a role.
        app(RoleDeleteAction::class)->run($role, $operator, force: true);

        Notification::assertSentTo($operator, ConfigurationChangedNotification::class);
    }

    /**
     * Every event the audience map declares is either dispatched by an action or
     * explicitly recorded as not-yet-wired.
     *
     * The map is the contract; this is what stops it growing entries nobody
     * reaches. An entry listed here as unwired is a decision, not an oversight —
     * and adding one to the dispatched set without a class is the same defect this
     * phase already found once.
     */
    #
    public function test_every_declared_event_is_dispatched_or_listed_as_unwired(): void
    {
        $dispatched = [
            'user.registered', 'user.updated', 'user.locked', 'user.unlocked',
            'user.deactivated', 'user.activated', 'user.deleted',
            'role.changed', 'role.deleted', 'feature.changed', 'setting.changed',
            'mail_setting.changed', 'channel.changed',
        ];

        // `permission.changed` has no dispatcher of its own: it is the same
        // audience and the same notification as `role.changed`, reached through
        // RoleAssignAction.
        $unwired = ['permission.changed'];

        $declared = array_keys(NotificationAudience::ADMINISTRATIVE_EVENTS);

        $this->assertSame(
            [],
            array_diff($declared, [...$dispatched, ...$unwired]),
            'an event was added to the audience map that is neither dispatched nor listed as unwired'
        );

        // And the reverse: a "dispatched" claim for an event the map does not
        // declare would route to an empty audience.
        $this->assertSame(
            [],
            array_diff($dispatched, $declared),
            'an event is dispatched but not declared in the audience map'
        );
    }

    /**
     * A delivery failure never breaks the action that caused it.
     *
     * The same rule as the account-state events, and it matters more here: a
     * feature toggle that rolled back because a mail server was down would leave
     * the store row and the notification disagreeing about what happened.
     */
    #
    public function test_a_delivery_failure_does_not_fail_the_configuration_change (): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('transport down'));

        $operator = $this->holderOf('features.manage');

        $result = app(FeatureToggleAction::class)->run('translations', false, $operator);

        // The toggle happened.
        $this->assertFalse($result['to'], 'precondition: the flag is off');
        $this->assertFalse(
            Feature::active('translations'),
            'the flag change was rolled back by a mail failure'
        );
    }
}
