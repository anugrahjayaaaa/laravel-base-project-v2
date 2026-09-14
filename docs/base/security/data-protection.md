# Data Protection

## Encryption

- Database-level encryption for sensitive fields (encrypted columns).
- File storage encryption for private files.
- TLS for all external communication.

## Storage Strategy

Abstract storage for:
- User-uploaded files
- Private files
- Temporary exports
- Future attachments

### Rules

| Storage Type | Access |
|-------------|--------|
| User-uploaded files | Authenticated access only |
| Private files | Private storage, never public |
| Temporary exports | Private storage, expire/delete |
| Audit exports | Private storage, lifecycle expiration |

Do not expose sensitive generated files through public storage.

## Audit Export Lifecycle

```
User requests export
    ↓
Create export job
    ↓
Queue
    ↓
Generate file
    ↓
Store in private storage
    ↓
Notify user
    ↓
Temporary download link
    ↓
Expire/delete export file
```

Audit export files should have a lifecycle/expiration policy.

## Backup & Disaster Recovery

Document:
- Database backup
- File/storage backup
- Backup verification
- Restore procedure
- Retention
- Recovery procedure
- RPO (recovery point objective)
- RTO (recovery time objective)

RPO/RTO may be deployment-specific. Do not claim backup exists without documenting how restoration works.

## Data Retention

Define retention separately for:
- Audit logs
- Application logs
- Server logs
- Security logs
- Telescope
- Sessions
- Revoked tokens
- Password history
- Notifications
- Temporary exports
- Backups

Do not apply one global retention period to everything.