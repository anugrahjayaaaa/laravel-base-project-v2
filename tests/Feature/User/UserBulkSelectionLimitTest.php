<?php

namespace Tests\Feature\User;

use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Requests\V1\User\BulkUserRequest;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Actions\V1\User\UserIndexAction;

/**
 * The bulk bar cannot select more than one page of users, so the request
 * refuses more than one page of ids.
 *
 * The web table's select-all is scoped to the rendered <table>
 * (resources/js/helpers/bulk-actions.js), so the UI tops out at the page size
 * and never trips this. The API shares the same BulkUserRequest, and before the
 * cap it accepted any list length: one authorised caller could post thousands
 * of ids and take a row lock per id inside a single transaction. These tests
 * pin the ceiling on the API channel, which is the one that can actually reach
 * it, and assert the boundary rather than a value well past it — a limit of 9
 * would pass an "11 is rejected" test and still break every real selection of 10.
 */
class UserBulkSelectionLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find('admin'));
        $this->actingAs($this->admin);
    }

    public function test_the_cap_matches_the_page_size(): void
    {
        $this->assertSame(10, BulkUserRequest::MAX_SELECTION);
        $this->assertSame(10, (int) (new UserIndexAction())->run(perPage: 10)->perPage());
    }

    public function test_api_accepts_a_full_page_and_rejects_one_more(): void
    {
        $users = User::factory()->count(BulkUserRequest::MAX_SELECTION)->create();

        $this->postJson(route('api.v1.users.bulk-action'), [
            'action' => 'lock',
            'user_ids' => $users->pluck('id')->all(),
        ])->assertSuccessful();

        $extra = User::factory()->create();

        $this->postJson(route('api.v1.users.bulk-action'), [
            'action' => 'lock',
            'user_ids' => $users->push($extra)->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('user_ids');

        // The rejected request must not have touched anything.
        $this->assertFalse($extra->fresh()->is_locked);
    }
}