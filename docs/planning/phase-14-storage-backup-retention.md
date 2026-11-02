# Phase 14 — Storage, Backup & Retention

> Date: 2026-10-29 (breakdown revised; Group F added 2026-10-31) | Status: **IN_PROGRESS** (Groups A, B & F **REVIEW**) | Branch: `feature/phase-14-storage-backup-retention`
> Purpose: make the base project storage-aware and backup-capable, with strict
> retention that protects audit trails and soft-deleted models, enforces
> capacity limits via `SystemSetting`, and cleans only temporary/unattached
> uploads.
> Dependency chain: **A → B → C → D → E**. Inside A: `A1 → A2 → A3 → A4 → A5`.
> **F** is a follow-up with no dependency on the others.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/infrastructure/storage.md`,
> `docs/base/operations/retention.md`, and
> `docs/base/infrastructure/backup-disaster-recovery.md` — a task that wants to
> contradict them needs an ADR in `docs/planning/decisions.md`, not a code
> change.

---

## 1. Scope

Seventeen tasks in six groups:

| Group | Subject | Nature |
|---|---|---|
| A | Storage architecture, SystemSetting UI & capacity limits | **Build** |
| B | Backup strategy & async background engine (with storage safety) | **Build** |
| C | Temporary data retention & triggered cleanup | **Build** |
| D | Security enforcement, RBAC, feature flags, audit trail | **Build** |
| E | Automated tests, verification suite & documentation | **Verify + docs** |
| F | Global timezone (follow-up) — `app_timezone`, rebase command | **Build** |

Not in scope: multi-region replication, backup encryption beyond the disk's own
defaults, a general media library or file browser, and per-tenant storage
quotas.

---

## 2. Specifications (owner decisions)

1. **Group A — Storage architecture, SystemSetting UI & capacity**
   - Default disk is `local`; `config/filesystems.php` stays S3-ready through
     `.env` keys (`AWS_*`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`,
     `AWS_USE_PATH_STYLE_ENDPOINT`). No bucket or region is hard-coded.
   - Runtime override: `AppServiceProvider::bindStorageConfig()` applies
     `storage_default_disk` and the `storage_s3_*` settings to
     `filesystems.default` / `filesystems.disks.s3`, falling back to config.
     The read is wrapped in `try/catch (QueryException)` — `SystemSetting`
     touches the database/cache, and boot runs before `migrate` on a fresh
     install. A failed read must mean "use config", never "app cannot boot".
   - Storage Management card in the `col-lg-8` column of
     `resources/views/pages/settings/index.blade.php`, below the existing
     cards: driver select plus a reactive local/S3 fieldset and the capacity
     gauge.
   - S3 access key and secret are encrypted at rest (`encrypt()` on write,
     `decrypt()` on read) and listed in `SystemSetting::SECRET_KEYS`, so
     `getAll()` / `getLive()` (the read-only table and
     `GET /api/v1/settings`) never return them.
   - Capacity: local `metrics()` `used` counts the bytes stored under the
     disk root via the storage listing; the physical ceiling still comes from
     `disk_total_space`, or the configured quota when set. S3 has **no
     total-capacity API**, so the gauge uses the configured quota
     (`storage_max_capacity_mb`) as its denominator and reports Available as
     "n/a" when no quota is set.
   - Hard guard: refuse an upload when used ≥ 95% of the effective quota, or
     when the incoming file would cross it. Refusal is
     `StorageCapacityExceededException` → HTTP **507** with a new
     `STORAGE_FULL` code in `docs/base/api/error-contract.md`.
   - Warning threshold (`storage_warning_threshold_percent`, default 80)
     triggers cleanup and `storage.capacity_warning`; it is below the 95% hard
     guard so cleanup has somewhere to work before uploads start failing.

2. **Group B — Backup strategy & async engine**
   - `spatie/laravel-backup` (ADR-026, installed with `^10.3`) backs up MySQL
     and `storage/app`.
     Destinations use a dedicated `backups` disk: backing up onto the disk
     being backed up is not a backup. ADR + `changelog.md` entry precede the
     install.
   - `backup_enabled` / `backup_schedule` are read from `SystemSetting` by the
     scheduler at run time, not by mutating a package config at boot.
   - Execution is queued: `ExecuteSystemBackupJob` on `QueueName::BACKUPS`,
     following the queue standard in `docs/base/infrastructure/queue.md`
     (`onQueue()` in the constructor, explicit `$tries` / `$timeout` /
     `$maxExceptions` / `backoff()`, `failed()` logging via
     `ReadsCorrelationId`).
   - Pre-backup guard estimates the backup as the raw `storage/app` size plus
     MySQL `data_length + index_length`. Compression only makes the real
     artifact smaller, so the raw figure is an upper bound: it can abort a
     backup that would have fit, never start one that does not.

