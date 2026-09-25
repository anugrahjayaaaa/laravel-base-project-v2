<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\TimezoneSeeder;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemSettingUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Http::fake([
            'aisenseapi.com/*' => Http::response([
                'timezones' => [
                    ['timezone' => 'UTC', 'offset' => '+0000'],
                    ['timezone' => 'Asia/Jakarta', 'offset' => '+0700'],
                ],
            ]),
        ]);
    }

    public function test_update_settings_persists_to_database(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'allow_username_change' => 'on',
            'allow_email_change' => 'on',
            'username_change_cooldown_days' => 15,
            'email_change_cooldown_days' => 10,
        ]);

        $response->assertRedirect();
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertTrue(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(15, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(10, SystemSetting::getInt('email_change_cooldown_days'));
        $this->assertEquals('00:00', SystemSetting::getString('password_security_sweep_time'));
        $this->assertSame('', SystemSetting::getString('password_security_sweep_timezone'));

        $activity = Activity::query()
            ->where('description', 'system_setting.updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(SystemSetting::class, $activity->subject_type);
        $this->assertNotNull($activity->subject_id);
        $this->assertSame($user->id, $activity->causer_id);
    }

    public function test_sweep_schedule_settings_can_be_customized(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->seed(TimezoneSeeder::class);

        $response = $this->from('/settings')->post(route('settings.update'), [
            'password_security_sweep_time' => '07:30',
            'password_security_sweep_timezone' => 'Asia/Jakarta',
        ]);

        $response->assertRedirect();
        $this->assertSame('07:30', SystemSetting::getString('password_security_sweep_time'));
        $this->assertSame('Asia/Jakarta', SystemSetting::getString('password_security_sweep_timezone'));
    }

    public function test_sweep_schedule_rejects_invalid_timezone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'password_security_sweep_timezone' => 'Not/A_Timezone',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('password_security_sweep_timezone');
    }

    public function test_grace_toggle_disables_and_does_not_store_disabled_days(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'true');
        SystemSetting::set('inactivity_lock_grace_days', '45');

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'inactivity_lock_grace_enabled' => 'off',
            'inactivity_lock_grace_days' => 30,
        ]);

        $response->assertRedirect();
        $this->assertFalse(SystemSetting::getBool('inactivity_lock_grace_enabled'));
        $this->assertSame('45', SystemSetting::getString('inactivity_lock_grace_days'));
    }

    public function test_enabled_grace_toggle_stores_grace_days(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'inactivity_lock_grace_enabled' => '1',
            'inactivity_lock_grace_days' => 45,
        ]);

        $response->assertRedirect();
        $this->assertTrue(SystemSetting::getBool('inactivity_lock_grace_enabled'));
        $this->assertSame('45', SystemSetting::getString('inactivity_lock_grace_days'));
    }

    public function test_unchecked_checkboxes_store_false(): void
    {
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('allow_email_change', 'true');

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'username_change_cooldown_days' => 30,
            'email_change_cooldown_days' => 30,
        ]);

        $response->assertRedirect();
        $this->assertFalse(SystemSetting::getBool('allow_username_change'));
        $this->assertFalse(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(30, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(30, SystemSetting::getInt('email_change_cooldown_days'));
    }

    public function test_settings_page_disables_grace_days_when_toggle_is_off(): void
    {
        SystemSetting::set('inactivity_lock_grace_enabled', 'false');
        SystemSetting::bustCache();

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'web')->get(route('settings.index'));

        $response->assertOk()
            ->assertSee('id="inactivity_lock_grace_enabled"', false)
            ->assertSee('id="inactivity_lock_grace_days"', false);

        $this->assertMatchesRegularExpression(
            '/id="inactivity_lock_grace_days"[^>]*disabled/',
            $response->getContent()
        );
    }

    public function test_cache_is_invalidated_on_update(): void
    {
        // Warm the cache with initial values
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('allow_email_change', 'false');

        // Cache is now populated — verify cached reads return initial values
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertFalse(SystemSetting::getBool('allow_email_change'));

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        // Update via UI POST
        $response = $this->from('/settings')->post(route('settings.update'), [
            'allow_username_change' => 'on',
            'allow_email_change' => 'on',
            'username_change_cooldown_days' => 15,
            'email_change_cooldown_days' => 10,
        ]);

        $response->assertRedirect();

        // Assert cache key is invalidated (fresh read from DB)
        $this->assertTrue(SystemSetting::getBool('allow_username_change'));
        $this->assertTrue(SystemSetting::getBool('allow_email_change'));
        $this->assertEquals(15, SystemSetting::getInt('username_change_cooldown_days'));
        $this->assertEquals(10, SystemSetting::getInt('email_change_cooldown_days'));

        // Assert DB reflects the updated values directly
        $this->assertEquals('true', SystemSetting::where('key', 'allow_username_change')->value('value'));
        $this->assertEquals('true', SystemSetting::where('key', 'allow_email_change')->value('value'));
        $this->assertEquals('15', SystemSetting::where('key', 'username_change_cooldown_days')->value('value'));
        $this->assertEquals('10', SystemSetting::where('key', 'email_change_cooldown_days')->value('value'));
    }
}
