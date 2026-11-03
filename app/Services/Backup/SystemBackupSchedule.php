<?php

namespace App\Services\Backup;

use App\Jobs\ExecuteSystemBackupJob;
use App\Models\SystemSetting;
use Carbon\CarbonInterface;

/**
 * Decides whether the daily backup is due, at run time.
 *
 * The package reads `config('backup')` when its commands are built, so the
 * schedule cannot be a `Schedule::command('backup:run')` registered from a config
 * value: changing the setting would need a scheduler restart or a deploy. The
 * scheduler therefore ticks every minute and asks this class, the same shape the
 * password-security sweeps use.
 *
 * `backup_enabled` gates the automatic run only. The operator-triggered job is
 * a separate path and stays reachable while this is off.
 */
class SystemBackupSchedule
{
    public const DEFAULT_TIME = '02:00';

    /**
     * The configured HH:MM, or the documented default when the row is missing
     * or malformed. A bad value must not silently disable backups.
     */
    public function time(): string
    {
        $value = SystemSetting::getString('backup_schedule', self::DEFAULT_TIME);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : self::DEFAULT_TIME;
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        if (! SystemSetting::getBool('backup_enabled', false)) {
            return false;
        }

        return ($now ?? now())->format('H:i') === $this->time();
    }

    /**
     * Dispatch the queued backup when due, and answer whether it was queued.
     *
     * After commit because the job reads committed settings; outside a
     * transaction this dispatches immediately.
     */
    public function dispatchIfDue(?CarbonInterface $now = null): bool
    {
        if (! $this->isDue($now)) {
            return false;
        }

        ExecuteSystemBackupJob::dispatch()->afterCommit();

        return true;
    }
}
