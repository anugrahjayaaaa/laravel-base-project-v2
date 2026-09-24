<?php

namespace App\Observers;

use App\Models\SystemSetting;

/**
 * Busts the system settings cache on write operations.
 */
class SystemSettingObserver
{
    public function saved(SystemSetting $setting): void
    {
        $this->bust($setting);
    }

    public function deleted(SystemSetting $setting): void
    {
        $this->bust($setting);
    }

    public function restored(SystemSetting $setting): void
    {
        $this->bust($setting);
    }

    private function bust(SystemSetting $setting): void
    {
        SystemSetting::bustCache();
    }
}