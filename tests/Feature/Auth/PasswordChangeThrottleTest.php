<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `throttle:password-change` — one bucket per user across both web forms and
 * the API route.
 */
class PasswordChangeThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_web_password_change_is_limited_per_user(): void
    {
        $user = User::factory()->create();

        // The limiter counts requests, not successes: a payload refused by
        // validation still spends the budget.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->put('/password/change', ['current_password' => 'wrong'])
                ->assertSessionHasErrors('current_password');
        }

        $this->actingAs($user)
            ->put('/password/change', ['current_password' => 'wrong'])
            ->assertSessionHas('rate_limit_seconds');
    }

    public function test_web_password_change_limit_does_not_cover_another_user(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->put('/password/change', ['current_password' => 'wrong']);
        }

        $this->actingAs(User::factory()->create())
            ->put('/password/change', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('rate_limit_seconds');
    }

    public function test_web_profile_form_shares_the_password_change_limiter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->put('/profile', ['name' => $user->name]);
        }

        $this->actingAs($user)
            ->put('/password/change', ['current_password' => 'wrong'])
            ->assertSessionHas('rate_limit_seconds');
    }

    public function test_api_password_change_is_limited_per_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/v1/auth/password/change', [
                'current_password' => 'wrong',
                'password' => 'short',
            ])->assertStatus(422);
        }

        $this->withToken($token)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'wrong',
            'password' => 'short',
        ])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }
}
