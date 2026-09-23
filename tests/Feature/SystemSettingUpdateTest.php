<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingUpdateTest extends TestCase
{
    use RefreshDatabase;

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
}