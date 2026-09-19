<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use RefreshDatabase;
    public function test_login_succeeds_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'email_verified',
                    ],
                ],
                'meta' => [
                    'request_id',
                    'timestamp',
                ],
            ])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.email_verified', true);
    }

    public function test_login_fails_with_invalid_email(): void
    {
        User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => 'nonexistent@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('data.message', 'Invalid credentials.');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('data.message', 'Invalid credentials.');
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('data.message', 'Account is inactive.');
    }

    public function test_login_fails_for_locked_user(): void
    {
        $user = User::factory()->locked()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('data.message', fn ($msg) => str($msg)->startsWith('Account is locked'));
    }

    public function test_login_fails_for_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Email not verified',
                'email' => $user->email,
                'verified' => false,
            ]);
    }

    public function test_api_login_rate_limited_after_5_attempts(): void
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
        ])->assertStatus(429);
    }
}