# Retention

## Data Retention Policy

Define retention separately for each data type. Do NOT apply one global retention period to everything.

## Retention Schedule

| Data Type | Default Retention | Rationale |
|----------|------------------|-----------|
| Audit logs | Indefinite (until manual review) | Legal/security compliance |
| Audit export files | 7 days after generation (`App\Models\AuditExport::RETENTION_DAYS`) | Temporary download window, private disk `audit-exports/` |
| Application logs | 30 days | Debug/operational troubleshooting |
| Security logs | 90 days | Incident response, security investigation |
| Server logs | Configured by infra team | Infrastructure monitoring |
| Pulse data | Pulse's own retention config | Runtime metrics, ingested on a schedule |
| Vantage job history | `vantage.retention_days` (default 14), pruned daily by `vantage:prune` behind `config('vantage.enabled')` | Queue monitor rows, same failure mode the notification sweep exists to prevent |
| Sessions | Until expiration (`web_session_expire_*` setting) | Automatic cleanup |
| Revoked tokens | Deleted on revocation | Sanctum rows are removed when the token is revoked; there is no archive |
| Password history | Configurable (`security.password_history.count`) | Prevent reuse (IM8 policy) |
| Password history count | Configurable count (default: 5) | Balance security vs usability |
| Notifications | 90 days after read | Inbox cleanup — the daily sweep prunes **read** rows only; unread rows stay until read |
| Temporary uploads (`tmp` disk) | `temp_file_retention_hours` (default 24, validated 1–8760) | Uploads are promoted out of `tmp` on save, so anything left past the window is unattached; cleaned daily by `CleanTemporaryStorageJob` |
| Database backups | `backup_retention_daily` daily, `backup_retention_weekly` weekly, `backup_retention_monthly` monthly (7/4/12 default) | Standard backup rotation; applied by `SystemBackupRunner` per run |
| File backups | same run, same retention | Sync with database |
| Failed jobs | 7 days | Investigation window — **operator policy**: no scheduled `queue:prune-failed` ships in this app; prune by hand or add the schedule |
| Password reset tokens | 15 minutes (`PASSWORD_RESET_EXPIRE_MINUTES`, `config/auth.php`) | Security — short window |

**Which rows the application actually enforces.** The scheduled sweeps are:
read notifications (90 d), audit exports (7 d), `tmp` files (TTL), backup
retention, and Vantage history (14 d). The other rows are targets, not
mechanisms: the default log channel is `stack → single` (one growing file —
`daily` with `LOG_DAILY_DAYS` is opt-in), application/security/server logs and
failed jobs are an ops/runbook concern, and audit logs are indefinite by
invariant. When a row has no sweep behind it, the retention is whatever the
operator's infrastructure does with it.

### Audit exports and `tmp` are two different things

- **Audit export files** are written by `GenerateAuditExport` to the private
  disk under `audit-exports/`, keyed by `audit_exports.expires_at`, and swept
  nightly by `routes/console.php`. `AuditExport::RETENTION_DAYS` is the single
  source of that number.
- **Temporary uploads** are written to the `tmp` disk first and promoted on
  save. `temp_file_retention_hours` is the only TTL for them, enforced by
  `CleanTemporaryStorageJob` — daily, plus a threshold-triggered dispatch from
  `StorageCapacityGuard` when usage crosses
  `storage_warning_threshold_percent` (throttled to one dispatch per hour, so
  an upload storm cannot enqueue one job per upload). A file still there past
  the window is unattached by definition; no media registry is needed to know
  that. The job never enumerates `local`, `public` or `secure_documents` — an
  unattached file there cannot be proven orphaned.

Neither number may be changed to match the other: they answer different
questions about different artifacts.

## Configuration

Keys that exist in code today (all `SystemSetting`, validated in
`SystemSettingRequest`):
```
temp_file_retention_hours         (default: 24, validated 1..8760)

backup_retention_daily            (default: 7,  validated 1..90)
backup_retention_weekly           (default: 4,  validated 1..52)
backup_retention_monthly          (default: 12, validated 1..60)
```

`retention.audit_logs.days`, `retention.application_logs.days`,
`retention.sessions.minutes`, `retention.tokens.revoked.days`,
`retention.notifications.days`, `retention.failed_jobs.days`,
`retention.password_reset.minutes` are **design targets, not config keys** —
no code reads them. The enforced values are hardcoded where they exist:
notification sweep 90 d (`routes/console.php`), audit export
`AuditExport::RETENTION_DAYS`, password reset token expiry in
`config/auth.php`, and audit logs indefinite by
`activitylog.delete_records_older_than_days => null` (a deliberate null — set
a number there only alongside a change to this document).

**Backups have a second, hard ceiling.** `config/backup.php` sets
`delete_oldest_backups_when_using_more_megabytes_than = 5000`, so after the three
windows are applied the oldest artifacts are deleted until the `backups` disk
holds at most 5 GB. It is not configurable from the UI and `SystemBackupRunner`
does not rewrite it: an install whose dailies alone exceed 5 GB keeps fewer
artifacts than `backup_retention_*` asks for. The counts are the policy, the cap
is the guard rail that keeps the destination from filling the volume the
application also writes logs to.

## Enforcement

- Application-level garbage collection jobs (scheduled daily).
- `CleanTemporaryStorageJob` on the `sweeps` queue: daily, plus a
  threshold-triggered run from the capacity guard (one dispatch per hour).
- Database-level TTL where supported (MySQL event scheduler, PostgreSQL TTL).
- File-level cleanup jobs for storage exports.
- Session cleanup handled by Laravel's session driver.

## Invariants

- Audit Trail / activity log rows are **never** deleted by any retention job.
- Soft-deleted models are **never** force-deleted automatically. No setting
  exists for either policy, so nothing can be switched on to do it.
- Only the `tmp` disk is TTL-cleaned.

## Audit

- Retention configuration changes are audited.
- `temp_storage.cleaned` is written only when files were deleted, carrying
  `deleted_count`, `bytes_freed` and `retention_hours`; an empty run writes no
  row, which is what makes a second run idempotent rather than a duplicate log.
- Retention job execution logged (job `failed()` hook).
- Alert on retention job failure.
