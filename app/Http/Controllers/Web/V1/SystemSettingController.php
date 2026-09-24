<?php

namespace App\Http\Controllers\Web\V1;

use App\Models\SystemSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemSettingRequest;
use Illuminate\Http\RedirectResponse;

/**
 * System settings controller — view and update platform settings.
 */
class SystemSettingController extends Controller
{
    /**
     * Show the settings page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        $settings = SystemSetting::getAll();

        return view('pages.settings.index', compact('settings'));
    }

    /**
     * Update system settings.
     *
     * @param  SystemSettingRequest  $request
     * @return RedirectResponse
     */
    public function update(SystemSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $updates = [
            // Login rate limiting & progressive lockout
            'login_max_attempts' => (string) ($data['login_max_attempts'] ?? 5),
            'lockout_base_minutes' => (string) ($data['lockout_base_minutes'] ?? 5),
            'lockout_increment_minutes' => (string) ($data['lockout_increment_minutes'] ?? 10),
            'login_rate_limit_per_minute' => (string) ($data['login_rate_limit_per_minute'] ?? 5),

            // Password reset / verification rate limits
            'password_forgot_rate_limit' => (string) ($data['password_forgot_rate_limit'] ?? 3),
            'password_reset_rate_limit' => (string) ($data['password_reset_rate_limit'] ?? 3),
            'password_reset_token_expire_minutes' => (string) ($data['password_reset_token_expire_minutes'] ?? 15),
            'email_verification_rate_limit' => (string) ($data['email_verification_rate_limit'] ?? 5),
            'email_verification_token_expire_minutes' => (string) ($data['email_verification_token_expire_minutes'] ?? 60),

            // Password policy & lifecycle
            'password_min_length' => (string) ($data['password_min_length'] ?? 8),
            'password_mixed_case' => ($data['password_mixed_case'] ?? false) ? 'true' : 'false',
            'password_numbers' => ($data['password_numbers'] ?? false) ? 'true' : 'false',
            'password_symbols' => ($data['password_symbols'] ?? false) ? 'true' : 'false',
            'password_uncompromised' => ($data['password_uncompromised'] ?? false) ? 'true' : 'false',
            'password_history_enabled' => ($data['password_history_enabled'] ?? false) ? 'true' : 'false',
            'password_history_count' => (string) ($data['password_history_count'] ?? 5),
            'password_expiration_days' => (string) ($data['password_expiration_days'] ?? 90),

            // Email verification
            'email_verification_expire_minutes' => (string) ($data['email_verification_expire_minutes'] ?? 60),
            'email_verification_mode' => $data['email_verification_mode'] ?? 'public',

            // Password reset token (Laravel broker)
            'password_reset_expire_minutes' => (string) ($data['password_reset_expire_minutes'] ?? 15),

            // Username / email change settings
            'allow_username_change' => ($data['allow_username_change'] ?? false) ? 'true' : 'false',
            'username_change_cooldown_days' => (string) ($data['username_change_cooldown_days'] ?? 30),
            'allow_email_change' => ($data['allow_email_change'] ?? false) ? 'true' : 'false',
            'email_change_cooldown_days' => (string) ($data['email_change_cooldown_days'] ?? 30),
        ];

        foreach ($updates as $key => $value) {
            SystemSetting::set($key, $value);
        }

        SystemSetting::bustCache();

        $this->audit('system_setting.updated', null, $request->user(), $data);

        return back()->with('status', 'Settings updated successfully.');
    }
}