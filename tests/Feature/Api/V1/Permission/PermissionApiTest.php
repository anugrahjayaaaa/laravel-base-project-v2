<?php

namespace Tests\Feature\Api\V1\Permission;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The permission catalogue API at its authorization boundary.
 *
 * The catalogue is read-only by design (P6-C7), so the only gate is
 * `permissions.view`. The sibling test therefore holds every permission the
 * catalogue defines except that one — a zero-permission caller would pass even
 * if the route were gated on the wrong name entirely.
 *
 * Deep behaviour (the role counts, the write-endpoint absence) lives in
 * `Tests\Feature\Role\RbacApiTest`.
 */
class PermissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * An ordinary role holding the whole catalogue except the given names.
     */
    private function userWithout(string ...$permissions): User
    {
        $role = AppRole::create([
            'name' => 'api-permission-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->givePermissionTo(
            Permission::whereIn('name', array_values(array_diff(PermissionCatalog::all(), $permissions)))
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_catalogue_reader_reads_the_listing(): void
    {
        Sanctum::actingAs($this->userWithout());

        // The catalogue is paginated; ask for enough rows that the assertion
        // is about the permission, not the default page size.
        $response = $this->getJson(route('api.v1.permissions.index', ['per_page' => 100]))->assertOk();

        $this->assertContains('users.view', collect($response->json('data.permissions'))->pluck('name'));
    }

    public function test_a_holder_of_every_other_permission_cannot_read_the_catalogue(): void
    {
        Sanctum::actingAs($this->userWithout('permissions.view'));

        $this->getJson(route('api.v1.permissions.index'))->assertForbidden();
    }

    public function test_an_unknown_sort_carries_the_validation_envelope(): void
    {
        Sanctum::actingAs($this->userWithout());

        $this->getJson(route('api.v1.permissions.index', ['sort' => 'password']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'message',
                'errors' => ['sort'],
                'code',
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
