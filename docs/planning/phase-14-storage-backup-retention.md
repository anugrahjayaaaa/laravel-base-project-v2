# Phase 14 — Storage, Backup & Retention

> Date: 2026-10-28 | Status: **PLANNED** | Branch: `feature/phase-14-storage-backup-retention`
> Purpose: make the base project storage-aware and backup-capable, with strict
> retention that protects audit trails and soft-deleted models, enforces
> capacity limits via `SystemSetting`, and cleans only temporary/unattached
> uploads.
> Dependency chain: **A → B → C → D → E**.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/operations/retention.md` and
> `docs/base/infrastructure/backup-disaster-recovery.md` — a task that wants to
> contradict them needs an ADR in `docs/planning/decisions.md`, not a code change.

---

## 1. Scope

Fifteen tasks in five groups:

| Group | Subject | Nature |
|---|---|---|
| A | Storage architecture, SystemSetting control & capacity limits | **Build** |
| B | Backup strategy & async background engine (with storage safety) | **Build** |
| C | Temporary data retention & triggered cleanup | **Build** |
| D | Security enforcement, RBAC, feature flags, audit trail | **Build** |
| E | Automated tests, verification suite & documentation | **Verify + docs** |

Not in scope: cloud-native snapshot tooling beyond S3-ready drivers,
multi-region replication, encryption-at-rest beyond disk-level defaults, and a
UI file browser beyond the storage management page.

---

## 2. Updated specifications (owner decisions)

1. **Group A — Storage Architecture, SystemSetting Control & Capacity Limits**
   - Dynamic storage driver: read primary storage disk from `SystemSetting`
     (`storage_default_disk` = `local` | `s3`), falling back to
     `config/filesystems.php`.
   - System Setting UI: a Storage Management card in the main content area
     (`col-lg-8`) of `resources/views/pages/settings/index.blade.php` with a
     reactive driver dropdown (`local` | `s3`); selecting `local` expands
     local root/permission fields, selecting `s3` expands AWS credentials &
     bucket configuration fields (Key, Secret, Region, Bucket, Endpoint, URL).
   - Runtime driver binding: `AppServiceProvider::bindStorageConfig()` overrides
     `config('filesystems.default')` and `config('filesystems.disks.s3')` from
     `SystemSetting` rows at boot, falling back to `.env` / `config/filesystems.php`.
   - Sensitive S3 keys/secrets stored in `SystemSetting` are encrypted via
     `encrypt()`/`decrypt()`; `SystemSetting::bustCache()` invalidated on update.
   - Conditional validation: `UpdateSystemSettingsRequest` validates local/s3
     fields with `required_if:storage_default_disk,s3`.
   - Capacity limit enforcement (`storage_max_capacity_mb` &
     `db_max_capacity_mb` in `SystemSetting`): check available disk/S3 space
     before processing new uploads; when the limit is reached (threshold met),
     return a standard error response / prevent the upload and trigger an
     administrative alert.
   - Storage capacity & health metrics: a visual progress bar in the Storage
     Management card showing Used Bytes, Free Bytes, and Total Bytes
     (`disk_free_space()` / `disk_total_space()` for local, or S3 probe).
   - Capacity guard: enforce `storage_max_capacity_mb` before file uploads,
     throwing `StorageCapacityExceededException` when usage exceeds the
     threshold (default 95%).
   - Private/secure file access handler via signed URLs or authorization gates.

2. **Group B — Backup Strategy, Background Engine & Storage Safety**
   - Backup MySQL DB and `storage/app` via `spatie/laravel-backup`.
   - Execute asynchronously via Queued Background Job `ExecuteSystemBackupJob`.
   - Check remaining storage before starting backup; abort and notify admin if
     the backup size exceeds the available storage threshold.
   - Controlled dynamically via `SystemSetting` keys (`backup_enabled`,
     `backup_schedule`).

3. **Group C — Temporary Data Retention & Emergency Purge**
   - Audit Trail / Activity Logs **MUST NOT** be deleted (retained
     permanently).
   - Soft-deleted models **MUST NOT** be force-deleted (no automatic pruning).
   - Temporary files / unattached uploads cleaned up automatically based on
     `SystemSetting` TTL (`temp_file_retention_hours`).
   - Capacity-triggered cleanup: auto-trigger `CleanTemporaryStorageJob` when
     disk usage crosses the warning threshold.

4. **Group D & E** — Standard RBAC (`storage.view`, `storage.manage`,
   `backup.execute`), Feature Flag (`feature:storage_management`), Action-First
   Audit Trail logging (`storage.capacity_warning`,
   `backup.aborted_storage_full`, `backup.triggered`, `temp_storage.cleaned`),
   and 100% test suite verification.

---

## 3. Group A — Storage Architecture, SystemSetting Control & Capacity Limits

### P14-A1 — Storage Disk Configuration & Runtime Binding

- **Task**: Add/adjust `config/filesystems.php` with S3-ready drivers for
  `local`, `public`, `tmp`, and `secure_documents` disks, all driven by `.env`
  keys. Bind the primary disk at runtime from `SystemSetting`
  (`storage_default_disk` = `local` | `s3`) via
  `AppServiceProvider::bindStorageConfig()`, overriding
  `config('filesystems.default')` and `config('filesystems.disks.s3')`,
  falling back to the config default when unset.
- **UI**: Storage Management card in `col-lg-8` main content area of
  `resources/views/pages/settings/index.blade.php` with driver select dropdown
  and reactive local/S3 field toggling.
- **Acceptance**:
  - Default disk resolves to `local` when no setting/env override is set.
  - Switching `storage_default_disk=s3` (with valid AWS env) routes all disks
    to S3 without code changes.
  - `tmp` and `secure_documents` are declared and reachable via
    `Storage::disk()`.
  - No hard-coded bucket/region in `app/` or `config/`.
  - S3 credentials encrypted at rest in `SystemSetting`.
- **Tests**: `StorageDiskTest` (disk resolution, env/setting switch, binding).
- **Docs**: `docs/base/infrastructure/storage.md` (if exists) or ADR note.

### P14-A2 — Storage Capacity Limit Shield & Middleware

- **Task**: Validate disk usage against `storage_max_capacity_mb` (and
  `db_max_capacity_mb` where applicable) before processing new uploads; when
  the limit is reached, throw `StorageCapacityExceededException`, return a
  standard error response, prevent the upload, and trigger an administrative
  alert (audit row `storage.capacity_warning`).
- **UI**: Storage capacity health gauge in the Storage Management card —
  progress bar showing Used Bytes, Free Bytes, and Total Bytes.
- **Acceptance**:
  - Upload is refused with a standard error when capacity threshold is met.
  - Alert/notification fired on threshold breach.
  - No silent partial writes.
  - Threshold defaults to 95% (`storage_warning_threshold_percent`).
- **Tests**: `StorageDiskTest` (capacity shield).
- **Docs**: `docs/base/features/storage.md`.

### P14-A3 — Secure Private Media Route & Signed URL Handler

- **Task**: Private/secure file access handler via signed URLs or
  authorization gates (`StorageController` + `can:storage.view` / signed route).
- **Acceptance**:
  - Files on `secure_documents` are never publicly reachable via direct URL.
  - Access requires `storage.view` (or a valid signed URL) and the
    `feature:storage_management` flag.
  - 403 on missing permission / inactive flag; 404 on missing file.
- **Tests**: `StorageDiskTest` (access gate, signed URL expiry).
- **Docs**: `docs/base/features/storage.md`.

### P14-A4 — Automated Storage Disk & Capacity Limit Test

- **Task**: `tests/Feature/Storage/StorageDiskTest.php` covering disk
  resolution, env/setting-driven switch, runtime binding, capacity shield, and
  secure access gating.
- **Acceptance**: All assertions green; no inline FQCN; no queries in views.

---

## 4. Group B — Backup Strategy & Async Background Engine

### P14-B1 — Spatie Backup Integration & Dynamic SystemSetting Control

- **Task**: Integrate `spatie/laravel-backup` (or equivalent) for MySQL DB +
  `storage/app`, with execution controlled by `SystemSetting` keys
  (`backup_enabled`, `backup_schedule`, `backup_retention_daily`, etc.).
- **Acceptance**:
  - Backup config reads schedule/retention from `SystemSetting`, not
    hardcoded.
  - Disabled via setting → no scheduled run.
  - ADR recorded if a new Composer package is added.
- **Tests**: `BackupStrategyTest`.
- **Docs**: `docs/infrastructure/backup-disaster-recovery.md`.

### P14-B2 — Pre-Backup Storage Check & Asynchronous Backup Execution

- **Task**: `ExecuteSystemBackupJob` queued job (follows
  `docs/base/infrastructure/queue.md` standard: `QueueName` + `onQueue()`,
  explicit `$tries`/`$timeout`/`$maxExceptions`/`backoff()`, `failed()`
  logging event name + exception + `request_id` via `ReadsCorrelationId`).
  Check remaining storage before starting; abort gracefully (audit row
  `backup.aborted_storage_full`) and notify admin if the backup size exceeds
  the available storage threshold. Triggered via `backup.execute` permission;
  notifies on success/failure.
- **Acceptance**:
  - Job runs on a named queue via `App\Support\QueueName`.
  - `request_id` read in `failed()` from the payload, never from the container.
  - Audit row `backup.triggered` (or `backup.aborted_storage_full`) written
    inside the transaction by the Action layer.
  - Notification alert on completion/failure/abort.
- **Tests**: `BackupStrategyTest` (dispatch, job convention, audit row, abort).
- **Docs**: `docs/base/infrastructure/queue.md`.

### P14-B3 — Automated Backup Strategy & Full Storage Test

- **Task**: `tests/Feature/Storage/BackupStrategyTest.php`.
- **Acceptance**: Dispatch + job convention + audit + notification + abort
  assertions green.

---

## 5. Group C — Temporary Data Retention & Storage Cleanup

### P14-C1 — Retention & Threshold Policy Configuration

- **Task**: `SystemSetting` keys for `temp_file_retention_hours` (default 24)
  and `storage_warning_threshold_percent` (e.g., 95). Audit logs and
  soft-deleted models are explicitly **excluded** from any cleanup.
- **Acceptance**:
  - Settings readable via `SystemSetting` getters and validated.
  - No setting exists that prunes audit logs or soft-deleted models.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/operations/retention.md`.

