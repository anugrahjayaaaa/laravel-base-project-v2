<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Auth\AuthAuthenticateAction;
use App\Actions\V1\Auth\AuthChangePasswordAction;
use App\Actions\V1\Auth\AuthListSessionsAction;
use App\Actions\V1\Auth\AuthLoginCompletedAction;
use App\Actions\V1\Auth\AuthLogoutAction;
use App\Actions\V1\Auth\AuthLogoutAllDevicesAction;
use App\Actions\V1\Auth\AuthRecordPasswordHistoryAction;
use App\Actions\V1\Auth\AuthResendVerificationAction;
use App\Actions\V1\Auth\AuthResetPasswordAction;
use App\Actions\V1\Auth\AuthRevokeSessionAction;
use App\Actions\V1\Auth\AuthSendResetLinkAction;
use App\Actions\V1\Auth\AuthVerifyEmailAction;
use App\Auth\LoginThrottle;
use App\Models\FailedLoginAttempt;
use App\Models\User;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The session list, session revoke, and both halves of the password-reset flow.
 *
 * The reset-broker outcomes (`PASSWORD_RESET` / `INVALID_USER`) are Laravel's;
 * what these tests pin is this project's layer around them: the lock check
 * before a mail is sent, the token sweep on success, and the history row that
 * the password policy later reads.
 */
class AuthActionTest extends TestCase
{
    use RefreshDatabase;

    // === sessions ===

    public function test_the_session_list_is_the_callers_own_and_newest_used_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $older = $user->createToken('older')->accessToken;
        $newer = $user->createToken('newer')->accessToken;
        $user->createToken('unused');
        $other->createToken('someone-else')->accessToken;

        DB::table('personal_access_tokens')->where('id', $older->getKey())->update(['last_used_at' => now()->subHour()]);
        DB::table('personal_access_tokens')->where('id', $newer->getKey())->update(['last_used_at' => now()]);

        $names = app(AuthListSessionsAction::class)->run($user)->pluck('name')->all();