3. **Group C — Retention & cleanup**
   - Audit Trail / activity log rows are **never** deleted.
   - Soft-deleted models are **never** force-deleted automatically. No pruning
     setting exists for either.
   - Only the `tmp` disk is cleaned, by age (`temp_file_retention_hours`,
     default 24). Uploads are written to `tmp` first and promoted on save, so
     "still in `tmp` past the TTL" *is* "unattached" — no media registry is
     needed to know.
   - Cleanup runs daily and is dispatched on a threshold crossing. The
     threshold-triggered dispatch is throttled with `Cache::add()` (one
     dispatch per hour) so an upload storm does not enqueue one job per upload.

4. **Group D & E — Security, audit, verification**
   - Permissions: `storage.view`, `storage.manage`, `backup.execute` in
     `PermissionCatalog` + seeder; routes gated with `can:`.
   - Feature flag `feature:storage_management` gates the management surface
     (routes + settings card). It does **not** gate runtime disk binding or the
     upload guard: turning the dashboard off must not remove the disk
     protection.
   - Audit events (Action layer, inside the transaction):
     `storage_setting.updated`, `backup.triggered`, `backup.aborted_storage_full`,
     `temp_storage.cleaned`, `storage.capacity_warning`.
   - 100% suite verification via `php artisan test --filter=Storage` plus the
     full suite.

---

## 3. Group A — Storage Architecture, SystemSetting UI & Capacity Limits

### P14-A1 — Storage Management Card UI

- **Task**: add the Storage Management card to the `col-lg-8` main column of
  `resources/views/pages/settings/index.blade.php`:
  - driver `<select name="storage_default_disk">` with `local` | `s3`;
  - reactive fieldsets: `local` shows root path and directory/file permission
    mode; `s3` shows key, secret, region, bucket, endpoint, and URL;
  - capacity gauge (Bootstrap progress bar with `role="progressbar"`,
    `aria-valuenow/min/max`) showing Used, Available, and Total;
  - secret inputs render blank with a "leave blank to keep the stored value"
    hint; they are never populated from `SystemSetting::getAll()`.
- **Behaviour**: fieldset toggling is a plain `change` listener on the select —
  no new dependency, no build step, no inline FQCN, no queries in the view.
- **Acceptance**:
  - selecting `s3` reveals every credential field; `local` hides them;
  - a missing/blank secret does not overwrite the stored ciphertext;
  - gauge values come from the capacity service, not from Blade arithmetic;
  - card is absent when `feature:storage_management` is off.
- **Tests**: `StorageDiskTest` (render + toggle state + secret never echoed).

### P14-A2 — Dynamic Driver Binding & Conditional Validation

- **Task**:
  - `config/filesystems.php`: `local`, `public`, `tmp`, `secure_documents`,
    `backups`, and an S3 disk driven only by env/config.
  - `AppServiceProvider::bindStorageConfig()`: read `storage_default_disk` and
    `storage_s3_*`, write `config('filesystems.default')` and
    `config('filesystems.disks.s3')`, fall back on any read failure.
  - `SystemSettingRequest`: `storage_default_disk` in `local,s3`;
    `required_if:storage_default_disk,s3` on key, secret, region, bucket;
    `endpoint`/`url` optional URLs; root path must resolve inside
    `storage_path()`; permission mode a validated octal string.
  - `SystemSettingsUpdateAction`: add the keys to the `$updates` whitelist
    (a validated key missing from that list is dropped silently), encrypt
    secrets, write in `DB::transaction`, bust the cache after commit.
  - `SystemSetting::SECRET_KEYS`: add the S3 key and secret.
- **Keys**: `storage_default_disk`, `storage_local_root`,
  `storage_local_permission`, `storage_s3_key`, `storage_s3_secret`,
  `storage_s3_region`, `storage_s3_bucket`, `storage_s3_endpoint`,
  `storage_s3_url`, `storage_max_capacity_mb` (`0` = unlimited),
  `storage_warning_threshold_percent`.
- **Acceptance**:
  - no setting → `local` from config; `s3` + valid fields resolves to S3;
  - `s3` without key/secret/bucket → 422 with per-field errors;
  - secrets stored as ciphertext and absent from every read path;
  - `config('filesystems.default')` reflects the setting after a save;
  - boot still works with no database (migration / first install).
- **Tests**: `StorageDiskTest` (binding, fallback, validation, encryption,
  secret redaction).
