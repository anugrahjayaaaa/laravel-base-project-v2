<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Rules\PasswordStrengthRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    /** PasswordStrengthRule integration */

    public function test_password_strength_rule_rejects_short_password(): void
    {
        $rule = new PasswordStrengthRule();
        $fail = false;
        $rule->validate('password', 'Ab!', function ($msg) use (&$fail) {
            $fail = true;
        });
        $this->assertTrue($fail, 'PasswordStrengthRule should fail for short password missing complexity.');
    }

    public function test_password_strength_rule_accepts_strong_password(): void
    {
        $rule = new PasswordStrengthRule();
        $fail = false;
        $rule->validate('password', 'Str0ng#Pass!2024', function ($msg) use (&$fail) {
            $fail = true;
        });
        $this->assertFalse($fail, 'PasswordStrengthRule should pass for strong password.');
    }

    public function test_password_strength_rule_rejects_password_with_username(): void
    {
        $rule = new PasswordStrengthRule('johndoe');
        $fail = false;
        $rule->validate('password', 'johndoe123ABC!', function ($msg) use (&$fail) {
            $fail = true;
        });
        $this->assertTrue($fail, 'PasswordStrengthRule should fail when password contains username.');
    }

    /** View: auth/reset-password renders password-strength partial */

    public function test_reset_password_page_contains_strength_indicator(): void
    {
        SystemSetting::set('password_min_length', '12');
        SystemSetting::set('password_require_upper', 'true');
        SystemSetting::set('password_require_lower', 'true');
        SystemSetting::set('password_require_digit', 'true');
        SystemSetting::set('password_require_symbol', 'true');

        $response = $this->get('/reset-password');

        $response->assertStatus(200);
        $response->assertSee('id="password-strength-container"', false);
        $response->assertSee('data-policy="password-strength"', false);
    }

    /** View: profile/edit renders password-strength partial */

    public function test_profile_edit_page_contains_strength_indicator(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.show'));

        $response->assertStatus(200);
        $response->assertSee('id="password-strength-container"', false);
        $response->assertSee('data-policy="password-strength"', false);
    }

    /** FormRequest: PasswordResetRequest validates via PasswordStrengthRule */

    public function test_web_reset_password_rejects_short_password(): void
    {
        $response = $this->post('/reset-password', [
            'token' => 'test-token',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    /** FormRequest: PasswordChangeRequest validates via PasswordStrengthRule */

    public function test_web_change_password_rejects_short_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('CurrentP@ss1!'),
        ]);

        $response = $this->actingAs($user)->put('/password/change', [
            'current_password' => 'CurrentP@ss1!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    /** FormRequest: ProfileUpdateRequest validates password via PasswordStrengthRule */

    public function test_web_profile_update_rejects_short_new_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('CurrentP@ss1!'),
        ]);

        $response = $this->actingAs($user)->put('/profile', [
            'name' => 'Updated Name',
            'current_password' => 'CurrentP@ss1!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
