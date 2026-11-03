# Storage

## Storage Abstraction

Defined for:
- User-uploaded files
- Private files
- Temporary processing / unattached uploads
- Audit exports and other generated artefacts
- Future attachments

## Disks

| Disk | Visibility | Root | Use |
|------|-----------|------|-----|
| `local` | private | `storage/app/private` (overridable via `storage_local_root`) | Default application files; audit exports |
| `public` | public | `storage/app/public` | Explicitly public assets only (`storage:link`) |
| `tmp` | private | `storage/app/tmp` | Unattached uploads; TTL-cleaned by `CleanTemporaryStorageJob` (`temp_file_retention_hours`, default 24) |
| `secure_documents` | private | `storage/app/secure` | Private media; **never** linked into `public/` |
| `backups` | private | `storage/app/backups` | Backup destination (separate from sources) |
| `s3` | private (cloud) | bucket | Optional default disk when `storage_default_disk=s3` |

`storage:link` only exposes `public` → `storage/app/public`. It does not expose
`secure_documents`, `tmp`, `local`, or `backups`.

## Runtime binding (Phase 14 Group A)

`AppServiceProvider::bindStorageConfig()` reads SystemSetting keys at boot (and
again after a settings save) and writes:

- `config('filesystems.default')` from `storage_default_disk` (`local` \| `s3`)
- `config('filesystems.disks.s3')` from `storage_s3_*` (non-empty values only)
- `config('filesystems.disks.local')` root / permission mode when set

Any read failure (no DB yet, migrate, `config:cache`) falls back to
`config/filesystems.php` / `.env` — boot must never fail because settings are
missing.

S3 credentials (`storage_s3_key`, `storage_s3_secret`) live in
`SystemSetting::SECRET_KEYS`: encrypted at rest, absent from `getAll()` /
`getLive()`, blank form fields keep the stored ciphertext.

## Capacity guard

`App\Services\Storage\StorageCapacityGuard`:

- `metrics()` — used / available / total / quota for the active disk, measured
  now. Local `used` counts the bytes stored under the disk root
  (`storage/app/private`: uploads, audit exports) by walking the tree with a
  raw `RecursiveIteratorIterator`; backups live in `storage/app/backups` and
  logs in `storage/logs`, both outside that root, so neither is counted. The
  physical ceiling still comes from `disk_total_space`, or from the configured
  quota when set (smaller of the two). S3 probes `ListObjectsV2` (300 s cache)
  for used bytes; without a quota there is **no fabricated total**.
- `cachedMetrics()` — the same figures at most 60 s old, for display paths
  (`/settings`, `/health`, `HealthCheckService`). The walk is cached per disk
  root; the quota is still read on every call. **Never** use it for a write
  decision: a minute-old number can pass a disk that is already past the limit.
- `assertCanStore($bytes)` — throws `StorageCapacityExceededException` when the
  write would exceed quota or the disk is already ≥ 95% full. Call at every
  write boundary **before** bytes land (today: `GenerateAuditExport`). It
  always measures through `metrics()`, never the cached figure.
- Warning threshold (`storage_warning_threshold_percent`, default 80) writes
  `storage.capacity_warning` and dispatches `CleanTemporaryStorageJob` once per
  hour (`Cache::add` throttle).
- `storage_max_capacity_mb = 0` means unlimited (no divide-by-zero).

### Guard coverage

The guard is not global: each check names one disk, and a disk nobody checks is
unguarded. The quota governs the disk it is measured on, never `backups`.

| Write boundary | Disk | Check | Refuses when |
|---|---|---|---|
| `GenerateAuditExport` | `local` | `assertCanStore(0, 'local')` after the CSV is built, before `writeStream` | quota reached, or the disk is already ≥ 95% used (byte count unknown at that point, so the percentage decides) |
| `SystemBackupRunAction` | `backups` | `fits(estimate, free)` with a 10% reserve | the raw-source estimate plus the reserve does not fit the destination's free space (the quota does not apply here) |
| `HealthCheckService::checkStorage()` | default disk | **unguarded by design** | a 2-byte probe that fails closed: a full disk makes `put()` throw → the check reports `fail`, and the same method reports `fail` at ≥ 95% via `cachedMetrics()` without writing |

Nothing else in the application writes user-controlled bytes to a disk.