- **Docs**: `docs/base/infrastructure/storage.md`.

### P14-A3 — Capacity Guard & Exception Handler

- **Task**: `StorageCapacityGuard` service used at every upload boundary:
  - `metrics()` → used / available / total / quota bytes for the active disk
    (local: bytes stored under the disk root, with `disk_total_space` as the
    physical ceiling; S3: cached ListObjectsV2
    size probe, 300 s TTL — the probe costs a request per page load
    otherwise);
  - `assertCanStore(int $bytes)` → throws
    `StorageCapacityExceededException` when used + incoming exceeds quota, or
    used ≥ 95% of quota; no partial write is left behind;
  - exception handler maps it to **507** `STORAGE_FULL` with the standard
    envelope (web: redirect back with an error; API: JSON);
  - on a warning-threshold crossing, write `storage.capacity_warning` and
    dispatch `CleanTemporaryStorageJob` through the hourly throttle.
- **UI**: the gauge from A1; a "Storage is almost full" alert above the card
  when the warning threshold is crossed.
- **Acceptance**:
  - over-quota upload refused with 507 / `STORAGE_FULL`, nothing written;
  - warning threshold fires once per crossing, not once per request;
  - `storage_max_capacity_mb = 0` means unlimited and not-divide-by-zero;
  - S3 without quota reports Used only, no fabricated total.
- **Tests**: `StorageDiskTest` (guard boundaries, warning throttle, envelope).
- **Docs**: `docs/base/api/error-contract.md` (`STORAGE_FULL`).

### P14-A4 — Secure Private Media Route & Signed URL Handler

- **Task**: `StorageController` + route for `secure_documents`, gated by
  `can:storage.view` and `feature:storage_management`; time-limited access for
  non-UI callers via `URL::temporarySignedRoute` (`signed` middleware,
  `INVALID_SIGNATURE` 403 on tamper/expiry).
- **Acceptance**:
  - no public URL reaches `secure_documents`; `storage:link` does not expose it;
  - 403 without permission / with the flag off; 404 for a missing file;
  - path traversal (`..`, absolute paths) rejected before disk access;
  - signed URL expires and cannot be replayed after expiry.
- **Tests**: `StorageDiskTest` (gate, traversal, signature, expiry).
- **Docs**: `docs/base/infrastructure/storage.md`.

### P14-A5 — Automated Storage Disk, UI & Capacity Test

- **Task**: `tests/Feature/Storage/StorageDiskTest.php` covering disk
  resolution, env/setting switch, boot fallback, conditional validation,
  encrypted/redacted secrets, the card render + toggle, guard boundaries, 507
  envelope, secure access, traversal, and signed-URL expiry.
- **Acceptance**: green; `test_` prefix; no inline FQCN; no queries in views;
  sabotage check — removing the guard turns the over-quota test red.

---

## 4. Group B — Backup Strategy & Async Background Engine

### P14-B1 — Spatie Backup Integration & Dynamic SystemSetting Control

- **Task**: ADR + `changelog.md` entry, then install
  `spatie/laravel-backup`; `config/backup.php` sources MySQL + `storage/app`
  and writes to the `backups` disk; schedule and retention read
  `backup_enabled`, `backup_schedule`, `backup_retention_daily`,
  `backup_retention_weekly`, `backup_retention_monthly`.
- **Constraint**: the package's config is read when commands/services are
  built, so schedule control lives in `routes/console.php`
  (`Schedule::job(...)` guarded by `SystemSetting::getBool('backup_enabled')`),
  not in a provider mutating `config('backup')` after boot.
- **Acceptance**:
  - `backup_enabled = false` → no dispatch, no artisan run;
  - changing `backup_schedule` changes the next run without a deploy;
  - backup artifacts land on `backups`, never on the source disk;
  - ADR records the package decision and its disk/retention model.
- **Tests**: `BackupStrategyTest` (schedule resolution, disabled path).
- **Docs**: `docs/base/infrastructure/backup-disaster-recovery.md`.

### P14-B2 — Pre-Backup Capacity Check & Async Execution

- **Task**: `ExecuteSystemBackupJob` on `QueueName::BACKUPS`
  (`App\Support\QueueName` gains `BACKUPS = 'backups'`; worker script and
  `docs/base/infrastructure/queue.md` table updated). The job:
  1. estimates size (raw `storage/app` + MySQL data/index length);
  2. compares it with available space on `backups` minus the warning margin;
  3. if it does not fit → audit `backup.aborted_storage_full`, notify admins,
     return without invoking the backup;
  4. otherwise runs the backup command, audits `backup.triggered`, and
     notifies success/failure.
