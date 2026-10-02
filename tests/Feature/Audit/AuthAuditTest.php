<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The auth audit rows, after AUD-007/008.
 *
 * Two properties matter here, and neither is covered by the User/System tests:
 *
 * 1. Every row carries the same context. The web and API halves of one story —
 *    "someone tried this account from this address" — could not be correlated
 *    before, because each of the 25 call sites assembled `ip` / `user_agent` /
 *    `channel` by hand and only some of them remembered the user agent.
 * 2. A failed login still names its target. There is no authenticated user to
 *    pass, so the row has to carry the identifier itself; a row that cannot say
 *    which account was tried is not useful for the incident it exists for.
 */
class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uses the factory so password, username and the active/locked flags match
     * what the login action actually accepts — a hand-built user with a password
     * the factory never sets fails with 403 and looks like a missing audit row.
     */
    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * The context every audit row must carry, on either channel.
     *
     * Asserted on `source` — the one key. The property being pinned is that
     * `Auditable::audit()` supplies the context, so this fails the moment anyone
     * moves it back out to a call site and starts assembling `source` by hand,
     * which is what made two rows incomparable before.
     */
    private function assertStandardContext(Activity $activity): void
    {
        $properties = $activity->properties->toArray();

        $this->assertArrayHasKey('ip', $properties, 'no ip recorded');
        $this->assertArrayHasKey('user_agent', $properties, 'no user agent recorded');
        $this->assertContains(
            $properties['source'] ?? null,
            ['web', 'api'],
            'no source recorded'
        );
        $this->assertArrayNotHasKey(
            'channel',
            $properties,
            'the retired `channel` key is still being written alongside `source`'
        );
    }

    public function test_a_successful_login_records_the_standard_context(): void
    {
        $user = $this->makeUser();

        $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $activity = Activity::where('event', 'auth.login')
            ->where('subject_id', $user->id)
            ->first();

        $this->assertNotNull($activity, 'no auth.login row was written');
        $this->assertStandardContext($activity);
        $this->assertSame('api', $activity->properties->toArray()['source'] ?? null);
    }

    public function test_a_failed_login_records_the_target_identifier(): void
    {
        $user = $this->makeUser();

        $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertUnauthorized();

        $activity = Activity::where('event', 'auth.login_failed')
            ->where('subject_id', $user->id)
            ->first();

        $this->assertNotNull($activity, 'no auth.login_failed row was written');

        $properties = $activity->properties->toArray();

        $this->assertSame($user->email, $properties['identifier'] ?? null);
        $this->assertStandardContext($activity);
    }

    /**
     * The login must not produce two rows: the controller used to audit a
     * successful login that the action had already recorded.
     */
    public function test_a_successful_login_writes_exactly_one_row(): void
    {
        $user = $this->makeUser();

        $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(
            1,
            Activity::where('event', 'auth.login')->where('subject_id', $user->id)->count(),
            'a successful login wrote more than one audit row'
        );
    }

    public function test_a_password_change_writes_exactly_one_row_with_context(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.auth.password.change'), [
                'current_password' => 'password',
                'password' => 'NewPassword456!',
                'password_confirmation' => 'NewPassword456!',
            ])
            ->assertOk();

        $count = Activity::where('event', 'auth.password_changed')
            ->where('subject_id', $user->id)
            ->count();

        $this->assertSame(1, $count, 'the password change wrote more than one audit row');

        $activity = Activity::where('event', 'auth.password_changed')
            ->where('subject_id', $user->id)
            ->first();

        $this->assertStandardContext($activity);
    }

    /**
     * A rejected password change must leave no audit row.
     *
     * The row is written inside the action's transaction, after the hash is
     * updated, so a failure earlier in that transaction leaves nothing behind.
     */
    public function test_a_rejected_password_change_leaves_no_audit_row(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.auth.password.change'), [
                'current_password' => 'not-the-current-password',
                'password' => 'NewPassword456!',
                'password_confirmation' => 'NewPassword456!',
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            Activity::where('event', 'auth.password_changed')->where('subject_id', $user->id)->count(),
            'a rejected password change still wrote an audit row'
        );
    }

    /**
     * Logout has no state change beyond ending the session, so it is the one
     * auth event that must still be recorded — losing it would mean losing the
     * only record that a session ended.
     *
     * Reached through the action rather than the route: the route is behind the
     * permission gate, so a factory user without a role cannot call it, and the
     * property under test is the audit row, not the gate. `SecurityAuditTest`
     * and `FeatureFlagRouteTest` already cover the route.
     *
     * The request resolves its user through the Sanctum guard rather than being
     * handed one. Neither `actingAs` nor a bare `Request::create()` with a user
     * resolver satisfies `Request::user()` — the action then sees no user, which
     * is exactly the case where it correctly writes no row, so the test would
     * pass while proving nothing.
     */
    public function test_a_logout_records_the_session_ending(): void
    {
        $user = $this->makeUser();
        $user->createToken('phpunit');

        $this->actingAs($user, 'sanctum');

        $request = \Illuminate\Http\Request::create('/api/v1/auth/logout', 'POST');
        $request->setUserResolver(fn () => auth()->guard('sanctum')->user() ?? $user);

        app(\App\Actions\V1\Auth\AuthLogoutAction::class)->run($request);

        $activity = Activity::where('event', 'auth.logout')
            ->where('subject_id', $user->id)
            ->first();

        $this->assertNotNull($activity, 'no auth.logout row was written');
        $this->assertStandardContext($activity);
    }

    /**
     * Context must come from `Auditable::audit()`, not from the call site.
     *
     * This is the property the whole consolidation exists for. Before it, each
     * caller assembled its own context and `source` was copied across six files
     * — under two different key names, one of them omitting the user agent, so
     * two rows describing the same kind of event could not be compared. Asserted
     * on a bare call with no properties at all: if any part of the context is
     * being supplied by the caller, this row will be missing it.
     */
    public function test_context_is_captured_without_the_caller_naming_it(): void
    {
        $user = $this->makeUser();

        $user->audit('test.bare_call');

        $activity = Activity::where('event', 'test.bare_call')->first();

        $this->assertNotNull($activity, 'the bare audit call wrote no row');

        $properties = $activity->properties->toArray();

        $this->assertSame('web', $properties['source'] ?? null, 'source not derived');
        $this->assertArrayHasKey('ip', $properties, 'ip not derived');
        $this->assertArrayHasKey('user_agent', $properties, 'user agent not derived');
    }

    /**
     * A caller may override a derived value — that is how a non-HTTP caller says
     * `system` rather than being mislabelled `web` for having no request.
     */
    public function test_a_caller_can_override_the_derived_source(): void
    {
        $user = $this->makeUser();

        $user->audit('test.overridden_source', null, ['source' => 'system']);

        $activity = Activity::where('event', 'test.overridden_source')->first();

        $this->assertNotNull($activity);
        $this->assertSame(
            'system',
            $activity->properties->toArray()['source'] ?? null,
            'the caller could not override the derived source'
        );
    }

    /**
     * Self-registration writes `user.registered`, never `user.created`. One
     * signup producing both is the exact double-audit AUD-001 fixed on the admin
     * side, reappearing through the registration callers of the same action.
     */
    public function test_registration_writes_registered_and_not_created(): void
    {
        \App\Models\SystemSetting::create(['key' => 'registration_enabled', 'value' => '1']);
        \App\Models\SystemSetting::bustCache();

        $this->postJson(route('api.v1.auth.register'), [
            'username' => 'newsignup',
            'name' => 'New Signup',
            'email' => 'new.signup@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $user = User::where('email', 'new.signup@example.test')->first();

        $this->assertNotNull($user);

        $this->assertSame(
            1,
            Activity::where('event', 'user.registered')->where('subject_id', $user->id)->count(),
            'registration did not write exactly one user.registered row'
        );

        $this->assertSame(
            0,
            Activity::where('event', 'user.created')->where('subject_id', $user->id)->count(),
            'a self-registration also wrote user.created'
        );

        $this->assertStandardContext(
            Activity::where('event', 'user.registered')->where('subject_id', $user->id)->first()
        );
    }
}