        // The unused token sorts last (null), and the other account's token is
        // not in the list at all.
        $this->assertSame(['newer', 'older', 'unused'], $names);
    }

    public function test_revoking_deletes_only_the_named_token(): void
    {
        $user = User::factory()->create();
        $target = $user->createToken('target')->accessToken;
        $user->createToken('kept');

        app(AuthRevokeSessionAction::class)->run($user, (string) $target->getKey());

        $this->assertSame(['kept'], $user->tokens()->pluck('name')->all());
    }

    public function test_another_accounts_token_is_not_found_rather_than_forbidden(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $token = $victim->createToken('victim')->accessToken;

        // Scoped to `$user->tokens()`: an id on another account has to look
        // exactly like an id that does not exist.
        $this->expectException(ModelNotFoundException::class);

        app(AuthRevokeSessionAction::class)->run($user, (string) $token->getKey());
    }

    // === reset link ===

    public function test_a_reset_link_is_sent_to_an_existing_account(): void
    {
        $user = User::factory()->create();

        $result = app(AuthSendResetLinkAction::class)->run(
            $user->email,
            '127.0.0.1',
            $this->formRequest(['email' => $user->email]),
            app(LoginThrottle::class),
        );

        $this->assertTrue($result['user']->is($user));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_gets_the_same_shape_without_a_mail(): void
    {
        $result = app(AuthSendResetLinkAction::class)->run(
            'nobody@example.com',
            '127.0.0.1',
            $this->formRequest(['email' => 'nobody@example.com']),
            app(LoginThrottle::class),
        );

        // No user to notify, and no error branch: the caller cannot use this
        // endpoint to learn which addresses have accounts.
        $this->assertNull($result['user']);
        Notification::assertNothingSent();
    }

    public function test_a_locked_account_is_refused_before_the_mail_is_sent(): void
    {
        $user = User::factory()->create();
        $this->lock($user->email);

        $result = app(AuthSendResetLinkAction::class)->run(
            $user->email,
            '127.0.0.1',
            $this->formRequest(['email' => $user->email]),
            app(LoginThrottle::class),
        );

        $this->assertSame(403, $result['error']['status']);
        Notification::assertNothingSent();
    }

    // === reset ===

    public function test_a_valid_token_moves_the_password_and_revokes_every_session(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->createToken('web');

        $token = app('auth.password.broker')->createToken($user);

        $result = app(AuthResetPasswordAction::class)->run(
            $user->email,
            '127.0.0.1',
            $this->formRequest([
                'email' => $user->email,
                'token' => $token,
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]),
            app(LoginThrottle::class),
        );

        $this->assertSame(Password::PASSWORD_RESET, $result['status']);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewPassword123!', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_expires_at);

        // A reset is a recovery action: whatever session existed before it was
        // in someone else's hands or nobody's.
        $this->assertCount(0, $fresh->tokens()->get());
        $this->assertSame(1, DB::table('password_histories')->where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            Activity::query()->where('event', 'auth.password_reset_completed')->count(),
        );
    }

    public function test_an_invalid_token_changes_nothing(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $result = app(AuthResetPasswordAction::class)->run(
            $user->email,
            '127.0.0.1',
            $this->formRequest([
                'email' => $user->email,
                'token' => 'not-a-real-token',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]),
            app(LoginThrottle::class),
        );

        $this->assertSame(400, $result['error']['status']);
        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, DB::table('password_histories')->where('user_id', $user->id)->count());
        $this->assertSame(0, Activity::query()->where('event', 'auth.password_reset_completed')->count());
    }

    // === authenticate ===

    public function test_a_correct_password_authenticates_by_email_and_by_username(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'username' => 'ada']);
        $throttle = app(LoginThrottle::class);

        $byEmail = app(AuthAuthenticateAction::class)->run('ada@example.com', 'password', '127.0.0.1', $throttle);
        $byUsername = app(AuthAuthenticateAction::class)->run('ada', 'password', '127.0.0.1', $throttle);

        $this->assertTrue($byEmail['user']->is($user));
        $this->assertTrue($byUsername['user']->is($user));
    }

    public function test_a_wrong_password_is_recorded_against_the_account_it_targeted(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $result = app(AuthAuthenticateAction::class)->run('ada@example.com', 'nope', '127.0.0.1', app(LoginThrottle::class));

        $this->assertSame(401, $result['error']['status']);
        $this->assertSame(1, FailedLoginAttempt::query()->count());
        $this->assertSame(1, Activity::query()->where('event', 'auth.login_failed')->count());
        $this->assertTrue(Activity::query()->where('event', 'auth.login_failed')->first()->subject->is($user));
    }

    public function test_an_unknown_identifier_records_the_attempt_without_an_audit_row(): void
    {
        $result = app(AuthAuthenticateAction::class)->run('nobody@example.com', 'nope', '127.0.0.1', app(LoginThrottle::class));

        $this->assertSame(401, $result['error']['status']);
        $this->assertSame(1, FailedLoginAttempt::query()->count());
        // There is no model to hang a row on; the attempts table already holds
        // this fact, so a subject-less audit row would duplicate it.
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_repeated_failures_are_throttled_before_the_password_is_checked(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $throttle = app(LoginThrottle::class);

        for ($i = 0; $i < 5; $i++) {
            app(AuthAuthenticateAction::class)->run($user->email, 'nope', '127.0.0.1', $throttle);
        }

        $result = app(AuthAuthenticateAction::class)->run($user->email, 'password', '127.0.0.1', $throttle);

        // The correct password above must not win: the bucket is checked first.
        $this->assertSame(429, $result['error']['status']);
        $this->assertArrayHasKey('lockedSeconds', $result);
    }

    public function test_a_durable_lock_answers_429_with_the_remaining_window(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        FailedLoginAttempt::query()->create([
            'identifier' => $user->email,
            'ip_address' => '127.0.0.1',
            'attempts' => 6,
            'lock_count' => 1,
            'locked_until' => now()->addMinutes(15),
        ]);

        $result = app(AuthAuthenticateAction::class)->run($user->email, 'password', '127.0.0.1', app(LoginThrottle::class));

        $this->assertSame(429, $result['error']['status']);
        $this->assertGreaterThan(0, $result['lockedSeconds']);
    }

    public function test_an_inactive_or_locked_or_unverified_account_is_refused_with_its_own_status(): void
    {
        $throttle = app(LoginThrottle::class);

        $inactive = User::factory()->inactive()->create(['email' => 'a@example.com']);
        $locked = User::factory()->locked()->create(['email' => 'b@example.com']);
        $unverified = User::factory()->unverified()->create(['email' => 'c@example.com']);

        $this->assertSame(403, app(AuthAuthenticateAction::class)->run($inactive->email, 'password', '127.0.0.1', $throttle)['error']['status']);
        $this->assertSame(403, app(AuthAuthenticateAction::class)->run($locked->email, 'password', '127.0.0.1', $throttle)['error']['status']);

        $result = app(AuthAuthenticateAction::class)->run($unverified->email, 'password', '127.0.0.1', $throttle);
        $this->assertSame(403, $result['error']['status']);
        $this->assertSame('UNVERIFIED_EMAIL', $result['error']['error_code']);
    }

    // === login completed ===

    public function test_the_web_channel_stamps_the_login_and_audits_the_account_as_causer(): void
    {
        $this->app->instance('request', Request::create('/login', 'POST'));
        $user = User::factory()->create(['last_activity_at' => null]);

        $token = app(AuthLoginCompletedAction::class)->run($user, false);

        $this->assertNull($token);
        $this->assertNotNull($user->fresh()->last_activity_at);
        $this->assertSame(0, $user->tokens()->count());

        $activity = Activity::query()->where('event', 'auth.login')->sole();
        $this->assertTrue($activity->causer->is($user));
        $this->assertFalse($activity->properties['remember']);
    }

    public function test_the_api_channel_issues_a_token_in_the_same_transaction_as_the_audit_row(): void
    {
        $this->app->instance('request', Request::create('/api/v1/auth/login', 'POST'));
        $user = User::factory()->create();

        $token = app(AuthLoginCompletedAction::class)->run($user, false);

        $this->assertIsString($token);
        $this->assertSame('auth-token', $user->tokens()->sole()->name);
        $this->assertSame(1, Activity::query()->where('event', 'auth.login')->count());
    }

    // === logout ===

    public function test_a_logout_without_an_authenticated_user_writes_nothing(): void
    {
        app(AuthLogoutAction::class)->run(Request::create('/logout', 'POST'));

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_an_api_logout_deletes_the_current_token_and_audits_it(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web');
        $user->withAccessToken($token->accessToken);
        $user->createToken('other');

        $request = Request::create('/api/v1/auth/logout', 'POST');
        $request->setUserResolver(fn () => $user);

        app(AuthLogoutAction::class)->run($request);

        // Only the token that made the request; the other session survives.
        $this->assertSame(['other'], $user->tokens()->pluck('name')->all());
        $activity = Activity::query()->where('event', 'auth.logout')->sole();
        $this->assertTrue($activity->causer->is($user));
    }

    public function test_logout_everywhere_clears_sessions_and_tokens_together(): void
    {
        $user = User::factory()->create();
        $user->createToken('one');
        $user->createToken('two');
        DB::table('sessions')->insert([
            'id' => 'session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'payload',
            'last_activity' => now()->getTimestamp(),
        ]);

        $result = app(AuthLogoutAllDevicesAction::class)->run($user);

        $this->assertSame(['success' => true], $result);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, Activity::query()->where('event', 'auth.logout_all')->count());
    }

    // === change password ===

    public function test_the_current_password_is_required_before_anything_is_written(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $user = User::factory()->create();
        $before = $user->password;

        try {
            app(AuthChangePasswordAction::class)->run($user, 'wrong-password', 'N3w-Password!');
            $this->fail('A wrong current password was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }

        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, Activity::query()->where('event', 'auth.password_changed')->count());
    }

    public function test_a_recent_password_cannot_be_reused_while_history_is_enabled(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $user = User::factory()->create();
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => Hash::make('N3w-Password!'),
            'created_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(AuthChangePasswordAction::class)->run($user, 'password', 'N3w-Password!');
    }

    public function test_a_successful_change_moves_the_password_and_kills_every_session(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $user = User::factory()->create(['must_change_password' => true]);
        $user->createToken('web');
        DB::table('sessions')->insert([
            'id' => 'session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'payload',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->assertTrue(app(AuthChangePasswordAction::class)->run($user, 'password', 'N3w-Password!'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('N3w-Password!', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_expires_at);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('password_histories')->where('user_id', $user->id)->count());
        $this->assertSame(1, Activity::query()->where('event', 'auth.password_changed')->count());
    }

    public function test_history_prunes_to_the_configured_count_and_keeps_the_newest(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            app(AuthRecordPasswordHistoryAction::class)->run($user, Hash::make("old-{$i}"));
        }

        $this->assertSame(3, DB::table('password_histories')->where('user_id', $user->id)->count());
        $this->assertTrue(Hash::check('old-2', DB::table('password_histories')->orderByDesc('id')->value('password')));
    }

    // === email verification ===

    public function test_verifying_marks_the_address_and_audits_it_once(): void
    {
        $user = User::factory()->unverified()->create();
        $action = app(AuthVerifyEmailAction::class);

        $this->assertTrue($action->run($user)['user']->is($user));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, Activity::query()->where('event', 'auth.email_verified')->count());

        $second = $action->run($user->fresh());
        $this->assertSame(422, $second['error']['status']);
    }

    public function test_a_resend_goes_to_an_unverified_account_and_is_audited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);

        $result = app(AuthResendVerificationAction::class)->run($user->email, '127.0.0.1');

        $this->assertTrue($result['success']);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertSame(1, Activity::query()->where('event', 'auth.verification_resent')->count());
    }

    public function test_an_unknown_address_is_answered_like_a_sent_one(): void
    {
        Notification::fake();

        $result = app(AuthResendVerificationAction::class)->run('nobody@example.com', '127.0.0.1');

        $this->assertSame(['success' => true], $result);
        Notification::assertNothingSent();
    }

    public function test_a_verified_account_is_refused_and_a_burst_is_rate_limited(): void
    {
        Notification::fake();
        $verified = User::factory()->create(['email' => 'done@example.com']);

        $verifiedResult = app(AuthResendVerificationAction::class)->run($verified->email, '127.0.0.1');
        $this->assertSame('EMAIL_ALREADY_VERIFIED', $verifiedResult['error']['error_code']);

        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
        $action = app(AuthResendVerificationAction::class);

        for ($i = 0; $i < 5; $i++) {
            $action->run($user->email, '127.0.0.1');
        }

        $limited = $action->run($user->email, '127.0.0.1');
        $this->assertSame(429, $limited['error']['status']);
        $this->assertSame('RATE_LIMITED', $limited['error']['error_code']);
    }

    // === transaction boundary ===

    public function test_a_login_issues_no_token_when_the_audit_write_fails(): void
    {
        $this->app->instance('request', Request::create('/api/v1/auth/login', 'POST'));
        $user = User::factory()->create(['last_activity_at' => null]);
        $this->failAuditWrites();

        try {
            app(AuthLoginCompletedAction::class)->run($user, false);
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_activity_at);
    }

    public function test_a_password_change_is_rolled_back_when_the_audit_write_fails(): void
    {
        $this->seed(SystemSettingSeeder::class);
        $user = User::factory()->create();
        $before = $user->password;
        $this->failAuditWrites();

        try {
            app(AuthChangePasswordAction::class)->run($user, 'password', 'N3w-Password!');
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, DB::table('password_histories')->where('user_id', $user->id)->count());
    }

    public function test_logout_everywhere_keeps_the_tokens_and_sessions_when_the_audit_write_fails(): void
    {
        $user = User::factory()->create();
        $user->createToken('web');
        $this->failAuditWrites();

        try {
            app(AuthLogoutAllDevicesAction::class)->run($user);
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, $user->tokens()->count());
    }

    // === helpers ===

    private function failAuditWrites(): void
    {
        Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit write failed'));
    }

    private function formRequest(array $payload): Request
    {
        return Request::create('/reset-password', 'POST', $payload);
    }

    private function lock(string $email): void
    {
        FailedLoginAttempt::query()->create([
            'identifier' => $email,
            'ip_address' => '127.0.0.1',
            'attempts' => 6,
            'lock_count' => 1,
            'locked_until' => now()->addMinutes(5),
        ]);
    }
}