- **Trigger**: a `POST` route gated by `can:backup.execute` +
  `feature:storage_management`, dispatching after commit.
- **Acceptance**:
  - queue conventions all hold (`JobConventionTest` covers the new job);
  - `request_id` read from the payload in `failed()`, never from the
    container;
  - abort path leaves no artifact and writes its audit row;
  - no synchronous backup in a request.
- **Tests**: `BackupStrategyTest` (dispatch, conventions, abort, audit,
  notification).
- **Docs**: `docs/base/infrastructure/queue.md`.

### P14-B3 — Automated Backup Strategy & Capacity Test

- **Task**: `tests/Feature/Storage/BackupStrategyTest.php` — disabled path,
  schedule resolution, dispatch, job convention, pre-check abort, success and
  failure notifications, audit rows present after commit and absent after
  rollback.
- **Acceptance**: green; queue faked where the backup command is not under
  test; one real command invocation at most.

---

## 5. Group C — Temporary Data Retention & Storage Cleanup

### P14-C1 — Retention & Threshold Policy Configuration

- **Task**: `SystemSetting` keys `temp_file_retention_hours` (default 24,
  bounds 1–8760) and `storage_warning_threshold_percent` (default 80, bounds
  1–99), plus the usual touchpoints: request rules, action whitelist, settings
  form, `FeatureCatalog::settingGroups()` mapping.
