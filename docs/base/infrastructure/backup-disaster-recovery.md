# Backup & Disaster Recovery

## Backup Types

| Type | Scope | Frequency |
|------|-------|-----------|
| Database backup | All database tables | Daily at `backup_schedule` (`HH:MM`, default `02:00`, in the global system timezone — `app_timezone`, ADR-027 — configurable, off by default) |
| File/storage backup | `storage/app` (excluding `backups/`, `backup-temp/`) | Same run as the database backup |

## Implementation

Backups run through `spatie/laravel-backup` (ADR-026), driven by application
code rather than the package's own scheduler wiring:

- **Queued, never in a request.** `ExecuteSystemBackupJob` on the `backups`
  queue and its own `backups` connection: a backup runs for minutes, so its
  `retry_after` (2000s) must exceed the job's `$timeout` (1800s) — on the shared
  connection a second worker would re-reserve a running backup and, because
  the job declares `$tries = 1`, fail it. `bin/run-workers.sh backups` is the
  worker that consumes it. The scheduler (`SystemBackupSchedule`, ticked from
  `routes/console.php` with `onOneServer()`) and the operator trigger
  (`POST /storage/backup`, `can:backup.execute` + `feature:storage_management`,
  throttled `system-backup`) both dispatch the same job. The job also carries a
  `WithoutOverlapping('system-backup')` middleware (expiry 3600s > `$timeout`),
  so the single-flight property holds by lock, not only by the accident of one
  worker process.
  `SystemBackupRunAction` owns the pre-check, the audit rows and the outcome
  notifications.
- **Dedicated destination.** Artifacts land on the `backups` disk only — never
  the disk being backed up. The destination is excluded from the source set.
- **Encryption is mandatory in production.** `SystemBackupRunner::run()` refuses
  to start when `backup.backup.password` is empty and the environment is
  `production`: the archive contains the whole database and `storage/app`,
  including `secure_documents`, and the package only encrypts when the password
  is set. `backup.verify_backup` is on, so a corrupt archive fails the run
  instead of being announced as completed. A failed run deletes
  `backup-temp`, where the dump is written in plaintext — a process killed by a
  timeout or `SIGKILL` cannot, and the next run empties the directory before
  writing.
- **Pre-flight capacity check.** `SystemBackupSizeEstimator` measures the raw
  `storage/app` size (minus the excluded directories) plus MySQL
  `data_length + index_length`. `SystemBackupRunAction` refuses when the
  estimate plus a 10% reserve does not fit in the destination's free space:
  the raw figure is larger than the compressed artifact, so the check may
  refuse a backup that would have fit, never start one that would not. The
  refusal is audited as `backup.aborted_storage_full` and announced to
  `backup.execute` holders. A destination that cannot be measured is trusted
  rather than refused.
- **Retention.** `backup_retention_daily|weekly|monthly` (defaults 7/4/12) are
  applied to the package's cleanup strategy immediately before the run
  (`SystemBackupRunner::applyRetention`), so one worker process never carries a
  previous run's windows. A second, hard ceiling also applies:
  `config/backup.php` sets
  `delete_oldest_backups_when_using_more_megabytes_than = 5000`, so after the
  three windows run the oldest artifacts are deleted until the `backups` disk
  holds at most 5 GB. An install whose dailies alone exceed 5 GB keeps fewer
  artifacts than the configured counts — the counts are a policy, the cap is a
  guard rail against an unbounded destination.
- **Notifications.** The package's own channels are disabled; the three
  outcomes — `backup.completed`, `backup.failed`, `backup.aborted` — reach
  holders of `backup.execute` through the standard administrative
  notification, so whoever is told can also act.

## Audit Events

| Event | Written by | When |
|-------|-----------|------|
| `backup.triggered` | `SystemBackupRunAction` | before the backup command runs; stays even if the command fails |
| `backup.aborted_storage_full` | `SystemBackupRunAction` | the pre-check refuses the backup |

Both rows carry `disk`, `estimated_bytes` and `available_bytes`.

## Backup Process

```
Backup Initiated (scheduler or POST /storage/backup)
    ↓
ExecuteSystemBackupJob queued (QueueName::BACKUPS)
    ↓
Pre-flight capacity check (estimate vs destination, 10% reserve)
    ↓
backup.triggered audited
    ↓
`backup:run` — MySQL dump + storage/app archive → backups disk
    ↓
`backup:clean` — retention applied
    ↓
backup.completed / backup.failed notified to backup.execute holders
```

## Verification

- Restore testing is manual: `backup:list` shows the artifacts on the
  `backups` disk; a restore procedure is an operator runbook concern, not a
  scheduled job.
- The pre-check itself is covered by `BackupStrategyTest`; the estimator's
  exclusions are asserted against the real filesystem.

## Restore Procedure

Documented restore procedure includes:
1. Identify backup to restore from.
2. Verify backup integrity.
3. Restore database.
4. Restore files.
5. Run migrations (if needed).
6. Clear caches.
7. Verify application health.
8. Notify stakeholders.

## Retention

| Data Type | Retention | Rationale |
|----------|-----------|-----------|
| Database backups | `backup_retention_daily` daily, `backup_retention_weekly` weekly, `backup_retention_monthly` monthly (7/4/12 default) | Standard backup rotation |
| File backups | same run, same retention | Sync with database |

`keep_all` and `yearly` windows are fixed at zero: the operator-facing policy
is the three counts, and a fourth window would be a second copy of them.

## Recovery Metrics

- RPO (Recovery Point Objective): configurable per deployment.
- RTO (Recovery Time Objective): configurable per deployment.
- RPO/RTO may be deployment-specific.

## Disaster Recovery

**The shipped `backups` disk is not disaster recovery.** It is rooted at
`storage/app/backups` (`config/filesystems.php`) — the same physical volume as
the application, and in the default single-host deployment the same host as the
database. A host or volume loss takes the database and every artifact with it.
That disk protects against a bad migration or a deleted row; it does not protect
against losing the server. Point the destination at off-host storage (S3 or a
mounted volume) before treating any of this as DR.

- Offsite backup storage (cloud provider + cross-region) — point the `backups`
  disk (or an S3 destination) there.
- Failover procedures documented.
- Runbook for complete environment restore.
- Regular disaster recovery exercises.

## Security

- Backup archives are encrypted with `BACKUP_ARCHIVE_PASSWORD`, and
  **production refuses to run without it** (`SystemBackupRunner`, ADR-026).
  The value lives in config, not in the settings table: set the variable, run
  `php artisan config:cache`, then `php artisan queue:restart` so the worker
  picks it up.
- Access control on backup files: read through the storage permissions, not a
  public URL.
- Backup/abort operations are audited (see Audit Events above).
- Never store secrets in version-controlled backup configuration.

## Tools

- Laravel `backup` package (`spatie/laravel-backup`) for database/file backups.
- Cloud-native snapshots (AWS RDS, S3 versioning) as alternative.
