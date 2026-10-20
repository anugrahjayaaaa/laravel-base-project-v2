<?php

namespace App\Support;

/**
 * The queue names the application's own jobs run on (Phase 11, P11-E1).
 *
 * Without this every job silently lands on `default`, and the per-queue view
 * every dashboard offers — Pulse included — is one bucket named after the
 * fallback. Naming them here means the label on the dashboard, the `--queue`
 * flag on the worker and the `$queue` property on the job are three readings
 * of one list instead of three strings that drift.
 *
 * `default` is deliberately NOT a constant here: it is the connection's
 * fallback, not an application queue name, and a job with no `$queue` is the
 * thing this class exists to prevent. Vendor dispatches (queued notifications
 * and mails with no explicit queue) still ride `default`, so the worker in
 * `bin/run-workers.sh` listens to it alongside these.
 *
 * ponytail: a constant list, not a queue-management UI. A new queue is one
 * line here, one `--queue` addition in the worker script, one `$queue` on
 * the job. Revisit only if queue names ever need to be data.
 */
class QueueName
{
    /**
     * Audit exports — heavy, IO-bound, user-initiated (GenerateAuditExport).
     */
    public const EXPORTS = 'exports';

    /**
     * Scheduled security sweeps — idempotent, dispatched by the scheduler,
     * never urgent (PasswordExpirySweep, InactivityLockSweep).
     */
    public const SWEEPS = 'sweeps';
}
