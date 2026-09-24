<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemSettingKeyConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_all_seed_keys_are_present_in_database(): void
    {
        $seederKeys = [
            'allow_email_change',
            'allow_username_change',
            'email_change_cooldown_days',
            'email_verification_expire_minutes',
            'email_verification_mode',
            'email_verification_rate_limit',
            'email_verification_token_expire_minutes',
            'lockout_base_minutes',
            'lockout_increment_minutes',
            'login_max_attempts',
            'login_rate_limit_per_minute',
            'password_expiration_days',
            'password_forgot_rate_limit',
            'password_history_count',
            'password_history_enabled',
            'password_min_length',
            'password_reject_username',
            'password_require_digit',
            'password_require_lower',
            'password_require_symbol',
            'password_require_upper',
            'password_reset_expire_minutes',
            'password_reset_rate_limit',
            'password_reset_token_expire_minutes',
            'username_change_cooldown_days',
        ];

        $missing = [];
        foreach ($seederKeys as $key) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if (!$exists) {
                $missing[] = $key;
            }
        }
        $this->assertEmpty($missing, 'Missing keys: ' . implode(', ', $missing));
    }

    public function test_no_auth_prefix_keys_in_database(): void
    {
        $authPrefixKeys = DB::table('system_settings')
            ->where('key', 'like', 'auth_%')
            ->pluck('key')
            ->toArray();

        $this->assertEmpty($authPrefixKeys, 'Found auth_ prefixed keys: ' . implode(', ', $authPrefixKeys));
    }

    public function test_system_setting_getters_return_correct_values(): void
    {
        $this->assertTrue(SystemSetting::getBool('password_history_enabled', true));
        $this->assertTrue(SystemSetting::getBool('password_history_enabled'));
        $this->assertEquals(5, SystemSetting::getInt('password_history_count', 5));
        $this->assertEquals(5, SystemSetting::getInt('password_history_count'));
        $this->assertEquals(5, SystemSetting::getInt('login_max_attempts'));
        $this->assertEquals(5, SystemSetting::getInt('lockout_base_minutes'));
    }

    public function test_update_setting_via_web_controller(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->from('/settings')->post(route('settings.update'), [
            'password_history_enabled' => 'on',
            'password_history_count' => 10,
            'login_max_attempts' => 7,
            'lockout_base_minutes' => 3,
        ]);

        $response->assertRedirect();

        SystemSetting::bustCache();

        $this->assertTrue(SystemSetting::getBool('password_history_enabled'));
        $this->assertEquals(10, SystemSetting::getInt('password_history_count'));
        $this->assertEquals(7, SystemSetting::getInt('login_max_attempts'));
        $this->assertEquals(3, SystemSetting::getInt('lockout_base_minutes'));
    }

    public function test_update_setting_via_api_controller(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->putJson(route('api.v1.settings.update'), [
            'password_history_enabled' => true,
            'password_history_count' => 8,
            'login_max_attempts' => 3,
            'lockout_base_minutes' => 2,
        ]);

        $response->assertStatus(200);

        SystemSetting::bustCache();

        $this->assertTrue(SystemSetting::getBool('password_history_enabled'));
        $this->assertEquals(8, SystemSetting::getInt('password_history_count'));
        $this->assertEquals(3, SystemSetting::getInt('login_max_attempts'));
        $this->assertEquals(2, SystemSetting::getInt('lockout_base_minutes'));
    }

    public function test_cache_busts_on_update(): void
    {
        $this->assertEquals(5, SystemSetting::getInt('login_max_attempts'));

        DB::table('system_settings')
            ->where('key', 'login_max_attempts')
            ->update(['value' => '99']);

        $this->assertEquals(5, SystemSetting::getInt('login_max_attempts'));

        SystemSetting::bustCache();

        $this->assertEquals(99, SystemSetting::getInt('login_max_attempts'));
    }

    public function test_validation_rules_match_expected_keys(): void
    {
        $expectedRules = [
            'login_max_attempts',
            'lockout_base_minutes',
            'lockout_increment_minutes',
            'login_rate_limit_per_minute',
            'password_forgot_rate_limit',
            'password_reset_rate_limit',
            'password_reset_token_expire_minutes',
            'email_verification_rate_limit',
            'email_verification_token_expire_minutes',
            'password_min_length',
            'password_mixed_case',
            'password_numbers',
            'password_symbols',
            'password_uncompromised',
            'password_history_enabled',
            'password_history_count',
            'password_expiration_days',
            'email_verification_expire_minutes',
            'email_verification_mode',
            'password_reset_expire_minutes',
            'allow_username_change',
            'username_change_cooldown_days',
            'allow_email_change',
            'email_change_cooldown_days',
        ];

        $request = new \App\Http\Requests\System\SystemSettingRequest();
        $rules = $request->rules();

        foreach ($expectedRules as $key) {
            $this->assertArrayHasKey($key, $rules, "Missing validation rule for: {$key}");
        }
    }

    public function test_password_policy_reads_settings_correctly(): void
    {
        $policy = new \App\Support\PasswordPolicy();

        $result = $policy->validate('Str0ngP@ssword123!');
        $this->assertIsArray($result);
        $this->assertEmpty($result, 'Strong password should pass all rules');

        $result = $policy->validate('Ab1!');
        $this->assertNotEmpty($result, 'Short password should fail');

        $result = $policy->validate('str0ngpassword123!');
        $this->assertNotEmpty($result, 'Password missing uppercase should fail');

        $result = $policy->validate('StrongPassword!');
        $this->assertNotEmpty($result, 'Password missing digit should fail');
    }

    public function test_email_verification_mode_values_are_valid(): void
    {
        $modes = ['public', 'admin', 'disabled'];
        
        foreach ($modes as $mode) {
            SystemSetting::set('email_verification_mode', $mode);
            SystemSetting::bustCache();
            $this->assertEquals($mode, SystemSetting::getString('email_verification_mode'));
        }

        // Reset to default
        SystemSetting::set('email_verification_mode', 'public');
    }

    public function test_password_history_count_boundary_values(): void
    {
        // Min boundary (0)
        SystemSetting::set('password_history_count', '0');
        SystemSetting::bustCache();
        $this->assertEquals(0, SystemSetting::getInt('password_history_count'));

        // Max boundary (24)
        SystemSetting::set('password_history_count', '24');
        SystemSetting::bustCache();
        $this->assertEquals(24, SystemSetting::getInt('password_history_count'));

        // Reset to default
        SystemSetting::set('password_history_count', '5');
    }
}
