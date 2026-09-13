# Retention

## Data Retention Policy

Define retention separately for each data type. Do NOT apply one global retention period to everything.

## Retention Schedule

| Data Type | Default Retention | Rationale |
|----------|------------------|-----------|
| Audit logs | Indefinite (until manual review) | Legal/security compliance |
| Audit export files | 7 days after generation | Temporary download window |
| Application logs | 30 days | Debug/operational troubleshooting |
| Server logs | Configured by infra team | Infrastructure monitoring |
| Telescope data | 7 days | Technical debugging, auto-purge |
| Sessions | Until expiration (`SESSION_LIFETIME`) | Automatic cleanup |
| Revoked tokens | 30 days after revocation | Allow investigation window |
| Password history | Configurable (`security.password_history.count`) | Prevent reuse (IM8 policy) |
| Password history count | Configurable count (default: 5) | Balance security vs usability |
| Notifications | 90 days | Inbox cleanup |
| Temporary exports | 1 day (`storage/tmp`) | Cleanup after download |
| Database backups | 7 daily, 4 weekly, 12 monthly | Standard backup rotation |
| File backups | 7 daily, 4 weekly | Sync with database |
| Failed jobs | 7 days | Investigation window |
| Password reset tokens | 60 minutes (default) | Security — short window |

## Configuration

Retention values are configurable:
```
retention.audit_logs.days         (default: indefinite)
retention.application_logs.days   (default: 30)
retention.telescope.days          (default: 7)
retention.sessions.minutes        (default: 120*60)
retention.tokens.revoked.days     (default: 30)
retention.notifications.days      (default: 90)
retention.tmp_exports.hours       (default: 24)
retention.failed_jobs.days        (default: 7)
retention.password_reset.minutes  (default: 60)

retention.backup.daily.count      (default: 7)
retention.backup.weekly.count     (default: 4)
retention.backup.monthly.count    (default: 12)
```

## Enforcement

- Application-level garbage collection jobs (scheduled daily).
- Database-level TTL where supported (MySQL event scheduler, PostgreSQL TTL).
- File-level cleanup jobs for storage exports.
- Session cleanup handled by Laravel's session driver.

## Audit

- Retention configuration changes are audited.
- Retention job execution logged.
- Alert on retention job failure.