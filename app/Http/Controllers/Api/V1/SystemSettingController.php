<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\System\SystemSettingRequest;
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
     *
     * Partial on purpose: an API client sends the keys it wants to change, and
     * a key it left out must survive the call. Only the web form — which posts
     * every field, and where an unchecked checkbox is simply absent — reads a
     * missing key as "reset to default".
     */
    public function update(SystemSettingRequest $request, SystemSettingsUpdateAction $action): JsonResponse
    {
        $action->run($request->validated(), partial: true, causer: $request->user());

        return $this->respond('Settings updated successfully.', 200, SystemSetting::getAll());
    }
}
