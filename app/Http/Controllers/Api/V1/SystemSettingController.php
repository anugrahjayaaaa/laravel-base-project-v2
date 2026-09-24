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
            'login_max_attempts' => (string) ($data['login_max_attempts'] ?? 5),
            'lockout_base_minutes' => (string) ($data['lockout_base_minutes'] ?? 5),
            'lockout_increment_minutes' => (string) ($data['lockout_increment_minutes'] ?? 10),
            'login_rate_limit_per_minute' => (string) ($data['login_rate_limit_per_minute'] ?? 5),
            'password_forgot_rate_limit' => (string) ($data['password_forgot_rate_limit'] ?? 3),
            'password_reset_rate_limit' => (string) ($data['password_reset_rate_limit'] ?? 3),
            'password_reset_token_expire_minutes' => (string) ($data['password_reset_token_expire_minutes'] ?? 15),
            'email_verification_rate_limit' => (string) ($data['email_verification_rate_limit'] ?? 5),
            'email_verification_token_expire_minutes' => (string) ($data['email_verification_token_expire_minutes'] ?? 60),
            'password_min_length' => (string) ($data['password_min_length'] ?? 8),
            'password_mixed_case' => ($data['password_mixed_case'] ?? false) ? 'true' : 'false',
            'password_numbers' => ($data['password_numbers'] ?? false) ? 'true' : 'false',
            'password_symbols' => ($data['password_symbols'] ?? false) ? 'true' : 'false',
            'password_uncompromised' => ($data['password_uncompromised'] ?? false) ? 'true' : 'false',
            'password_history_enabled' => ($data['password_history_enabled'] ?? false) ? 'true' : 'false',
            'password_history_count' => (string) ($data['password_history_count'] ?? 5),
            'password_expiration_days' => (string) ($data['password_expiration_days'] ?? 90),
            'email_verification_expire_minutes' => (string) ($data['email_verification_expire_minutes'] ?? 60),
            'email_verification_mode' => $data['email_verification_mode'] ?? 'public',
            'password_reset_expire_minutes' => (string) ($data['password_reset_expire_minutes'] ?? 15),
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