**Rule for new boundaries:** a feature that writes files to any disk calls
`assertCanStore($bytes, $disk)` first, for the disk it is about to write, and
grows a row in this table. Upload endpoints do not exist yet; when one lands it
opens with the guard — invariant 7 in the phase document means this, not a
global check.

Handler: HTTP **507** / code `STORAGE_FULL`, with `meta.storage` carrying the
measurement. See `docs/base/api/error-contract.md`.

The management feature flag (`storage_management`) gates the management surface
— both Settings cards (Storage and Backup), the secure route and the backup
trigger — not the storage itself: disk binding and the upload guard stay active
when the flag is off. Every key the two cards own is listed under the flag in
`config/pennant.php`, so a save while the flag is off skips them instead of
writing defaults over the stored configuration, and they are absent from
`getLive()` until the flag is back on.

## Secure private media

Route: `GET /storage/secure/{path}` (`storage.secure`).

Middleware: `feature:storage_management` + `can:storage.view` + `signed`.

- Path traversal (`..`, NUL) → 404 before disk access.
- Missing file → 404.
- Tampered / expired signature → 403 (`INVALID_SIGNATURE` on API JSON).
- Always a download (`Content-Disposition: attachment`). A stored `.html` or
  `.svg` must never render on the application origin; inline preview needs an
  explicit MIME allowlist (images/PDF) and that is not implemented.
- Generate with `URL::temporarySignedRoute('storage.secure', $expires, ['path' => …])`.

## Management UI

Settings → Storage Management card (`feature:storage_management`):

- Driver select `local` \| `s3` with reactive fieldsets (plain `change` listener)
- Capacity gauge from `StorageCapacityGuard::cachedMetrics()` (no Blade arithmetic)
- The same gauge on `/health`, on the storage card: used / total / available
  plus the progress bar, shown even while the check is failing. The check
  itself reads `fail` at `StorageCapacityGuard::HARD_LIMIT_PERCENT`. A null
  `percent` (unknown total) renders no bar rather than a fabricated 0%.
- Warning alert when percent ≥ threshold
- Secret inputs always blank; “leave blank to keep the stored value”

## Permissions

| Permission | Gates |
|---|---|
| `storage.view` | `storage.secure` (private media), plus the read-only settings table on `/settings` |
| `storage.manage` | Seeded for storage operations; the Storage card is edited under `settings.manage` (P14-D1), so no route checks it yet. The capacity gauge sits inside that editable card, so reading it needs `settings.manage`; a `settings.view`-only holder sees the read-only table, not the gauge |
| `backup.execute` | `POST /storage/backup`, the "Run Backup Now" button, and backup outcome notifications |

All three map to the `storage_management` flag in `PermissionCatalog`.

Both storage routes are web-only: `routes/api.php` has no storage or backup
endpoint in this phase, so an API-only client cannot request a backup or fetch
private media.

## Rules

- Audit exports must use private storage (`local`), never `public`.
- Do not expose sensitive generated files through public storage.
- User-uploaded files default to private; explicit public only when needed.
- Only the `tmp` disk is TTL-cleaned. Audit logs and soft-deleted models are
  never pruned by retention jobs.
- `storage_local_root` must **resolve** inside `storage_path()`. The check
  normalises `..` and resolves the deepest existing ancestor through the
  filesystem, so neither `/…/storage/../etc` nor a symlink pointing out of the
  tree is accepted.
- Prefer Laravel's `Storage` facade — local and S3 stay interchangeable.

## Cloud Storage

When using S3-compatible storage, either set the usual `AWS_*` env vars or
configure through System Settings (encrypted). Env wins when a setting row is
empty.

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
```

The driver needs `league/flysystem-aws-s3-v3` (ADR-028), which ships in
`composer.json` and pulls `aws/aws-sdk-php`. Laravel only *suggests* it, so
removing it makes every operation on the `s3` disk throw a class-not-found —
`StorageDiskTest::test_the_s3_driver_package_is_installed` fails instead.

## Backup Strategy

See [backup-disaster-recovery.md](./backup-disaster-recovery.md). Phase 14 Group B
runs `spatie/laravel-backup` through `ExecuteSystemBackupJob` on
`QueueName::BACKUPS`, writing to the `backups` disk asynchronously with a
pre-flight capacity check and retention from `backup_retention_daily|weekly|monthly`.

## ADR References

- ADR: Storage abstraction for secure file handling
