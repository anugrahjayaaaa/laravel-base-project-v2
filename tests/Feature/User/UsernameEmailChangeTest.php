<?php

namespace Tests\Feature\User;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsernameEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('allow_email_change', 'true');
        SystemSetting::set('username_change_cooldown_days', '30');
        SystemSetting::set('email_change_cooldown_days', '30');
    }

    public function test_admin_can_update_username(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $user = User::factory()->create(['username' => 'oldname']);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => 'newname',
            'email' => $user->email,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', ['username' => 'newname']);
        $this->assertNotNull(User::find($user->id)->username_changed_at);
    }

    public function test_username_change_rejected_within_cooldown(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $user = User::factory()->create(['username' => 'oldname', 'username_changed_at' => now()]);

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => 'newname',
            'email' => $user->email,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertDatabaseMissing('users', ['username' => 'newname']);
    }

    public function test_email_change_sends_verification_to_pending_email(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $user = User::factory()->create(['username' => 'testuser123']);

        Notification::fake();
        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => 'testuser123',
            'email' => 'new@example.com',
            'status' => 'active',
        ]);

        $this->assertTrue($response->isRedirect() || $response->getStatusCode() === 302);
        Notification::assertSentTo($user, ChangeEmailVerificationNotification::class);
    }

    public function test_email_verification_link_updates_email_and_sets_timestamp(): void
    {
        $user = User::factory()->create();
        $token = \Illuminate\Support\Str::random(64);
        $user->update([
            'pending_email' => 'new@example.com',
            'email_change_token' => $token,
            'email_change_token_expires_at' => now()->addHours(24),
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'email.verify-change',
            now()->addHours(24),
            ['user' => $user, 'token' => $token]
        );

        $response = $this->actingAs($user)->get($url);


        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'pending_email' => null]);
        $this->assertNotNull(User::find($user->id)->email_changed_at);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_username_change_blocked_when_feature_disabled(): void
    {
        SystemSetting::set('allow_username_change', 'false');

        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $user = User::factory()->create(['username' => 'oldname']);

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'username' => 'newname',
            'email' => $user->email,
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['username' => 'oldname']);
    }

    public function test_login_works_with_username(): void
    {
        $user = User::factory()->create(['username' => 'johndoe', 'email' => 'john@example.com', 'password' => bcrypt('secret')]);

        $response = $this->post('/login', ['identifier' => 'johndoe', 'password' => 'secret']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_works_with_email(): void
    {
        $user = User::factory()->create(['username' => 'johndoe', 'email' => 'john@example.com', 'password' => bcrypt('secret')]);

        $response = $this->post('/login', ['identifier' => 'john@example.com', 'password' => 'secret']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }
}
