<?php

namespace Tests\Feature\Web\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class WebAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // === Existing tests ===

    public function test_web_login_renders_form(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200)
            ->assertViewIs('pages.auth.login');
    }

    public function test_web_login_succeeds(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->from('/login')
            ->post('/login', [
                'identifier' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_web_login_fails_invalid_credentials(): void
    {
        User::factory()->create();

        $response = $this->from('/login')
            ->post('/login', [
                'identifier' => 'wrong@example.com',
                'password' => 'wrong',
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('identifier');
    }

    public function test_web_login_fails_locked_user(): void
    {
        $user = User::factory()->locked()->create();

        $response = $this->from('/login')
            ->post('/login', [
                'identifier' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('identifier');
    }

    public function test_web_login_fails_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->from('/login')
            ->post('/login', [
                'identifier' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_web_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post('/logout');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_web_forgot_password_renders_form(): void
    {
        $response = $this->get('/forgot-password');
        $response->assertStatus(200)
            ->assertViewIs('pages.auth.forgot-password');
    }

    public function test_web_reset_password_renders_form(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $response = $this->get("/reset-password?email={$user->email}&token={$token}");
        $response->assertStatus(200)
            ->assertViewIs('pages.auth.reset-password');
    }

    // === Phase 3C: Email Verification Tests ===

    public function test_web_verify_email_signed_link_marks_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::signedRoute('verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertRedirect(route('verification.notice'))
            ->assertSessionHas('success');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_web_resend_rate_limited_after_threshold(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/email/resend', [
                'email' => $user->email,
            ]);
        }

        $response->assertStatus(429);
    }

    // Mail failure test requires notification sender mocking — not feasible with current tooling
}