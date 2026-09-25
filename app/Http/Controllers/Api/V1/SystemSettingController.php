<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\System\UpdateSystemSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * API system settings controller for get and update operations.
 */
class SystemSettingController extends Controller
{
    /**
     * Get all system settings.
     */
    public function index(): JsonResponse
    {
        return $this->respond('', 200, SystemSetting::getAll());
    }

    /**
     * Update system settings and record the acting user in the audit log.
     */
    public function update(SystemSettingRequest $request, UpdateSystemSettingsAction $action): JsonResponse
    {
        $data = $request->validated();

        $action->run($data);

        $this->audit('system_setting.updated', SystemSetting::query()->firstOrFail(), $request->user(), $data);

        return $this->respond('Settings updated successfully.', 200, SystemSetting::getAll());
    }
}
