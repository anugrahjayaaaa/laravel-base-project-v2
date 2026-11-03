<?php

namespace App\Services\Backup;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Backup\Config\Config;
use Throwable;

/**
 * The only place that invokes `spatie/laravel-backup`.
 *
 * ## Retention is rewritten at call time
 *
 * The package reads its `Config` object when a command is constructed, and the
 * provider registers that object `scoped` from `config('backup')`. A job that
 * only called `config([...])` would be right on a cold worker and wrong on the
 * second run in the same process, because the scoped instance is already
 * built. Forgetting the instance makes the next command rebuild it from the
 * edited config; the package's own `Config::rebind()` does the same thing but
 * is marked `@internal used for testing`.
 *
 * ## The package's own notifications stay off
 *
 * `SystemBackupRunAction` owns the outcome notification, resolved from
 * `backup.execute` holders. The package's notifier would mail a hardcoded
 * address instead. `--disable-notifications` is passed on both commands.
 *
 * ## Production refuses an unencrypted archive
 *
 * The archive holds the whole database plus `storage/app` — including
 * `secure_documents` — and `BACKUP_ARCHIVE_PASSWORD` is the only encryption the
 * package applies. `Zip` only calls `setPassword()` when the password is not
 * null, so an install that never set it writes plaintext and says nothing.
 * Refusing is louder than warning in a settings card nobody reopens.
 */
class SystemBackupRunner
{
    public const DEFAULT_DAILY = 7;

    public const DEFAULT_WEEKLY = 4;

    public const DEFAULT_MONTHLY = 12;

    /**
     * Run the backup, then apply retention to what is on the disk.
     *
     * A cleanup failure is logged rather than thrown: the backup itself
     * succeeded, and a failed rotation must not report the backup as failed.
     *
     * @throws RuntimeException when production has no archive password, or
     *                          when the backup command exits non-zero.
     */
    public function run(): void
    {
        $this->assertEncryptedInProduction();

        try {
            $this->applyRetention();

            $exit = Artisan::call('backup:run', ['--disable-notifications' => true]);

            if ($exit !== Command::SUCCESS) {
                throw new RuntimeException('Backup command failed with exit code '.$exit.'.');
            }

            $cleanup = Artisan::call('backup:clean', ['--disable-notifications' => true]);

            if ($cleanup !== Command::SUCCESS) {
                Log::warning('Backup cleanup failed', ['exit_code' => $cleanup]);
            }
        } catch (Throwable $e) {
            $this->cleanTemporaryDirectory();

            throw $e;
        }
    }

    /**
     * Refuse a plaintext artifact in production.
     *
     * `Zip` reads the password once and only encrypts when it is not null, so
     * an empty env value produces a readable archive with no other signal.
     */
    private function assertEncryptedInProduction(): void
    {
        if (! app()->isProduction() || filled(config('backup.backup.password'))) {
            return;
        }

        throw new RuntimeException(
            'Refusing to create an unencrypted backup in production: set BACKUP_ARCHIVE_PASSWORD and restart the queue worker.'
        );
    }

    /**
     * Remove the package's scratch directory after a failed run.
     *
     * The database dump is written there in plaintext. The package deletes it
     * after its own exceptions, not after a `Throwable` it never catches, and
     * a killed process runs nothing at all — the next run empties the
     * directory before writing.
     *
     * The path comes from config and this deletes recursively, so it is
     * confined to `storage/`: a misconfigured directory must not turn this
     * into a delete anywhere on the host.
     */
    private function cleanTemporaryDirectory(): void
    {
        $temp = rtrim((string) config('backup.backup.temporary_directory'), '/');

        if ($temp === '' || ! str_starts_with($temp.'/', rtrim(storage_path(), '/').'/') || ! is_dir($temp)) {
            return;
        }

        try {
            File::deleteDirectory($temp);
        } catch (Throwable $e) {
            Log::warning('Backup temporary directory could not be removed', [
                'path' => $temp,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rewrite the package's cleanup windows from the stored settings.
     *
     * The documented policy is daily / weekly / monthly counts. Spatie's
     * strategy describes day windows, so `keep_all` and `yearly` are zero and
     * the three operator values become the daily, weekly and monthly windows.
     */
    public function applyRetention(): void
    {
        config([
            'backup.cleanup.default_strategy.keep_all_backups_for_days' => 0,
            'backup.cleanup.default_strategy.keep_daily_backups_for_days' => $this->retention('backup_retention_daily', self::DEFAULT_DAILY),
            'backup.cleanup.default_strategy.keep_weekly_backups_for_weeks' => $this->retention('backup_retention_weekly', self::DEFAULT_WEEKLY),
            'backup.cleanup.default_strategy.keep_monthly_backups_for_months' => $this->retention('backup_retention_monthly', self::DEFAULT_MONTHLY),
            'backup.cleanup.default_strategy.keep_yearly_backups_for_years' => 0,
        ]);

        app()->forgetInstance(Config::class);
    }

    private function retention(string $key, int $default): int
    {
        return max(1, SystemSetting::getInt($key, $default));
    }
}
