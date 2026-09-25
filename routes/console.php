<?php

use App\Jobs\InactivityLockSweep;
use App\Jobs\PasswordExpirySweep;
use App\Models\SystemSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Password/security lifecycle sweeps. The schedule runs each minute; the
// callback dispatches both jobs only at the configured local time.
Schedule::call(function (): void {
    $timezone = SystemSetting::getString('password_security_sweep_timezone') ?: config('app.timezone');
    $scheduledTime = SystemSetting::getString('password_security_sweep_time', '00:00');

    if (now($timezone)->format('H:i') !== $scheduledTime) {
        return;
    }

    PasswordExpirySweep::dispatch();
    InactivityLockSweep::dispatch();
})->everyMinute()->name('password-security-sweeps')->withoutOverlapping();
