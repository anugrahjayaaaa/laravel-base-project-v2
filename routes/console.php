<?php

use App\Jobs\InactivityLockSweep;
use App\Jobs\PasswordExpirySweep;
use App\Models\SystemSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

/**
 * Prune read notifications past the retention window.
 *
 * The `notifications` table grows one row per delivery, forever. Nothing in the
 * app deletes from it, so an install that receives administrative events accrues
 * rows with no ceiling — the inbox itself paginates, which hides the problem from
 * every page that renders it while the table keeps filling.
 *
 * READ rows only, for the same reason Laravel's own `DatabaseNotification`
 * prunes on `read_at`: an unread notification is state the user has not acted on
 * yet, and deleting it removes a badge nobody was shown. Anything unread stays
 * until it is read, which is the point of reading it.
 *
 * A direct DELETE rather than `model:prune`, which needs a `Prunable` model and
 * a `notifications()` relation override to make Laravel's command reach this
 * table at all. `Notifiable` hardcodes `DatabaseNotification` in that relation,
 * so the framework path costs a model swap on the one relation every delivery,
 * read and count goes through — in exchange for a scheduled query this module
 * can already write.
 */
Schedule::call(function (): void {
    // 90 days, the same order of magnitude as the 5 risky-test benchmark
    // assertions this module's own suite allows itself: long enough that a
    // notification someone is still reasoning about is still in their inbox,
    // short enough that the table does not outlive the feature it records.
    // A constant rather than a `SystemSetting` — a setting here means a seeder
    // row, a form field, a validation rule and a UI control for a number no
    // operator has asked to move.
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(90))
        ->delete();
})->daily()->name('notification-retention')->withoutOverlapping();
