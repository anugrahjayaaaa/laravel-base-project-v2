<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_show_profile_returns_own_user(): void
    {
        $this->getJson(route('api.v1.profile.show'))
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', $this->user->name);
    }

    public function test_update_profile_changes_name(): void
    {
        $response = $this->putJson(route('api.v1.profile.update'), [
            'name' => 'New Name',
        ])->assertStatus(200);

        $this->assertEquals('New Name', $this->user->fresh()->name);
        $this->assertEquals('New Name', $response->json('data.user.name'));
        $this->assertEquals('Profile updated successfully.', $response->json('data.message'));
    }

    public function test_update_profile_validates_email_unique(): void
    {
        User::factory()->create(['email' => 'other@example.com']);

        $this->putJson(route('api.v1.profile.update'), [
            'email' => 'other@example.com',
        ])->assertStatus(422);
    }
}