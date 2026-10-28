<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
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

    /**
     * Settings owned by an inactive feature flag are excluded from the API read.
     *
     * `send_test_mail_rate_limit_per_minute` feeds the notifications test-mail
     * route, which 403s when the flag is off. Returning the value anyway lets an
     * operator inspect a ceiling they can never reach — a read-only leak.
     */
    public function test_api_index_excludes_settings_owned_by_a_disabled_feature(): void
    {
        $this->seed(SystemSettingSeeder::class);

        // Precondition: the setting exists and the feature is on, so the
        // absence in the disabled response is the flag's effect, not a missing
        // key to begin with.
        $this->assertTrue(
            SystemSetting::getLive()['send_test_mail_rate_limit_per_minute'] === '5',
            'precondition: the notifications setting is live when the flag is on'
        );

        Feature::deactivate('notifications');
        Feature::flushCache();

        Sanctum::actingAs($this->userWith('settings.view'));

        $data = $this->getJson(route('api.v1.settings.index'))
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey(
            'send_test_mail_rate_limit_per_minute',
            $data,
            'a setting whose feature flag is off must not appear in the API read'
        );
    }

    /**
     * The same exclusion applies to the POST/PUT response body — a client must
     * not get back a value it just wrote to a disabled-feature key.
     */
    public function test_api_update_response_excludes_disabled_feature_settings(): void
    {
        $this->seed(SystemSettingSeeder::class);
        Feature::deactivate('notifications');
        Feature::flushCache();

        Sanctum::actingAs($this->userWith('settings.manage'));

        $data = $this->putJson(route('api.v1.settings.update'), ['login_max_attempts' => 7])
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey(
            'send_test_mail_rate_limit_per_minute',
            $data,
            'a disabled-feature setting must not echo back in the write response'
        );
    }

    /**
     * The write itself is still a no-op — the setting is not persisted.
     */
    public function test_api_update_to_a_disabled_feature_setting_is_a_noop(): void
    {
        $this->seed(SystemSettingSeeder::class);
        Feature::deactivate('notifications');
        Feature::flushCache();

        Sanctum::actingAs($this->userWith('settings.manage'));

        $this->putJson(route('api.v1.settings.update'), ['send_test_mail_rate_limit_per_minute' => 99])
            ->assertOk();

        $this->assertSame(
            '5',
            SystemSetting::getString('send_test_mail_rate_limit_per_minute'),
            'writing to a disabled-feature setting must not persist'
        );
    }
}
