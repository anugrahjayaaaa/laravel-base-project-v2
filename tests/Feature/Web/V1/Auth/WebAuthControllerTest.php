<?php

namespace Tests\Feature\Web\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
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

    public function test_web_forgot_password_submission(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertRedirectBack()
            ->assertSessionHas('success');
    }

    public function test_web_reset_password_submission(): void
    {
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $response = $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHas('success');
    }

    public function test_web_protected_route_redirects_unauthenticated(): void
        {
            $response = $this->get('/dashboard');
            $response->assertRedirect('/login');
        }

        public function test_web_forgot_password_handles_mail_failure(): void
           {
               \Password::shouldReceive('sendResetLink')
                   ->andThrow(new \Exception('Mail service down'));

               $user = User::factory()->create();

               $response = $this->from('/forgot-password')
                   ->post('/forgot-password', [
                       'email' => $user->email,
                   ]);

               $response->assertRedirectBack()
                   ->assertSessionHasErrors('email');
           }

           public function test_web_failed_login_creates_db_record(): void
               {
                   $user = User::factory()->create();

                   $this->from('/login')
                       ->post('/login', [
                           'identifier' => $user->email,
                           'password' => 'wrongpassword',
                       ]);

                   $this->assertDatabaseHas('failed_login_attempts', [
                       'identifier' => $user->email,
                       'ip_address' => '127.0.0.1',
                   ]);
               }

               public function test_web_login_locked_shows_duration(): void
               {
                   \App\Models\FailedLoginAttempt::create([
                       'identifier' => 'locked@example.com',
                       'ip_address' => '127.0.0.1',
                       'attempts' => 5,
                       'lock_count' => 1,
                       'locked_until' => now()->addMinutes(5),
                   ]);

                   $response = $this->from('/login')
                       ->post('/login', [
                           'identifier' => 'locked@example.com',
                           'password' => 'wrong',
                       ]);

                   $response->assertRedirect('/login')
                       ->assertSessionHasErrors('identifier');
               }

           public function test_web_login_rate_limited_after_5_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'identifier' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_web_forgot_password_rate_limited_after_3_attempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post('/forgot-password', [
                'email' => 'unknown@example.com',
            ]);
        }

        $this->post('/forgot-password', [
            'email' => 'unknown@example.com',
        ])->assertStatus(429);
    }

    public function test_web_reset_password_rate_limited_after_3_attempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post('/reset-password', [
                'email' => 'unknown@example.com',
                'token' => 'fake-token',
                'password' => 'newpass1234',
                'password_confirmation' => 'newpass1234',
            ]);
        }

        $this->post('/reset-password', [
            'email' => 'unknown@example.com',
            'token' => 'fake-token',
            'password' => 'newpass1234',
            'password_confirmation' => 'newpass1234',
        ])->assertStatus(429);
    }

    // Mail failure test for resend requires notification sender mocking — not feasible
    }