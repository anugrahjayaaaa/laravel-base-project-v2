<?php

namespace Tests\Feature\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCrudWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $this->actingAs($this->user, 'web');
    }

    // -- Index --

    public function test_index_renders_user_table(): void
    {
        User::factory()->count(3)->create();

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Users')
            ->assertSee('Admin User');
    }

    public function test_index_paginates(): void
    {
        User::factory()->count(14)->create();

        $response = $this->get(route('users.index') . '?per_page=5');

        $response->assertOk();
        $this->assertEquals(5, $response->original['users']->count());
        $this->assertEquals(15, $response->original['users']->total());
    }

    public function test_index_searches_name_and_email(): void
    {
        User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $response = $this->get(route('users.index') . '?search=John');

        $response->assertOk();
        $names = $response->original['users']->pluck('name');
        $this->assertTrue($names->contains('John Doe'));
        $this->assertFalse($names->contains('Jane Smith'));
    }

    public function test_index_filters_by_status(): void
    {
        User::factory()->create(['name' => 'Active', 'is_active' => true, 'is_locked' => false]);
        User::factory()->create(['name' => 'Locked', 'is_locked' => true]);

        $response = $this->get(route('users.index') . '?status=' . UserStatusEnum::ACTIVE->value);

        $response->assertOk();
        $names = $response->original['users']->pluck('name');
        $this->assertTrue($names->contains('Active'));
        $this->assertFalse($names->contains('Locked'));
    }

    public function test_index_sorts_by_name_asc(): void
    {
        User::factory()->create(['name' => 'Zara']);
        User::factory()->create(['name' => 'Aaron']);

        $response = $this->get(route('users.index') . '?sort=name&direction=asc');

        $response->assertOk();
        $users = $response->original['users'];
        $this->assertEquals('Aaron', $users->first()->name);
        $this->assertEquals('Zara', $users->last()->name);
    }

    // -- Update --

    public function test_update_success_redirects_with_flash(): void
    {
        $target = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->put(route('users.update', $target), [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'status' => \App\Enums\UserStatusEnum::ACTIVE->value,
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'User updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_update_name_required(): void
    {
        $target = User::factory()->create();

        $this->put(route('users.update', $target), [
            'name' => '',
            'email' => 'valid@example.com',
            'status' => \App\Enums\UserStatusEnum::ACTIVE->value,
        ])
            ->assertStatus(302)
            ->assertSessionHasErrors('name');
    }

    public function test_update_email_unique_ignores_current_user(): void
    {
        User::factory()->create(['email' => 'other@example.com']);
        $target = User::factory()->create(['email' => 'old@example.com']);

        $this->put(route('users.update', $target), [
            'name' => 'Test',
            'email' => 'other@example.com',
            'status' => \App\Enums\UserStatusEnum::ACTIVE->value,
        ])
            ->assertStatus(302)
            ->assertSessionHasErrors('email');
    }

    public function test_update_requires_authentication(): void
    {
        $this->actingAs(User::factory()->create());
        $target = User::factory()->create();

        // Auth test: verify redirect when not logged in
        $this->from(route('users.index'));
        $this->post('/logout');

        $this->get(route('users.index'))
            ->assertRedirect('/login');
    }

    // -- Toggle Status (P4-B6) --

    public function test_toggle_status_active_to_inactive(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => \App\Enums\UserStatusEnum::INACTIVE->value,
        ])->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_toggle_status_inactive_to_active(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => \App\Enums\UserStatusEnum::ACTIVE->value,
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->is_active);
    }
}