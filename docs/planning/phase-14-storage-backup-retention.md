# Phase 14 — Storage, Backup & Retention

> Date: 2026-10-28 | Status: **PLANNED** | Branch: `feature/phase-14-storage-backup-retention`
> Purpose: make the base project storage-aware and backup-capable, with a strict
> retention policy that protects audit trails and soft-deleted models while
> cleaning only temporary/unattached uploads.
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
| A | Storage disks architecture & S3 readiness | **Build** — config + secure access route |
| B | Backup strategy & async background engine | **Build** — Spatie backup + queued job |
| C | Temporary data retention & cleanup | **Build** — TTL job + scheduler |
| D | Security enforcement, RBAC, feature flags, audit trail | **Build** — gates + audit rows |
| E | Automated tests, verification suite & documentation | **Verify + docs** |

Not in scope: cloud-native snapshot tooling beyond S3-ready drivers, multi-region
replication, encryption-at-rest beyond disk-level defaults, and UI file browser
beyond the storage management page.

---

## 2. Adjusted specifications (owner decisions)

1. **Group A — Storage Architecture & S3 Readiness**
   - Default to local instance storage, but configure via `config/filesystems.php`
     to be S3-ready (dynamic `.env` drivers for seamless cloud migration).
   - Private/secure file access handler via signed URLs or authorization gates.
   - Disks: `local`, `public`, `tmp`, `secure_documents`.

2. **Group B — Backup Strategy & Async Background Engine**
   - Backup MySQL DB and `storage/app`.
   - Execute asynchronously via Queued Background Job `ExecuteSystemBackupJob`.
   - Job execution and triggers controlled dynamically via `SystemSetting` keys.

3. **Group C — Data Retention & Cleanup (strict invariants)**
   - Audit Trail / Activity Logs **MUST NOT** be deleted (retained permanently).
   - Soft-deleted models **MUST NOT** be force-deleted (no automatic pruning).
   - **ONLY** temporary files / unattached uploads are cleaned up automatically
     based on `SystemSetting` TTL (e.g., 24 hours).

4. **Group D & E** — Standard RBAC (`storage.view`, `storage.manage`,
   `backup.execute`), Feature Flag (`feature:storage_management`), Action-First
   Audit Trail logging (`backup.triggered`, `temp_storage.cleaned`), and 100%
   test suite verification.

---

## 3. Group A — Storage Disks Architecture & S3 Readiness

### P14-A1 — Storage Disk Configuration

- **Task**: Add/adjust `config/filesystems.php` with S3-ready drivers for
  `local`, `public`, `tmp`, and `secure_documents` disks, all driven by `.env`
  keys (`FILESYSTEM_DISK`, `AWS_*`, bucket, region, endpoint, path-style).
- **Acceptance**:
  - Default disk resolves to `local` when no env override is set.
  - Switching `FILESYSTEM_DISK=s3` (with valid AWS env) routes all disks to S3
    without code changes.
  - `tmp` and `secure_documents` are declared and reachable via `Storage::disk()`.
  - No hard-coded bucket/region in `app/` or `config/`.
- **Tests**: `StorageDiskTest` (disk resolution, env switch).
- **Docs**: `docs/base/infrastructure/storage.md` (if exists) or ADR note.

### P14-A2 — Secure Private Media Route & Middleware

- **Task**: Private/secure file access handler via signed URLs or authorization
  gates (e.g., `StorageController` + `can:storage.view` / signed route).
- **Acceptance**:
  - Files on `secure_documents` are never publicly reachable via direct URL.
  - Access requires `storage.view` (or a valid signed URL) and the
    `feature:storage_management` flag.
  - 403 on missing permission / inactive flag; 404 on missing file.
- **Tests**: `StorageDiskTest` (access gate, signed URL expiry).
- **Docs**: `docs/base/features/storage.md`.

### P14-A3 — Automated Storage Disk Access Test

- **Task**: `tests/Feature/Storage/StorageDiskTest.php` covering disk resolution,
  env-driven S3 switch, and secure access gating.
