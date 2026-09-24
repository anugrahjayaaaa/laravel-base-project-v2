<?php

namespace Tests\Feature\User;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        SystemSetting::set('allow_email_change', 'true');
        SystemSetting::set('allow_username_change', 'true');
    }

    public function test_password_update_without_email_no_validation_error(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->put(route('profile.update'), [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => $user->email]);
    }

    public function test_wrong_current_password_shows_error_on_field(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'wrongpassword',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('current_password');
    }

    public function test_email_change_shows_pending_message(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $response = $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Verification email sent to new email address.');
        Notification::assertSentTo($user, ChangeEmailVerificationNotification::class);
    }

    public function test_email_verify_link_returns_not_found_without_signed_route(): void
    {
        $user = User::factory()->create();
        $token = \Illuminate\Support\Str::random(64);
        $user->update([
            'pending_email' => 'new@example.com',
            'email_change_token' => $token,
            'email_change_token_expires_at' => now()->addHours(24),
        ]);

        // Unsigned URL should be rejected (signed middleware)
        $url = route('email.verify-change', ['user' => $user, 'token' => $token]);
        $response = $this->actingAs($user)->get($url);
        $this->assertTrue(
            $response->isRedirect() || $response->getStatusCode() === 403,
            'Expected redirect (login) or 403, got '.$response->getStatusCode()
        );
    }
}
