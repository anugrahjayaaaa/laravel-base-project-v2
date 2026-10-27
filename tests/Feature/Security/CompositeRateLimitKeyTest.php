<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P13 Group E2 — what a throttle bucket is keyed on, and what that buys.
 *
 * `ThrottleJsonResponseTest` pins the SHAPE of a refusal and the existing auth
 * tests pin that a limit exists at all. Neither answers the question this file
 * asks: two requests that look alike to a human — same address, same endpoint —
 * must land in the same bucket only when they share the key the limiter was
 * built on, and a different key must be a different budget.
 *
 * That distinction is the whole control. A per-identifier limiter that was
 * silently keyed on IP alone would still answer 429 in every existing test and
 * would still let one address spray a thousand accounts.
 */
class CompositeRateLimitKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * `login` is keyed on identifier + IP, so the same account from a second
     * address starts a fresh bucket. The account is still protected by the
     * durable `failed_login_attempts` row it built on the first address, and
     * the spray is what `login-ip` sees — this probe is about the key, not a
     * claim that rotating addresses is free.
     */
    public function test_login_is_keyed_on_identifier_and_ip_not_the_account_alone(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('api.v1.auth.login'), [
                'identifier' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            // The lockout is the endpoint's own 429, not the generic
            // UNAUTHENTICATED the shared error branch would produce.
            ->assertJsonPath('retry_after_seconds', fn ($seconds) => is_int($seconds) && $seconds > 0);

        // Second address, same account: a different key, so the limiter does
        // not answer for the first one. Credentials are judged again (401).
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('api.v1.auth.login'), [
                'identifier' => $user->email,
                'password' => 'wrong',
            ])->assertStatus(401);
    }

    /**
     * The other half of the composite key: one address is not one budget for
     * every account. Three requests for one address, then a fourth for a
     * different one, is the shape a spray does NOT have.
     */
    public function test_a_second_identifier_from_the_same_ip_gets_its_own_bucket(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('api.v1.auth.password.forgot'), [
                'email' => 'first@example.com',
            ])->assertStatus(200);
        }

        $this->postJson(route('api.v1.auth.password.forgot'), [
            'email' => 'first@example.com',
        ])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

        $this->postJson(route('api.v1.auth.password.forgot'), [
            'email' => 'second@example.com',
        ])->assertStatus(200);
    }

    /**
     * `reset-password` was documented at 3/min and never probed. The throttle
     * runs before validation, so the body is deliberately junk: what is being
     * measured is the bucket, not the reset flow.
     */
    public function test_reset_password_refuses_the_fourth_attempt_for_one_identifier(): void
    {
        $payload = [
            'token' => 'not-a-real-token',
            'email' => 'reset@example.com',
            'password' => 'not-checked-by-the-throttle',
            'password_confirmation' => 'not-checked-by-the-throttle',
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('api.v1.auth.password.reset'), $payload)->assertStatus(422);
        }

        $this->postJson(route('api.v1.auth.password.reset'), $payload)
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }

    /**
     * `email-verification` is keyed on the user and windows per hour, which is
     * the one limiter on this list whose window is not a minute. Reaching it
     * needs a signed URL, because `signed` runs before the throttle.
     */
    public function test_email_change_verification_is_throttled_per_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $url = URL::temporarySignedRoute(
            'api.v1.email.verify-change',
            now()->addHour(),
            ['user' => $user->getKey(), 'token' => 'not-a-real-token'],
        );

        for ($i = 0; $i < 5; $i++) {
            $this->getJson($url)->assertStatus(400);
        }

        $this->getJson($url)
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }
}