### P14-C2 — Scheduled & Triggered Temporary Cleanup Job

- **Task**: `CleanTemporaryStorageJob` scheduled daily and auto-triggered when
  disk usage crosses the warning threshold, cleaning only `tmp` / unattached
  uploads older than the TTL. Never touches audit logs or soft-deleted models.
- **Acceptance**:
  - Job deletes only files under `tmp`/unattached older than TTL.
  - Audit log rows and soft-deleted model rows remain untouched (asserted).
  - Job follows queue convention (P14-B2).
  - Audit row `temp_storage.cleaned` written inside the transaction.
  - Triggered automatically on threshold breach.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/operations/retention.md`.

### P14-C3 — Automated Retention Test

- **Task**: `tests/Feature/Storage/DataRetentionTest.php` asserting temp files
  purged, Audit Logs & Soft Deleted models preserved, and threshold-triggered
  cleanup.
- **Acceptance**: Green; sabotage check (removing the guard deletes audit
  rows) turns red.

---

## 6. Group D — Security Enforcement, RBAC, Feature Flags & Audit Trail

### P14-D1 — RBAC Authorization

- **Task**: Seed `storage.view`, `storage.manage`, `backup.execute` in
  `PermissionCatalog` + seeder; gate routes with `can:`.
- **Acceptance**: Zero-permission user gets 403 on all storage/backup routes;
  superadmin bypasses via `Gate::before`.

### P14-D2 — Feature Flag Middleware

- **Task**: `feature:storage_management` flag declared in `config/pennant.php`,
  seeded by `FeatureFlagSeeder`, gated on routes + sidebar via
  `FeatureCatalog::isActive()`.
- **Acceptance**: Flag off → 403 for every role (incl. superadmin) and menu
  item drops.

### P14-D3 — Action-First Audit Trail Logging

- **Task**: `backup.triggered`, `backup.aborted_storage_full`,
  `temp_storage.cleaned`, and `storage.capacity_warning` audit events written
  inside the Action's transaction via `Auditable::audit()`.
- **Acceptance**: Audit row present after commit; absent after rollback.

---

## 7. Group E — Automated Tests, Verification Suite & Documentation

### P14-E1 — Comprehensive Test Run

- **Task**: `php artisan test --filter=Storage` green; full suite green.
- **Acceptance**: No regressions; pint clean on touched files.

### P14-E2 — Update Documentation

- **Task**: Update `docs/operations/retention.md`,
  `docs/infrastructure/backup-disaster-recovery.md`, and
  `docs/planning/progress.md`.
- **Acceptance**: Docs match code; tracker reflects Phase 14 status.

---

## 8. Task tracker registration

All P14-* tasks registered in `docs/planning/task-tracker.md` as `PLANNED`.
Phase 14 row in `docs/planning/progress.md` set to `PLANNED`.

---

## 9. Invariants (must hold, non-negotiable)

1. Audit Trail / Activity Logs are **never** deleted by any cleanup job.
2. Soft-deleted models are **never** force-deleted automatically.
3. Only `tmp` / unattached uploads are cleaned, gated by `SystemSetting` TTL.
4. Backup runs asynchronously via queued job; never synchronously in a request.
5. Storage access is gated by RBAC + feature flag; no public direct access to
   `secure_documents`.
6. Capacity limits enforced before uploads/backups; breach triggers alert +
   audit row.
7. Dynamic disk driver read from `SystemSetting` with config fallback; binding
   via `AppServiceProvider::bindStorageConfig()`.
8. All jobs follow the queue convention; `JobConventionTest` must pass.
9. Audit rows written inside the transaction by the Action layer only.
10. S3 credentials encrypted at rest; cache busted on setting update.

---

## 10. Verification gate (before declaring done)

- `vendor/bin/pint --test` on touched files.
- `php artisan test` (full suite) green.
- `php artisan test --filter=Storage` green.
- Docs updated and consistent with code.
- No regression in shared code paths.