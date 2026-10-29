<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Concerns\FormatsByteSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\System\SystemSettingRequest;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\Timezone;
use App\Providers\AppServiceProvider;
use App\Services\Storage\StorageCapacityGuard;
use App\Support\FeatureCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * System settings controller for the web settings page.
 */
class SystemSettingController extends Controller
{
    use FormatsByteSize;

    /**
     * Settings stored as the strings 'true'/'false' but consumed as booleans.
     *
     * One list, shared with the request's `prepareForValidation()` cast. A key
     * that is cast on the way in and not on the way out renders as a ticked
     * switch that means OFF.
     *
     * @var list<string>
     */
    private const BOOLEAN_KEYS = [
        'password_require_upper',
        'password_require_lower',
        'password_require_digit',
        'password_require_symbol',
        'password_reject_username',
        'password_uncompromised',
        'password_history_enabled',
        'password_expiry_enabled',
        'inactivity_lock_enabled',
        'inactivity_lock_grace_enabled',
        'allow_username_change',
        'allow_email_change',
        'registration_enabled',
        'api_docs_public',
    ];

    /**
     * Show the settings page.
     *
     * Boolean keys are cast to real booleans here rather than in Blade. The view
     * reads `$settings['x'] ?? true` straight into `@checked()`, and a stored
     * `'false'` string is truthy in PHP — so an uncast value ticks every switch
     * ON. The read-only branch prints booleans as `true`/`false`, so it wants
     * them cast too.
     */
    public function index(StorageCapacityGuard $capacity): View
    {
        $settings = SystemSetting::getLive();

        foreach (self::BOOLEAN_KEYS as $key) {
            $settings[$key] = filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $timezones = Timezone::active()->orderBy('name')->get();
        $roles = RoleLookup::visibleTo(request()->user())->pluck('name', 'name');

        // The view renders labels, never arithmetic: measuring belongs to the
        // service that also refuses the write.
        $storage = $capacity->metrics();

        foreach (['used', 'available', 'total'] as $key) {
            $storage[$key.'_label'] = $this->bytesLabel($storage[$key]);
        }

        return view('pages.settings.index', [
            'settings' => $settings,
            'timezones' => $timezones,
            'roles' => $roles,
            'storage' => $storage,
            'storageEnabled' => FeatureCatalog::isActive('storage_management'),
            'hasStorageSecret' => AppServiceProvider::hasStorageSecret('storage_s3_secret'),
        ])->with('settingGroups', FeatureCatalog::settingGroups());
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
