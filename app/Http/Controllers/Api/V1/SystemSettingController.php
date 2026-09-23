<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->respond('', 200, [
            'allow_username_change' => SystemSetting::getBool('allow_username_change', true),
            'allow_email_change' => SystemSetting::getBool('allow_email_change', true),
            'username_change_cooldown_days' => SystemSetting::getInt('username_change_cooldown_days', 30),
            'email_change_cooldown_days' => SystemSetting::getInt('email_change_cooldown_days', 30),
        ]);
    }

    public function update(SystemSettingRequest $request): JsonResponse
    {
        $data = $request->validated();

        SystemSetting::set('allow_username_change', $data['allow_username_change'] ? 'true' : 'false');
        SystemSetting::set('allow_email_change', $data['allow_email_change'] ? 'true' : 'false');
        SystemSetting::set('username_change_cooldown_days', (string) ($data['username_change_cooldown_days'] ?? 30));
        SystemSetting::set('email_change_cooldown_days', (string) ($data['email_change_cooldown_days'] ?? 30));

        $this->audit('system_setting.updated', null, $request->user(), $data);

        return $this->respond('Settings updated successfully.', 200, [
            'allow_username_change' => $data['allow_username_change'],
            'allow_email_change' => $data['allow_email_change'],
            'username_change_cooldown_days' => $data['username_change_cooldown_days'] ?? 30,
            'email_change_cooldown_days' => $data['email_change_cooldown_days'] ?? 30,
        ]);
    }
}