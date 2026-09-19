<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResendVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_resend_rate_limited_after_5_attempts(): void
    {
        $user = User::factory()->unverified()->create(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('api.v1.auth.email.resend'), [
                'email' => $user->email,
            ]);
        }

        $this->postJson(route('api.v1.auth.email.resend'), [
            'email' => $user->email,
        ])->assertStatus(429);
    }
}