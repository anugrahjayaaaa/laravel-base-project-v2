<?php

namespace App\Actions\V1\System;

use App\Actions\V1\Notification\NotificationAdminEventAction;
use App\Concerns\FormatsByteSize;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\SystemBackupRunner;
use App\Services\Backup\SystemBackupSizeEstimator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Execute one system backup, but only when the destination can hold it.
 *
 * The estimate is the raw source size, deliberately larger than the compressed
 * artifact: this check may refuse a backup that would have fit, never start one
 * that would not. The reserve keeps the destination from being filled to the
 * last byte by a backup that then leaves the server unable to write logs.
 *
 * Both outcomes are recorded by the Action layer, matching every other module:
 * `backup.aborted_storage_full` when the pre-check refuses, `backup.triggered`
 * before the command runs. The trigger row stays even if the command fails —
 * it records that an attempt began, which is what an incident review needs.
 */
class SystemBackupRunAction
{
    use FormatsByteSize;

    /** Free space kept back on the destination, as a percentage. */
    public const RESERVE_PERCENT = 10;

    public function __construct(
        private readonly SystemBackupSizeEstimator $estimator,
        private readonly SystemBackupRunner $runner,
        private readonly NotificationAdminEventAction $notifyAction,
    ) {
    }

    /**
     * @throws Throwable when the backup command fails.
     */
    public function run(?User $causer = null): void
    {
        $estimate = $this->estimator->estimate();
        $available = $this->estimator->availableBytes('backups');

        if (! $this->fits($estimate['total'], $available)) {
            $this->audit('backup.aborted_storage_full', $causer, [
                'disk' => 'backups',
                'estimated_bytes' => $estimate['total'],
                'available_bytes' => $available,
            ]);

            $this->notifyAction->configurationChanged(
                'backup.aborted',
                sprintf(
                    'estimated %s, %s available',
                    $this->bytesLabel($estimate['total']),
                    $this->bytesLabel($available)
                ),
                $causer
            );

            return;
        }

        $this->audit('backup.triggered', $causer, [
            'disk' => 'backups',
            'estimated_bytes' => $estimate['total'],
            'available_bytes' => $available,
        ]);

        try {
            $this->runner->run();
        } catch (Throwable $e) {
            $this->notifyAction->configurationChanged(
                'backup.failed',
                mb_substr($e->getMessage(), 0, 200),
                $causer
            );

            throw $e;
        }

        $this->notifyAction->configurationChanged(
            'backup.completed',
            'estimated '.$this->bytesLabel($estimate['total']),
            $causer
        );
    }

    /**
     * Does the estimate plus the safety reserve fit in the free space?
     *
     * Unknown free space always passes: refusing every backup because the
     * destination cannot be measured would turn a monitoring gap into a
     * permanently disabled feature.
     */
    private function fits(int $required, ?int $available): bool
    {
        if ($available === null) {
            return true;
        }

        $reserve = (int) floor($available * self::RESERVE_PERCENT / 100);

        return $required + $reserve <= $available;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, ?User $causer, array $properties): void
    {
        // The scheduled run has no operator; the manual one does. Only the
        // former is the system acting, so only it carries the SYSTEM marker —
        // a null causer alone would render as "Anonymous".
        $marker = $causer === null ? ['causer' => 'SYSTEM'] : [];

        DB::transaction(function () use ($event, $causer, $properties, $marker): void {
            SystemSetting::query()->firstOrFail()->audit($event, $causer, $properties + $marker + [
                'source' => 'system',
            ]);
        });
    }
}
