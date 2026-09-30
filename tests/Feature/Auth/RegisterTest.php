<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\RegisterNotification;
use App\Notifications\UserCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Self-registration.
 *
 * The point of the feature is that it is a second front door to the same
 * account creation, so most of these assert that the two paths differ only
 * where they are supposed to.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
        // The superadmin role has to exist for the admin-path causer below:
        // Gate::before keys on the role, and an absent row grants nothing.
        $this->seed(RoleSeeder::class);
    }

    private function enable(): void
    {
        SystemSetting::set('registration_enabled', 'true');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Self Registered',
            'username' => 'selfreg',
            'email' => 'selfreg@example.com',
            'password' => 'ChosenP@ss1!',
            'password_confirmation' => 'ChosenP@ss1!',
        ], $overrides);
    }

    public function test_register_page_is_hidden_while_the_feature_is_off(): void
    {
        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.submit'), $this->payload())->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_page_renders_when_enabled(): void
    {
        $this->enable();

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_login_offers_a_register_link_only_when_enabled(): void
    {
        $this->get(route('login'))->assertDontSee('register');

        $this->enable();

        $this->get(route('login'))->assertSee('register', false);
    }

    public function test_a_user_can_register_and_must_verify_before_logging_in(): void
    {
        Notification::fake();
        $this->enable();

        $response = $this->post(route('register.submit'), $this->payload());

        $response->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $user = User::where('email', 'selfreg@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password, 'a chosen password needs no forced change');
        $this->assertTrue(Hash::check('ChosenP@ss1!', $user->password), 'the password they typed is the one stored');
    }

    public function test_the_verification_email_contains_no_password(): void
    {
        Notification::fake();
        $this->enable();

        $this->post(route('register.submit'), $this->payload());

        $user = User::where('email', 'selfreg@example.com')->firstOrFail();

        Notification::assertSentTo($user, RegisterNotification::class, function ($notification, $channels, $notifiable) {
            $rendered = $notification->toMail($notifiable)->render();

            return str_contains($rendered, 'Verify')
                && ! str_contains($rendered, 'ChosenP@ss1!');
        });
    }

    public function test_an_admin_created_user_still_gets_a_temporary_password(): void
    {
        Notification::fake();
        $this->enable();
        Role::findOrCreate('admin', 'web');

        // The same action, reached the admin way: no password argument.
        // causer: required since P6-C10 — a client-supplied `roles` key is a
        // grant, so the admin path checks users.assign_roles.
        $causer = \App\Models\User::factory()->create();
        $causer->assignRole(\App\Models\RoleLookup::find(\App\Support\SystemRole::SUPERADMIN));

        $user = app(\App\Actions\V1\User\UserCreateAction::class)->run([
            'name' => 'Admin Made',
            'username' => 'adminmade',
            'email' => 'adminmade@example.com',
            'roles' => ['admin'],
        ], causer: $causer);

        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole('admin'), 'the admin picks the roles, not the default');

        Notification::assertSentTo($user, UserCreatedNotification::class);
    }

    public function test_default_role_comes_from_the_setting(): void
    {
        Notification::fake();
        $this->enable();
        Role::findOrCreate('member', 'web');
        SystemSetting::set('registration_default_role', 'member');

        $this->post(route('register.submit'), $this->payload());

        $user = User::where('email', 'selfreg@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('member'));
    }

    public function test_no_role_is_assigned_when_the_default_is_cleared(): void
    {
        Notification::fake();
        $this->enable();
        Role::findOrCreate('member', 'web');
        SystemSetting::set('registration_default_role', '');

        $this->post(route('register.submit'), $this->payload());

        $this->assertCount(0, User::where('email', 'selfreg@example.com')->firstOrFail()->getRoleNames());
    }

    public function test_duplicate_username_is_rejected(): void
    {
        Notification::fake();
        $this->enable();
        User::factory()->create(['username' => 'taken']);

        $this->post(route('register.submit'), $this->payload(['username' => 'taken']))
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::where('username', 'taken')->count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Notification::fake();
        $this->enable();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register.submit'), $this->payload(['email' => 'taken@example.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_weak_password_is_rejected(): void
    {
        Notification::fake();
        $this->enable();

        $this->post(route('register.submit'), $this->payload([
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]))->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        Notification::fake();
        $this->enable();

        $this->post(route('register.submit'), $this->payload(['password_confirmation' => 'DifferentP@ss1!']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_rate_limited(): void
    {
        Notification::fake();
        $this->enable();
        SystemSetting::set('registration_rate_limit_per_minute', '2');

        $this->post(route('register.submit'), $this->payload())->assertRedirect(route('login'));
        $this->post(route('register.submit'), $this->payload([
            'username' => 'second', 'email' => 'second@example.com',
        ]))->assertRedirect(route('login'));

        $this->post(route('register.submit'), $this->payload([
            'username' => 'third', 'email' => 'third@example.com',
        ]))->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 2);
    }
}
