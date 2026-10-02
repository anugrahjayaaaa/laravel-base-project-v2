<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\System\SystemSettingRequest;
use App\Models\RoleLookup;
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
        $roles = RoleLookup::visibleTo(request()->user())->pluck('name', 'name');

        return view('pages.settings.index', compact('settings', 'timezones', 'roles'));
    }

    /**
     * Update system settings.
     *
     * The audit record is written by the action, not here — see
     * `SystemSettingsUpdateAction`.
     */
    public function update(SystemSettingRequest $request, SystemSettingsUpdateAction $action): RedirectResponse
    {
        $action->run($request->validated(), causer: $request->user());

        return back()->with('status', 'Settings updated successfully.');
    }
}
