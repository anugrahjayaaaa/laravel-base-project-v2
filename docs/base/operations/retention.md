# Retention

## Data Retention Policy

Define retention separately for each data type. Do NOT apply one global retention period to everything.

## Retention Schedule

|| Data Type | Default Retention | Rationale |
||----------|------------------|-----------|
|| Audit logs | Indefinite (until manual review) | Legal/security compliance |
|| Audit export files | 7 days after generation (`App\Models\AuditExport::RETENTION_DAYS`) | Temporary download window, private disk `audit-exports/` |
|| Application logs | 30 days | Debug/operational troubleshooting |
|| Security logs | 90 days | Incident response, security investigation |
|| Server logs | Configured by infra team | Infrastructure monitoring |
| Pulse data | Pulse's own retention config | Runtime metrics, ingested on a schedule |
|| Sessions | Until expiration (`web_session_expire_*` setting) | Automatic cleanup |
|| Revoked tokens | 30 days after revocation | Allow investigation window |
|| Password history | Configurable (`security.password_history.count`) | Prevent reuse (IM8 policy) |
|| Password history count | Configurable count (default: 5) | Balance security vs usability |
|| Notifications | 90 days | Inbox cleanup |
|| Ad-hoc temporary exports | 1 day (`storage/tmp`) | Cleanup after download. **Not used by this base** — nothing writes there; see the note below |
|| Database backups | 7 daily, 4 weekly, 12 monthly | Standard backup rotation |
|| File backups | 7 daily, 4 weekly | Sync with database |
|| Failed jobs | 7 days | Investigation window |
|| Password reset tokens | 60 minutes (default) | Security — short window |

### Two export rows, two different things

"Audit export files" (7 days) and "Ad-hoc temporary exports" (1 day) read as one
policy stated twice, which is how the audit trail got shipped with two numbers
attached to it and neither settled. They are not the same artifact:

- **Audit export files** are written by `GenerateAuditExport` to the private
  disk under `audit-exports/`, keyed by `audit_exports.expires_at`, and swept
  nightly by `routes/console.php`. `AuditExport::RETENTION_DAYS` is the single
  source of that number.
- **Ad-hoc temporary exports** are a generic slot for a future artifact that
  writes to `storage/tmp`. **Nothing in this base writes there**, so the row
  describes a policy that has no implementation behind it yet.

If an artifact ever does use `storage/tmp`, the row above is where its retention
is declared — and the audit export's number must not be changed to match it.

## Configuration

Retention values are configurable:
```
retention.audit_logs.days         (default: indefinite)
retention.application_logs.days   (default: 30)
retention.security_logs.days      (default: 90)
pulse retention                   (Pulse's own config, not this project)
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