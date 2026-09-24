<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * API system settings controller — get and update settings.
 */
class SystemSettingController extends Controller
{
    /**
     * Get all system settings.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return $this->respond('', 200, SystemSetting::getAll());
    }

    /**
     * Update system settings.
     *
     * @param  SystemSettingRequest  $request
     * @return JsonResponse
     */
    public function update(SystemSettingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $updates = [
            'auth_login_max_attempts' => (string) ($data['auth_login_max_attempts'] ?? 5),
            'auth_lockout_base_minutes' => (string) ($data['auth_lockout_base_minutes'] ?? 5),
            'auth_lockout_increment_minutes' => (string) ($data['auth_lockout_increment_minutes'] ?? 10),
            'auth_login_rate_limit_per_minute' => (string) ($data['auth_login_rate_limit_per_minute'] ?? 5),
            'auth_password_forgot_rate_limit' => (string) ($data['auth_password_forgot_rate_limit'] ?? 3),
            'auth_password_reset_rate_limit' => (string) ($data['auth_password_reset_rate_limit'] ?? 3),
            'auth_password_reset_token_expire_minutes' => (string) ($data['auth_password_reset_token_expire_minutes'] ?? 15),
            'auth_email_verification_rate_limit' => (string) ($data['auth_email_verification_rate_limit'] ?? 5),
            'auth_email_verification_token_expire_minutes' => (string) ($data['auth_email_verification_token_expire_minutes'] ?? 60),
            'auth_password_min_length' => (string) ($data['auth_password_min_length'] ?? 8),
            'auth_password_mixed_case' => ($data['auth_password_mixed_case'] ?? false) ? 'true' : 'false',
            'auth_password_numbers' => ($data['auth_password_numbers'] ?? false) ? 'true' : 'false',
            'auth_password_symbols' => ($data['auth_password_symbols'] ?? false) ? 'true' : 'false',
            'auth_password_uncompromised' => ($data['auth_password_uncompromised'] ?? false) ? 'true' : 'false',
            'password_history_enabled' => ($data['password_history_enabled'] ?? false) ? 'true' : 'false',
            'password_history_count' => (string) ($data['password_history_count'] ?? 5),
            'auth_password_expiration_days' => (string) ($data['auth_password_expiration_days'] ?? 90),
            'auth_verification_expire_minutes' => (string) ($data['auth_verification_expire_minutes'] ?? 60),
            'auth_verification_mode' => $data['auth_verification_mode'] ?? 'public',
            'auth_password_reset_expire_minutes' => (string) ($data['auth_password_reset_expire_minutes'] ?? 15),
            'allow_username_change' => ($data['allow_username_change'] ?? false) ? 'true' : 'false',
            'username_change_cooldown_days' => (string) ($data['username_change_cooldown_days'] ?? 30),
            'allow_email_change' => ($data['allow_email_change'] ?? false) ? 'true' : 'false',
            'email_change_cooldown_days' => (string) ($data['email_change_cooldown_days'] ?? 30),
        ];

        foreach ($updates as $key => $value) {
            SystemSetting::set($key, $value);
        }

        $this->audit('system_setting.updated', null, $request->user(), $data);

        return $this->respond('Settings updated successfully.', 200, SystemSetting::getAll());
    }
}