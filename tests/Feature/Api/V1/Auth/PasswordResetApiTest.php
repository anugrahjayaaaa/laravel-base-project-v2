<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/v1/auth/password/reset
 *
 * The web address was already covered by `AuthControllerTest`; this is the API
 * one, which shares the same action and differs only in how it answers.
 */
class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $user, string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ], $overrides);
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $this->postJson(route('api.v1.auth.password.reset'), $this->payload($user, $token))
            ->assertOk()
            ->assertJsonPath('data.message', 'Password reset successfully.');

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.auth.password.reset'), $this->payload($user, 'not-a-real-token'))
            ->assertStatus(400)
            ->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }
}
