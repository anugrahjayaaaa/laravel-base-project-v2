<?php

namespace Tests\Feature\Api\V1\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The feature-flag API at its authorization boundary.
 *
 * Reading the catalogue is `features.view`; changing a flag is
 * `features.manage`. The sibling cases hold everything else, so a route gated
 * on the wrong one of the pair is refused here rather than passing because the
 * caller holds no permissions at all.
 *
 * The flag-management routes deliberately carry no `feature:{slug}` middleware
 * (turning a flag off must not delete the way to turn it back on); the
 * every-slug proof for that lives in
 * `Tests\Feature\FeatureFlag\FeatureFlagApiTest`.
 */
class FeatureApiTest extends TestCase
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
            'name' => 'api-feature-'.uniqid(),
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

    public function test_a_manager_can_toggle_a_flag(): void
    {
        Sanctum::actingAs($this->userWithout());

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertSuccessful()
            ->assertJsonPath('data.feature.to', false);

        $this->assertFalse(Feature::active('pulse'));
    }

    public function test_a_viewer_can_read_but_not_toggle(): void
    {
        Sanctum::actingAs($this->userWithout('features.manage'));

        $this->getJson(route('api.v1.features.index'))->assertSuccessful();

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue(Feature::active('pulse'));
    }

    public function test_a_manager_without_features_view_cannot_read_the_catalogue(): void
    {
        // Holds `features.manage`, so the refusal is the missing read
        // permission rather than an empty grant.
        Sanctum::actingAs($this->userWithout('features.view'));

        $this->getJson(route('api.v1.features.index'))->assertForbidden();
    }

    public function test_a_toggle_without_a_state_carries_the_validation_envelope(): void
    {
        Sanctum::actingAs($this->userWithout());

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'message',
                'errors' => ['enabled'],
                'code',
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