- **Acceptance**:
  - both settings validated and persisted like every other numeric setting;
  - no key exists that prunes audit logs or force-deletes models;
  - out-of-range values rejected by tests.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/base/operations/retention.md`.

### P14-C2 — Scheduled & Threshold-Triggered Cleanup Job

- **Task**: `CleanTemporaryStorageJob` on `QueueName::SWEEPS`:
  - deletes files under the `tmp` disk older than
    `temp_file_retention_hours` (uploads are promoted out of `tmp` on save, so
    leftovers are unattached by definition);
  - never enumerates `storage/app/private` or `public` — an unattached file
    there cannot be proven orphaned;
  - scheduled daily and dispatched by the capacity guard on a threshold
    crossing (hourly `Cache::add()` throttle);
  - audits `temp_storage.cleaned` with the deleted count and bytes freed;
  - idempotent: a second run finds nothing and writes no row.
- **Acceptance**:
  - only `tmp` files past the TTL are deleted;
  - activity-log rows and soft-deleted model rows are untouched (asserted);
  - a file exactly at the TTL boundary is kept (`older than`, not `at`);
  - job follows the queue convention.
- **Tests**: `DataRetentionTest`.
- **Docs**: `docs/base/operations/retention.md`.

### P14-C3 — Automated Retention Test

- **Task**: `tests/Feature/Storage/DataRetentionTest.php` — TTL purge,
  boundary, preserved audit rows, preserved soft-deleted rows, threshold
  dispatch throttle, audit row contents, second-run idempotence.
- **Acceptance**: green; sabotage check — pointing the job at the private disk
  deletes live files and turns the preservation test red.

---

## 6. Group D — Security Enforcement, RBAC, Feature Flags & Audit Trail

### P14-D1 — RBAC Authorization

- **Task**: seed `storage.view`, `storage.manage`, `backup.execute` in
  `PermissionCatalog` + seeder; gate the storage routes with `can:`.
- **Decision**: the settings page keeps its existing pipeline
  (`settings.view` / `settings.manage`) — the Storage Card is part of that
  form, so editing storage settings still requires `settings.manage`.
  `storage.*` gates the storage operations added by this phase (metrics,
  secure media, cleanup) and `backup.execute` gates the trigger. Recorded here
  because a reader will otherwise expect the card to be gated by
  `storage.manage`.
- **Acceptance**: a zero-permission user gets 403 on every new route;
  superadmin passes through `Gate::before`; the read-only settings viewer
  cannot write storage keys.

### P14-D2 — Feature Flag Middleware

- **Task**: declare `storage_management` in `config/pennant.php`, seed it in
  `FeatureFlagSeeder`, add `feature:storage_management` to the storage routes,
  and hide the settings card + sidebar entry when
  `FeatureCatalog::isActive()` is false.
- **Acceptance**:
  - flag off → 403 for every role including superadmin, card and menu gone;
  - flag off does **not** disable runtime disk binding or the upload guard;
  - flag on but permissions missing → still 403.

### P14-D3 — Action-First Audit Trail Logging

| Event | Written by | When |
|---|---|---|
| `storage_setting.updated` | `SystemSettingsUpdateAction` | a storage key changes; inside the transaction |
| `storage.capacity_warning` | capacity guard/action | warning threshold crossed, once per crossing |
| `backup.triggered` | `ExecuteSystemBackupJob`'s action | before the backup command runs |
| `backup.aborted_storage_full` | same | pre-check refuses the backup |
| `temp_storage.cleaned` | `CleanTemporaryStorageJob` | files were deleted (not on an empty run) |

- **Acceptance**: each row present after commit, absent after rollback; no
  observer writes any of them; storage secrets never appear in audit
  properties.

---

## 7. Group E — Automated Tests, Verification Suite & Documentation

### P14-E1 — Comprehensive Test Run

- **Task**: `php artisan test --filter=Storage` green, full suite green,
  `vendor/bin/pint --test` clean.
- **Acceptance**: no regressions; `JobConventionTest` and
  `SettingsPersistenceTest` pass with the new keys and job.

### P14-E2 — Update Documentation

- **Task**: update `docs/base/infrastructure/storage.md`,
  `docs/base/operations/retention.md`,
  `docs/base/infrastructure/backup-disaster-recovery.md`,
  `docs/base/infrastructure/queue.md`,
  `docs/base/api/error-contract.md`, `docs/planning/changelog.md`, and
  `docs/planning/progress.md`.
- **Acceptance**: docs match code; no doc references a setting, route, event,
  job, or disk that does not exist.

---

## 7b. Group F — Global timezone (follow-up)

> Added 2026-10-31 after Group B exposed the fragmentation: the backup schedule
> compared a wall clock against UTC, the password sweep honoured its own zone,
> and every other timestamp rendered in the process zone.

### P14-F1 — One global timezone, applied to the process

- **Task**: replace `password_security_sweep_timezone` with `app_timezone`;
  apply it to the process via `AppServiceProvider::bindTimezone()` at boot and
  after a save; expose it on a System Timezone card; add `timezone:rebase` for
  existing rows. Details and consequences: **ADR-027**.
- **Acceptance**:
  - saving `Asia/Jakarta` moves `now()`, `config('app.timezone')`, the API
    `meta.timestamp` offset, the audit trail and the settings clock to `+07:00`
    (`GlobalTimezoneTest`);
  - `backup_schedule` and `password_security_sweep_time` are due at that wall
    clock, not UTC;
  - an invalid or inactive zone is rejected by validation with the stored value
    untouched;
  - `''` falls back to the shipped base zone, and the fallback does not drift
    after the process zone has been mutated once;
  - `php artisan timezone:rebase --dry-run` reports tables/columns without
    writing, `--from` = `--to` is a no-op, and every written value is the
    expected shifted wall clock (`RebaseTimezonesCommandTest`);
  - a fresh `php artisan migrate` does not fail when `system_settings` does not
    exist yet.
- **Docs**: ADR-027, `changelog.md`, `docs/base/features/settings.md`,
  `docs/base/security/password-security.md`,
  `docs/base/infrastructure/queue.md`, `docs/base/api/response-contract.md`.

---

## 8. Task tracker registration

P14-A1..A5 registered as **REVIEW** (2026-10-29). P14-B1..B3
registered as **REVIEW** (2026-10-30). P14-F1 registered as **REVIEW**
(2026-10-31). P14-C*..E* remain `PLANNED`.
Phase 14 row in `docs/planning/progress.md` set to `IN_PROGRESS`.

---

## 9. Invariants (must hold, non-negotiable)

1. Audit Trail / activity log rows are **never** deleted by any cleanup job.
2. Soft-deleted models are **never** force-deleted automatically.
3. Only the `tmp` disk is cleaned, gated by `SystemSetting` TTL.
4. Backup runs asynchronously via queued job; never synchronously in a request.
5. Backup artifacts never land on the disk being backed up.
6. Storage access is gated by RBAC + feature flag; `secure_documents` has no
   public direct URL.
7. Capacity is enforced before uploads and backups; a breach triggers an alert
   and an audit row; no silent partial write.
8. Disk binding reads `SystemSetting` with a config fallback and must never
   prevent boot when the table is missing or unreachable.
9. The `storage_management` flag gates the management surface only — the
   upload guard stays active when it is off.
10. All jobs follow the queue convention; `JobConventionTest` must pass.
11. Audit rows are written inside the transaction by the Action layer only.
12. S3 credentials are encrypted at rest, listed in `SECRET_KEYS`, and never
    rendered back into a form or audit row.

---

## 10. Verification gate (before declaring done)

- `vendor/bin/pint --test` on touched files.
- `php artisan test` (full suite) green.
- `php artisan test --filter=Storage` green.
- Sabotage checks red for the guard and the retention test.
- Docs updated and consistent with code.
- No regression in shared code paths.
