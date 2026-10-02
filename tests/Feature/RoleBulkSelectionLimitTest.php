<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Requests\V1\Role\BulkRoleRequest;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The roles bulk bar cannot select more than one page, so the request refuses
 * more than one page of ids.
 *
 * Same shape as UserBulkSelectionLimitTest, and the same reason: the select-all
 * is scoped to the rendered <table>, so the UI tops out at the page size and
 * never trips the cap. The API shares the request and could otherwise hand the
 * handler an unbounded list. The test pins the boundary rather than a value
 * well past it, because a limit of 9 would pass an "11 is rejected" test and
 * still break every real selection of 10.
 */
class RoleBulkSelectionLimitTest extends TestCase
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

    private function makeRole(int $i): Role
    {
        return Role::create(['name' => 'bulk-role-'.$i, 'guard_name' => RoleLookup::guard()]);
    }

    public function test_the_cap_matches_the_page_size(): void
    {
        $this->assertSame(10, BulkRoleRequest::MAX_SELECTION);
        $this->assertSame(10, (int) app(\App\Actions\V1\Role\RoleIndexAction::class)->run(perPage: 10)->perPage());
    }

    public function test_a_full_page_is_accepted_and_one_more_is_rejected(): void
    {
        $roles = collect(range(1, BulkRoleRequest::MAX_SELECTION))->map(fn ($i) => $this->makeRole($i));

        $this->post(route('roles.bulk-action'), [
            'action' => 'delete',
            'role_ids' => $roles->pluck('id')->all(),
        ])->assertRedirect();

        $extra = $this->makeRole(99);

        $response = $this->post(route('roles.bulk-action'), [
            'action' => 'delete',
            'role_ids' => $roles->push($extra)->pluck('id')->all(),
        ]);

        $response->assertSessionHasErrors('role_ids');

        // The rejected request must not have touched anything.
        $this->assertNotNull($extra->fresh());
    }
}