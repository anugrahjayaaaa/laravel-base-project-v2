<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\User\UserActivateAction;
use App\Actions\V1\User\UserAdminResendVerificationAction;
use App\Actions\V1\User\UserCancelEmailChangeAction;
use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserDeleteAction;
use App\Actions\V1\User\UserForceDeleteAction;
use App\Actions\V1\User\UserIndexAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserRequestEmailChangeAction;
use App\Actions\V1\User\UserRestoreAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Actions\V1\User\UserVerifyEmailChangeAction;
use App\Enums\UserStatusEnum;
use App\Exceptions\LastSuperadminException;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The unit contract of every User action: the state it writes, the audit row it
 * leaves, the refusals that protect the last superadmin, and the transaction
 * boundary that keeps a half-applied update from being audited as a success.
 */
class UserActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    // === activate ===

    public function test_activating_sets_the_flag_and_audits_the_target(): void
    {
        $user = $this->user(['is_active' => false]);

        app(UserActivateAction::class)->run($user, $this->admin());

        $this->assertTrue($user->fresh()->is_active);

        $row = $this->audit('user.activated');
        $this->assertSame($user->id, $row->subject_id);
        $this->assertSame($user->email, $row->properties['target_email']);
    }

    public function test_activating_a_locked_user_is_refused(): void
    {
        // Reachable through the update path, which passes `status=active` for an
        // account the lock buttons are the only legitimate way out of.
        $user = $this->user(['is_active' => false, 'is_locked' => true]);

        try {
            app(UserActivateAction::class)->run($user);
            $this->fail('A locked account was activated.');
        } catch (ValidationException $e) {
            $this->assertSame(['status'], array_keys($e->errors()));
        }

        $this->assertFalse($user->fresh()->is_active);
    }

    // === lock / deactivate ===

    public function test_locking_writes_the_flag_drops_tokens_and_audits(): void
    {
        $user = $this->user();
        $user->createToken('phone');

        app(UserLockAction::class)->run($user, $this->admin());

        $this->assertTrue($user->fresh()->is_locked);
        $this->assertCount(0, $user->tokens);
        $this->assertSame($user->email, $this->audit('user.locked')->properties['target_email']);
    }

    public function test_locking_can_leave_sessions_alone(): void
    {
        $user = $this->user();
        $user->createToken('phone');

        app(UserLockAction::class)->run($user, $this->admin(), invalidateSessions: false);

        $this->assertTrue($user->fresh()->is_locked);
        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_locking_an_inactive_user_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(UserLockAction::class)->run($this->user(['is_active' => false]));
    }

    public function test_locking_the_last_superadmin_is_refused(): void
    {
        $superadmin = $this->lastSuperadmin();

        $this->expectException(LastSuperadminException::class);

        // A locked superadmin cannot log in either, so locking is a third route
        // to the same lockout the role and delete guards close.
        app(UserLockAction::class)->run($superadmin, $this->admin());
    }

    public function test_deactivating_yourself_is_refused(): void
    {
        $admin = $this->admin();

        try {
            app(UserDeactivateAction::class)->run($admin, $admin);
            $this->fail('An admin deactivated their own account.');
        } catch (ValidationException $e) {
            $this->assertSame(['email'], array_keys($e->errors()));
        }
    }

    public function test_deactivating_a_locked_user_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(UserDeactivateAction::class)->run($this->user(['is_locked' => true]), $this->admin());
    }

    public function test_deactivating_the_last_superadmin_is_refused(): void
    {
        $this->expectException(LastSuperadminException::class);

        app(UserDeactivateAction::class)->run($this->lastSuperadmin(), $this->admin());
    }

    public function test_deactivating_clears_the_flag_and_audits(): void
    {
        $user = $this->user();

        app(UserDeactivateAction::class)->run($user, $this->admin());

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame($user->id, $this->audit('user.deactivated')->subject_id);
    }

    // === unlock ===

    public function test_unlocking_clears_the_flag_and_audits(): void
    {
        $user = $this->user(['is_locked' => true]);

        app(UserUnlockAction::class)->run($user, $this->admin());

        $this->assertFalse($user->fresh()->is_locked);
        $this->assertSame('user.unlocked', $this->audit('user.unlocked')->event);
    }

    // === delete / restore / force delete ===

    public function test_deleting_trashes_the_row_captures_identity_and_drops_tokens(): void
    {
        $user = $this->user();
        $user->createToken('phone');

        app(UserDeleteAction::class)->run($user, $this->admin());

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertCount(0, $user->tokens);

        // The address is captured before the delete: a trashed row renders as a
        // bare id in the audit list, and the address is what an operator searches.
        $row = $this->audit('user.deleted');
        $this->assertSame($user->email, $row->properties['target_email']);
    }

    public function test_deleting_yourself_is_refused(): void
    {
        $admin = $this->admin();

        $this->expectException(ValidationException::class);

        app(UserDeleteAction::class)->run($admin, $admin);
    }

    public function test_deleting_the_last_superadmin_is_refused(): void
    {
        $superadmin = $this->lastSuperadmin();

        $this->expectException(LastSuperadminException::class);

        app(UserDeleteAction::class)->run($superadmin, $this->admin());
    }

    public function test_restore_reactivates_by_default_and_records_it(): void
    {
        $user = $this->user(['is_active' => false]);
        $user->delete();

        app(UserRestoreAction::class)->run($user, true, $this->admin());

        $user->refresh();
        $this->assertNull($user->deleted_at);
        $this->assertTrue($user->is_active);
        $this->assertTrue($this->audit('user.restored')->properties['reactivated']);
    }

    public function test_restore_without_a_causer_writes_no_audit_row(): void
    {
        $user = $this->user();
        $user->delete();

        app(UserRestoreAction::class)->run($user, true, null);

        $this->assertNull($user->fresh()->deleted_at);
        $this->assertSame(0, Activity::query()->where('event', 'user.restored')->count());
    }

    public function test_force_delete_removes_the_row_and_keeps_its_identity_in_the_audit(): void
    {
        $user = $this->user();
        $user->delete();

        app(UserForceDeleteAction::class)->run($user, $this->admin());

        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        // After a hard delete nothing else records the account, so the identity
        // captured in the row is the only remaining trace.
        $row = $this->audit('user.force_deleted');
        $this->assertSame($user->username, $row->properties['target_username']);
    }

    public function test_force_delete_is_allowed_while_another_user_remains(): void
    {
        // The `count <= 1` refusal in the action cannot fire for any caller that
        // exists: the causer is itself a users row, so withTrashed() is at least
        // two. Recorded here rather than asserted as reachable — the value of
        // this test is that the allowed direction is proven.
        $target = $this->user();
        $target->delete();

        app(UserForceDeleteAction::class)->run($target, $this->admin());

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    // === email change ===

    public function test_requesting_an_email_change_stores_a_hashed_token_and_mails_the_new_address(): void
    {
        $user = $this->user();

        app(UserRequestEmailChangeAction::class)->run($user, 'new@example.test', $this->admin());

        $user->refresh();
        $this->assertSame('new@example.test', $user->pending_email);
        // Hashed: a database read must not hand over a working confirmation link.
        $this->assertSame(64, strlen($user->email_change_token));
        $this->assertNotSame('', $user->email_change_token);
        $this->assertSame($user->email, $user->getEmailForVerification(), 'the live address must not move yet');

        Notification::assertSentOnDemand(ChangeEmailVerificationNotification::class);
    }

    public function test_a_wrong_email_change_token_changes_nothing(): void
    {
        $user = $this->user();
        app(UserRequestEmailChangeAction::class)->run($user, 'new@example.test');

        $this->assertFalse(app(UserVerifyEmailChangeAction::class)->run($user->fresh(), 'wrong-token'));
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_a_valid_email_change_token_moves_the_address_and_revokes_tokens(): void
    {
        $user = $this->user();
        $user->createToken('phone');

        $token = 'plain-token-value';
        $user->forceFill([
            'pending_email' => 'new@example.test',
            'email_change_token' => hash('sha256', $token),
            'email_change_token_expires_at' => now()->addHour(),
        ])->save();

        $applied = app(UserVerifyEmailChangeAction::class)->run($user->fresh(), $token);

        $this->assertTrue($applied);
        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
        $this->assertCount(0, $user->tokens);
        $this->assertSame('new@example.test', $this->audit('user.email_changed')->properties['new_email']);
    }

    public function test_an_expired_email_change_token_is_refused(): void
    {
        $user = $this->user();
        $token = 'plain-token-value';
        $user->forceFill([
            'pending_email' => 'new@example.test',
            'email_change_token' => hash('sha256', $token),
            'email_change_token_expires_at' => now()->subMinute(),
        ])->save();

        $this->assertFalse(app(UserVerifyEmailChangeAction::class)->run($user->fresh(), $token));
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_cancelling_records_the_address_it_was_aimed_at(): void
    {
        $user = $this->user();
        app(UserRequestEmailChangeAction::class)->run($user, 'attacker@example.test');

        app(UserCancelEmailChangeAction::class)->run($user->fresh(), $this->admin());

        $user->refresh();
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
        $this->assertSame(
            'attacker@example.test',
            $this->audit('user.email_change_cancelled')->properties['cancelled_pending_email']
        );
    }

    // === admin resend ===

    public function test_resending_is_refused_when_verification_is_disabled(): void
    {
        SystemSetting::set('email_verification_mode', 'disabled');

        $result = app(UserAdminResendVerificationAction::class)->run($this->unverified(), '127.0.0.1');

        $this->assertSame(403, $result['error']['status']);
        $this->assertSame(0, Activity::query()->where('event', 'user.verification_resent')->count());
    }

    public function test_resending_is_refused_for_an_already_verified_account(): void
    {
        $result = app(UserAdminResendVerificationAction::class)->run($this->user(), '127.0.0.1');

        $this->assertSame(422, $result['error']['status']);
        $this->assertSame('EMAIL_ALREADY_VERIFIED', $result['error']['error_code']);
    }

    public function test_resending_is_rate_limited(): void
    {
        $user = $this->unverified();
        SystemSetting::set('email_verification_rate_limit', '1');

        $action = app(UserAdminResendVerificationAction::class);
        $action->run($user, '10.0.0.1');
        $second = $action->run($user, '10.0.0.1');

        $this->assertSame(429, $second['error']['status']);
        RateLimiter::clear(sha1(strtolower(trim($user->email)).'|10.0.0.1'));
    }

    public function test_a_successful_resend_is_audited_with_the_destination(): void
    {
        $user = $this->unverified();

        app(UserAdminResendVerificationAction::class)->run($user, '127.0.0.1', $this->admin());

        $this->assertSame($user->email, $this->audit('user.verification_resent')->properties['target_email']);
    }

    // === create / update ===

    public function test_creating_without_a_password_forces_a_change_on_first_login(): void
    {
        $user = app(UserCreateAction::class)->run($this->payload(), null, $this->admin());

        $this->assertTrue($user->must_change_password);
        // A generated password is never typed by the user, so it must not enter
        // the reuse history — that would block a string they never chose.
        $this->assertDatabaseCount('password_histories', 0);
        $this->assertSame($user->id, $this->audit('user.created')->subject_id);
    }

    public function test_creating_with_a_chosen_password_records_it_in_the_history(): void
    {
        $user = app(UserCreateAction::class)->run($this->payload(), 'Chosen-Passw0rd!', $this->admin());

        $this->assertFalse($user->must_change_password);
        $this->assertDatabaseHas('password_histories', ['user_id' => $user->id]);
    }

    public function test_self_registration_audits_as_registered_not_created(): void
    {
        app(UserCreateAction::class)->run($this->payload());

        $this->assertSame(0, Activity::query()->where('event', 'user.created')->count());
        $this->assertSame(1, Activity::query()->where('event', 'user.registered')->count());
    }

    public function test_creation_rolls_back_when_the_role_grant_is_refused(): void
    {
        // The real refusal path, not a double: RoleAssignAction throws inside the
        // transaction after the user row was inserted. Either both persist or
        // neither does — an account created on no roles because the throw was
        // swallowed would be the silent version of this.
        $payload = $this->payload() + ['roles' => [SystemRole::ADMIN]];

        try {
            app(UserCreateAction::class)->run($payload, null, $this->user());
            $this->fail('An unprivileged causer assigned roles.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->assertSame(0, Activity::query()->where('event', 'user.created')->count());
    }

    public function test_updating_strips_markup_from_the_name(): void
    {
        $user = $this->user();

        app(UserUpdateAction::class)->run($user, ['name' => '<b>Bob</b>'], $this->admin());

        $this->assertSame('Bob', $user->fresh()->name);
    }

    public function test_updating_rolls_back_the_name_when_the_role_grant_is_refused(): void
    {
        $user = $this->user();
        $before = $user->name;

        try {
            app(UserUpdateAction::class)->run(
                $user,
                ['name' => 'Renamed', 'roles' => [SystemRole::ADMIN]],
                $this->user()
            );
            $this->fail('An unprivileged causer assigned roles.');
        } catch (AuthorizationException) {
            // expected
        }

        // The name write came first and used to persist on its own; the audit row
        // would then describe a save that did not fully happen.
        $this->assertSame($before, $user->fresh()->name);
        $this->assertSame(0, Activity::query()->where('event', 'user.updated')->count());
    }

    public function test_updating_status_routes_through_the_state_actions(): void
    {
        $user = $this->user();

        app(UserUpdateAction::class)->run(
            $user,
            ['status' => UserStatusEnum::INACTIVE->value],
            $this->admin()
        );

        // A second writer of `is_active` would skip the self/deactivate/last
        // superadmin guards; the deactivation event is the proof it did not.
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(1, Activity::query()->where('event', 'user.deactivated')->count());
    }

    public function test_updating_an_email_opens_the_change_flow_instead_of_moving_the_address(): void
    {
        $user = $this->user();

        app(UserUpdateAction::class)->run($user, ['email' => 'moved@example.test'], $this->admin());

        $user->refresh();
        $this->assertSame('moved@example.test', $user->pending_email);
        $this->assertNotSame('moved@example.test', $user->email);
        Notification::assertSentOnDemand(ChangeEmailVerificationNotification::class);
    }

    public function test_updating_without_a_roles_key_leaves_the_roles_alone(): void
    {
        $user = $this->user();
        $user->assignRole(SystemRole::ADMIN);

        app(UserUpdateAction::class)->run($user, ['name' => 'Renamed'], $this->admin());

        $this->assertTrue($user->fresh()->hasRole(SystemRole::ADMIN));
    }

    public function test_the_self_service_save_audits_as_profile_updated(): void
    {
        $user = $this->user();

        app(UserUpdateAction::class)->run($user, ['name' => 'Renamed'], null);

        $row = $this->audit('user.profile_updated');
        $this->assertSame($user->id, $row->causer_id, 'the account that saved is the actor');
        $this->assertSame('Renamed', $row->properties['name']['to']);
    }

    // === index ===

    public function test_an_unknown_sort_column_falls_back_to_the_default_order_instead_of_reaching_order_by(): void
    {
        $older = $this->user();
        $newer = $this->user();

        $page = app(UserIndexAction::class)->run('', 'active', 'email); drop table users', 'asc', 100, $this->admin());

        // The whitelist replaces the value; the order clause still applies.
        $this->assertSame($older->getKey(), $page->items()[0]->getKey());
        $this->assertSame($newer->getKey(), $page->items()[1]->getKey());
    }

    public function test_the_superadmin_account_is_hidden_from_a_plain_viewer(): void
    {
        $superadmin = $this->lastSuperadmin();
        $viewer = $this->user();

        $idsForViewer = collect(app(UserIndexAction::class)->run(perPage: 100, viewer: $viewer)->items())->pluck('id');
        $idsForSuperadmin = collect(app(UserIndexAction::class)->run(perPage: 100, viewer: $superadmin)->items())->pluck('id');

        $this->assertNotContains($superadmin->getKey(), $idsForViewer);
        $this->assertContains($superadmin->getKey(), $idsForSuperadmin);
    }

    // === helpers ===

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function unverified(): User
    {
        return User::factory()->unverified()->create();
    }

    private function admin(): User
    {
        $admin = $this->user();
        $admin->assignRole(SystemRole::ADMIN);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function lastSuperadmin(): User
    {
        $superadmin = $this->user();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        return $superadmin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'username' => 'newperson',
        ];
    }

    private function audit(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }
}
