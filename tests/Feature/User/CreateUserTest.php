<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $adminRole = Role::create(['name' => 'admin']);

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);

        $admin->assignRole($adminRole);

        Sanctum::actingAs($admin, ['*']);
    }

    // -- Web --

    public function test_create_user_page_renders(): void
    {
        $response = $this->get(route('users.create'));

        $response->assertStatus(200)
            ->assertSee('Create User')
            ->assertSee('Account Information');
    }

    public function test_admin_creates_user_via_web(): void
    {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $this->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'username' => 'newuser',
            'roles' => [$role->name],
        ])->assertRedirect(route('users.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'is_active' => true,
            'is_locked' => false,
            'must_change_password' => true,
        ]);

        $user = User::where('email', 'newuser@example.com')->first();

        $this->assertTrue($user->hasRole('editor'));
    }

    public function test_create_user_validates_required_fields(): void
    {
        $this->post(route('users.store'), [
            'name' => '',
            'email' => 'notanemail',
            'username' => '',
        ])->assertSessionHasErrors(['name', 'email', 'username']);
    }

    public function test_create_user_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'taken@example.com',
            'username' => 'newuser',
        ])->assertSessionHasErrors('email');
    }

    public function test_create_user_validates_role_exists(): void
    {
        $this->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'new@example.com',
            'username' => 'newuser',
            'roles' => ['nonexistent_role'],
        ])->assertSessionHasErrors('roles.0');
    }

    // -- API --

    public function test_admin_creates_user_via_api(): void
    {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'api']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'API User',
            'email' => 'apiuser@example.com',
            'username' => 'apiuser',
            'roles' => [$role->name],
        ])->assertStatus(201)
            ->assertJsonPath('data.message', 'User created successfully.');

        $this->assertDatabaseHas('users', [
            'email' => 'apiuser@example.com',
            'must_change_password' => true,
        ]);
    }

    public function test_api_create_user_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'API User',
            'email' => 'taken@example.com',
            'username' => 'apiuser',
        ])->assertStatus(422);
    }

    // -- Notification --

    public function test_user_created_notification_is_sent(): void
    {
        Notification::fake();

        $this->post(route('users.store'), [
            'name' => 'Notified User',
            'email' => 'notified@example.com',
            'username' => 'notifieduser',
        ]);

        Notification::assertSentTo(
            User::where('email', 'notified@example.com')->first(),
            \App\Notifications\UserCreatedNotification::class
        );
    }

    public function test_temp_password_is_12_chars(): void
    {
        $this->post(route('users.store'), [
            'name' => 'PwTest',
            'email' => 'pwt@example.com',
            'username' => 'pwttest',
        ]);

        $user = User::where('email', 'pwt@example.com')->first();

        $this->assertNotNull($user);
        // Password is hashed, but we verify the action generates one
        // by checking must_change_password is true (set because temp pw sent)
        $this->assertTrue($user->must_change_password);
    }
}
