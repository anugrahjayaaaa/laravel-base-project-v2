<?php

use App\Enums\AuditExportStatus;
use App\Jobs\InactivityLockSweep;
use App\Jobs\PasswordExpirySweep;
use App\Jobs\QueueFailureProbe;
use App\Models\AuditExport;
use App\Models\SystemSetting;
use App\Support\QueueName;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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