- **Acceptance**: All assertions green; no inline FQCN; no queries in views.

---

## 4. Group B — Backup Strategy & Async Background Engine

### P14-B1 — Spatie Backup Integration & Dynamic SystemSetting Control

- **Task**: Integrate `spatie/laravel-backup` (or equivalent) for MySQL DB +
  `storage/app`, with execution controlled by `SystemSetting` keys
  (`backup.enabled`, `backup.schedule`, `backup.retention_daily`, etc.).
- **Acceptance**:
  - Backup config reads schedule/retention from `SystemSetting`, not hardcoded.
  - Disabled via setting → no scheduled run.
  - ADR recorded if a new Composer package is added.
- **Tests**: `BackupStrategyTest`.
- **Docs**: `docs/infrastructure/backup-disaster-recovery.md`.

### P14-B2 — Asynchronous Backup Job Execution & Notification Alerts

- **Task**: `ExecuteSystemBackupJob` queued job (follows
  `docs/base/infrastructure/queue.md` standard: `QueueName` + `onQueue()`,
  explicit `$tries`/`$timeout`/`$maxExceptions`/`backoff()`, `failed()` logging
  event name + exception + `request_id` via `ReadsCorrelationId`). Triggered via
  `backup.execute` permission; notifies on success/failure.
- **Acceptance**:
  - Job runs on `exports`/`sweeps` (or a dedicated queue) named via
    `App\Support\QueueName`.
  - `request_id` read in `failed()` from the payload, never from the container.
  - Audit row `backup.triggered` written inside the transaction by the Action
    layer.
  - Notification alert on completion/failure.
- **Tests**: `BackupStrategyTest` (dispatch, job convention, audit row).
- **Docs**: `docs/base/infrastructure/queue.md` (convention compliance).

### P14-B3 — Automated Backup Strategy Test

- **Task**: `tests/Feature/Storage/BackupStrategyTest.php`.
- **Acceptance**: Dispatch + job convention + audit + notification assertions
  green.

---

## 5. Group C — Temporary Data Retention & Storage Cleanup

### P14-C1 — Retention Policy Configuration

- **Task**: `SystemSetting` key for temporary upload TTL (e.g.,
  `storage.tmp_ttl_hours`, default 24). Audit logs and soft-deleted models are
  explicitly **excluded** from any cleanup.
- **Acceptance**:
  - Setting is readable via `SystemSetting::get()` and validated (1..8760).
  - No setting exists that prunes audit logs or soft-deleted models.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/operations/retention.md`.

### P14-C2 — Scheduled Temporary Cleanup Job

- **Task**: `CleanTemporaryStorageJob` scheduled daily, cleaning only `tmp` /
  unattached uploads older than the TTL. Never touches audit logs or
  soft-deleted models.
- **Acceptance**:
  - Job deletes only files under `tmp`/unattached older than TTL.
  - Audit log rows and soft-deleted model rows remain untouched (asserted).
  - Job follows queue convention (P14-B2).
  - Audit row `temp_storage.cleaned` written inside the transaction.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/operations/retention.md`.

### P14-C3 — Automated Retention Test

- **Task**: `tests/Feature/Storage/DataRetentionTest.php` asserting temp files
  cleaned while Audit Logs and Soft Deleted models remain intact.
- **Acceptance**: Green; sabotage check (removing the guard deletes audit rows)
  turns red.

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
- **Acceptance**: Flag off → 403 for every role (incl. superadmin) and menu item
  drops.

### P14-D3 — Action-First Audit Trail Logging

- **Task**: `backup.triggered` and `temp_storage.cleaned` audit events written
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
6. All jobs follow the queue convention; `JobConventionTest` must pass.
7. Audit rows written inside the transaction by the Action layer only.

---

## 10. Verification gate (before declaring done)

- `vendor/bin/pint --test` on touched files.
- `php artisan test` (full suite) green.
- `php artisan test --filter=Storage` green.
- Docs updated and consistent with code.
- No regression in shared code paths.