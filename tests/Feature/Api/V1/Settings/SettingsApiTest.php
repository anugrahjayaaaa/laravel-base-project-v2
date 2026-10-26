<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The settings API at its authorization boundary.
 *
 * Reading and writing are separate permissions (`settings.view` /
 * `settings.manage`), and the route gates are the only thing enforcing that
 * split on this channel. The two directions are asserted so a route gated on
 * the wrong sibling fails here even though the caller is not permission-less.
 *
 * Deep behaviour (persistence, audit rows, partial updates) lives in
 * `Tests\Feature\Settings\SystemSettingUpdateTest`.
 */
class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWith(string ...$permissions): User
    {
        $role = AppRole::create([
            'name' => 'api-settings-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_reader_can_read_the_settings(): void
    {
        Sanctum::actingAs($this->userWith('settings.view'));

        $this->getJson(route('api.v1.settings.index'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'timestamp']]);
    }

    public function test_a_reader_cannot_write_the_settings(): void
    {
        Sanctum::actingAs($this->userWith('settings.view'));

        $this->putJson(route('api.v1.settings.update'), ['login_max_attempts' => 7])
            ->assertForbidden();

        $this->assertNotSame(7, SystemSetting::getInt('login_max_attempts'));
    }

    public function test_a_writer_can_write_the_settings(): void
    {
        Sanctum::actingAs($this->userWith('settings.manage'));

        $this->putJson(route('api.v1.settings.update'), ['login_max_attempts' => 7])
            ->assertOk();

        $this->assertSame(7, SystemSetting::getInt('login_max_attempts'));
    }

    public function test_a_writer_cannot_read_the_settings(): void
    {
        // The other direction: manage must not imply view, the same split the
        // web page enforces (P6-D8).
        Sanctum::actingAs($this->userWith('settings.manage'));

        $this->getJson(route('api.v1.settings.index'))->assertForbidden();
    }

    public function test_an_out_of_bounds_setting_carries_the_validation_envelope(): void
    {
        Sanctum::actingAs($this->userWith('settings.manage'));

        $this->putJson(route('api.v1.settings.update'), ['login_max_attempts' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'message',
                'errors' => ['login_max_attempts'],
                'code',
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
