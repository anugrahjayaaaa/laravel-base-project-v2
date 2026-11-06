<?php

use App\Enums\AuditExportStatus;
use App\Jobs\CleanTemporaryStorageJob;
use App\Jobs\InactivityLockSweep;
use App\Jobs\MemberPointsExpirySweepJob;
use App\Jobs\MemberSubscriptionExpirySweepJob;
use App\Jobs\PasswordExpirySweep;
use App\Jobs\QueueFailureProbe;
use App\Models\AuditExport;
use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use App\Services\Backup\SystemBackupSchedule;
use App\Services\Timezone\RebaseTimezones;
use App\Support\QueueName;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * One deliberately failing job, for exercising the queue monitoring screens
 * (Pulse's queues card, `/vantage`, and Vantage's retry button) by hand.
 *
 * A failure only becomes visible through a worker: `failed_jobs` is written by
 * `queue:work`, not by the dispatch, so this names the worker to have running.
 * `composer dev`'s listener takes no `--queue`, which means it answers `default`
 * only and would sit through this job forever — the one place the queue list
 * lives is `bin/run-workers.sh`, so that is what the output points at.
 */
Artisan::command('queue:probe-failure', function () {
    QueueFailureProbe::dispatch();

    $this->info('Dispatched '.QueueFailureProbe::class.' — it always fails.');
    $this->line('A worker must be listening to the `'.QueueName::SWEEPS.'` queue: bin/run-workers.sh local');
    $this->line('Then open /vantage (or /pulse) for the failed row and press Retry.');
})->purpose('Dispatch one deliberately failing job (queue monitoring + Vantage retry check)');

/**
 * Shift stored wall clocks when the system timezone changes.
 *
 * `app_timezone` changes how a stored value is INTERPRETED, not the value. This
 * is the one-time migration for rows written under the previous zone; run it
 * during maintenance, BEFORE saving the new timezone, or rows written after the
 * switch will be shifted a second time.
 *
 * `--from` must describe the wall clocks actually in the columns. On MySQL the
 * server session timezone is the reference: if it is not UTC, pass that zone.
 * Tables with no single-column primary key are reported, never guessed at.
 */
Artisan::command('timezone:rebase
    {--from=UTC : Timezone the stored wall clocks are currently in}
    {--to= : Timezone to convert them to (defaults to app_timezone)}
    {--dry-run : Report what would change without writing}
    {--force : Skip the confirmation prompt}
    {--chunk=500 : Rows per chunk}', function (RebaseTimezones $rebase): int {
    $from = (string) $this->option('from');
    $to = (string) ($this->option('to') ?: SystemSetting::getString('app_timezone') ?: AppServiceProvider::baseTimezone());

    foreach (['--from' => $from, '--to' => $to] as $flag => $timezone) {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error($flag.' is not a valid IANA timezone: '.$timezone);

            return Command::FAILURE;
        }
    }

    if ($from === $to) {
        $this->info('Source and target timezone are the same; nothing to do.');

        return Command::SUCCESS;
    }

    $dryRun = (bool) $this->option('dry-run');

    // In production the flag replaces the prompt, not supplements it: a
    // maintenance window is usually unattended, and an interactive confirm()
    // there would hang or be piped an accidental newline. Anything that rewrites
    // every timestamp column should name its intent explicitly.
    if (! $dryRun && app()->isProduction() && ! $this->option('force')) {
        $this->error('Refusing to rewrite timestamps in production without --force.');

        return Command::FAILURE;
    }

    if (! $dryRun && ! $this->option('force') && ! $this->confirm(
        "Shift every timestamp column from {$from} to {$to}? This rewrites data and cannot be undone."
    )) {
        $this->warn('Aborted.');

        return Command::FAILURE;
    }

    $report = $rebase->rebase($from, $to, $dryRun, (int) $this->option('chunk'));

    if ($report['tables'] === []) {
        $this->info('No timestamp rows found to shift.');

        return Command::SUCCESS;
    }

    $this->table(
        ['Table', 'Columns', 'Rows'],
        array_map(
            static fn (array $row, string $table): array => [$table, implode(', ', $row['columns']), (string) $row['rows']],
            $report['tables'],
            array_keys($report['tables']),
        ),
    );

    foreach ($report['skipped'] as $table => $reason) {
        $this->warn("Skipped {$table}: {$reason}");
    }

    $this->info($dryRun ? 'Dry run — no rows were written.' : 'Rebase complete.');

    return Command::SUCCESS;
})->purpose('Convert stored timestamp columns from one timezone to another');

// Password/security lifecycle sweeps. The schedule runs each minute; the
// callback dispatches both jobs only at the configured local time.
//
// `now()` is already in the operator's zone: `AppServiceProvider::bindTimezone()`
// applies the global `app_timezone` at boot, so there is no second timezone to
// read here — one setting governs this and the backup schedule alike.
Schedule::call(function (): void {
    $scheduledTime = SystemSetting::getString('password_security_sweep_time', '00:00');

    if (now()->format('H:i') !== $scheduledTime) {
        return;
    }

    PasswordExpirySweep::dispatch();
    InactivityLockSweep::dispatch();
})->everyMinute()->name('password-security-sweeps')->withoutOverlapping();

