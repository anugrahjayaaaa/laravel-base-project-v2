# Storage

## Storage Abstraction

Defined for:
- User-uploaded files
- Private files
- Temporary exports
- Future attachments

## Disks

| Disk | Visibility | Use |
|------|-----------|-----|
| `local` | private | Private application files |
| `public` | public | Publicly accessible files (user uploads with public access) |
| `tmp` | private | Temporary exports, processing |

## Configuration

```php
// config/filesystems.php
'disks' => [
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
        'visibility' => 'private',
    ],
    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'visibility' => 'public',
    ],
    'tmp' => [
        'driver' => 'local',
        'root' => storage_path('app/tmp'),
        'visibility' => 'private',
        'expire' => 60 * 24, // minutes (1 day)
    ],
],
```

## Rules

- Audit exports must use private storage.
- Do not expose sensitive generated files through public storage.
- User-uploaded files default to private; explicit public only when needed.
- Temporary exports stored in `tmp` disk with expiration/lifecycle policy.

## File Lifecycle

```
Upload → Store (private) → Access via controlled route → Expire/Delete
```

## Cloud Storage

When using S3-compatible storage:
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
```

Use Laravel's `Storage` facade for disk abstraction — works with local and cloud transparently.

## Security

- Never store secrets in uploaded files.
- Validate file types and size on upload.
- Scan uploads for malware where applicable.
- Set appropriate permissions (private by default).
- Use content-disposition headers for controlled downloads.

## Backup Strategy

- Database: `mysqldump` or Laravel `db:dump` package
- Files: cloud backup (S3 lifecycle rules, Glacier for archives)
- Backup verification: checksum validation
- Retention: configurable (see [backup-disaster-recovery.md](./backup-disaster-recovery.md))

## ADR References

- ADR: Storage abstraction for secure file handling