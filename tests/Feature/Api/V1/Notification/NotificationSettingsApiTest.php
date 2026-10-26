<?php

namespace Tests\Feature\Api\V1\Notification;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PUT /api/v1/notifications/channels
 *
 * The web form had a test; the API address had none. The payload is the one
 * thing worth pinning: an unticked switch arrives as `'0'`, not as a missing
 * key, and the stored value has to follow it.
 */
class NotificationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::where('name', 'admin')->firstOrFail());

        Sanctum::actingAs($admin, ['*']);
    }

    public function test_an_administrator_can_update_the_channel_switches(): void
    {
        $this->putJson(route('api.v1.notifications.channels.update'), [
            'in_app' => true,
            'mail' => false,
            'database' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Notification channels updated.');

        $this->assertDatabaseHas('system_settings', ['key' => 'notification_channel_mail', 'value' => 'false']);
        $this->assertDatabaseHas('system_settings', ['key' => 'notification_channel_in_app', 'value' => 'true']);
    }
}