/**
 * Daily system backup.
 *
 * The callback ticks every minute and asks `SystemBackupSchedule`, rather than
 * registering `backup:run` from a config value: the package reads its config
 * when its commands are built, so a stored schedule would need a scheduler
 * restart to take effect. `backup_enabled` is the switch; the manual trigger
 * has its own route and permission.
 */
Schedule::call(function (SystemBackupSchedule $schedule): void {
    $schedule->dispatchIfDue();
})->everyMinute()->name('system-backup')->onOneServer()->withoutOverlapping();

/**
 * Daily temporary-file cleanup.
 *
 * Uploads land on `tmp` and are promoted on save, so a file still there past
 * `temp_file_retention_hours` is orphaned. The capacity guard dispatches the
 * same job when usage crosses the warning threshold; this is the daily floor
 * that runs even on an install that never gets close to full.
 */
Schedule::job(new CleanTemporaryStorageJob())
    ->daily()
    ->name('temp-storage-cleanup')
    ->withoutOverlapping();

/**
 * Daily points-expiry check (Phase 18, O-3).
 *
 * `member_points_expiry_date` is the next annual reset date or empty. The job
 * costs one settings read when the date has not arrived, which is every day
 * except the one the operator chose.
 */
Schedule::job(new MemberPointsExpirySweepJob())
    ->daily()
    ->name('member-points-expiry')
    ->withoutOverlapping();

/**
 * Daily subscription-expiry sweep (Phase 18, P18-C3) — the eighth entry.
 *
 * `withoutOverlapping()` stops one sweep running twice; it does not stop two
 * DIFFERENT sweeps starting in the same minute, and midnight already belongs to
 * `member-points-expiry`. Half past zero answers the P17-C3 collision question
 * for this entry: the two sweeps share the `sweeps` queue, so a worker is busy
 * with one when the other lands regardless — the stagger keeps their scan
 * bursts (and their DB load) off the same tick. The flag kill switch lives in
 * the job, not here, like every other sweep in this file.
 */
Schedule::job(new MemberSubscriptionExpirySweepJob())
    ->dailyAt('00:30')
    ->name('member-subscription-expiry')
    ->onOneServer()
    ->withoutOverlapping();

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

/**
 * Prune audit exports past their download window, files included.
 *
 * `docs/base/operations/retention.md:12` — "Audit export files | 7 days after
 * generation | Temporary download window". The number is
 * `AuditExport::RETENTION_DAYS`, which is also what the row's `expires_at` is
 * written from, so the sweep and the download route cannot answer differently
 * about the same row.
 *
 * Both halves, one pass, and the order matters:
 *
 * - the FILE first. An `audit_exports` row whose file is still on disk is a
 *   download link that 410s — a URL in somebody's mail that resolves to nothing,
 *   with a row still claiming it exists;
 * - the ROW second. Deleting the row without unlinking the file leaks disk
 *   forever, because nothing else in the application knows the path existed.
 *
 * A row whose job is still `pending` is NOT swept by age: `expires_at` is set at
 * request time, so a request that has been queued for eight days is swept here
 * while its job is still legitimately working. Those are rare and they are not
 * this sweep's business — the job's own timeout is.
 */
Schedule::call(function (): void {
    AuditExport::query()
        ->where('expires_at', '<', now())
        ->where('status', '!=', AuditExportStatus::PENDING)
        ->each(function (AuditExport $export): void {
            if ($export->path !== null) {
                Storage::disk('local')->delete($export->path);
            }

            $export->delete();
        });
})->daily()->name('audit-export-retention')->withoutOverlapping();

/**
 * Prune Vantage job history past its retention window.
 *
 * `vantage_jobs` is the one table Vantage owns that nothing else in the app
 * sweeps, and it grows one row per job run (plus one per tagged job in
 * `vantage_job_tags`). Without this the table outlives the retention the config
 * promises (`vantage.retention_days`, 14 days) — the same failure the
 * notification sweep above exists to prevent.
 *
 * `--force` skips the confirmation prompt an unattended run cannot answer;
 * `--keep-processing` spares in-flight jobs the command would otherwise treat
 * as history. `--days` is deliberately omitted so the command reads
 * `vantage.retention_days` — the number an operator overrides — so the schedule
 * and the config cannot disagree.
 *
 * Guarded on `vantage.enabled`: the package registers its commands only when
 * enabled, and the scheduler would otherwise log a "command not found" every
 * night on a deployment that switched Vantage off.
 */
if (config('vantage.enabled')) {
    Schedule::command('vantage:prune --force --keep-processing')
        ->daily()
        ->name('vantage-retention')
        ->withoutOverlapping();
}
