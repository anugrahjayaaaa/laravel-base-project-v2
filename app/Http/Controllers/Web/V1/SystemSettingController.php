<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\System\UpdateSystemSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\SystemSetting;
use App\Models\Timezone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * System settings controller for the web settings page.
 */
class SystemSettingController extends Controller
{
    /**
     * Show the settings page.
     */
    public function index(): View
    {
        $settings = SystemSetting::getAll();
        $timezones = Timezone::active()->orderBy('name')->get();

        return view('pages.settings.index', compact('settings', 'timezones'));
    }

    /**
     * Update system settings and record the acting user in the audit log.
     */
    public function update(SystemSettingRequest $request, UpdateSystemSettingsAction $action): RedirectResponse
    {
        $data = $request->validated();

        $action->run($data);

        $this->audit('system_setting.updated', SystemSetting::query()->firstOrFail(), $request->user(), $data);

        return back()->with('status', 'Settings updated successfully.');
    }
}
