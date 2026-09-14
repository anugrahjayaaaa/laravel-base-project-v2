# Logging Strategy

## 1. Overview

Logging is split into **five distinct concerns**. They serve different
audiences and must not be conflated:

| Concern | Purpose | Audience | Source of Truth |
|---------|---------|----------|-----------------|
| Audit Trail | WHO did WHAT (business/security accountability) | Admins, security, ops | Mutation caller (Action/Service layer) |
| Application Logs | WHAT happened technically (app errors, warnings, info) | Developers, ops | Application code via `Log::` |
| Security Logs | Security-relevant events (failed login, lock, rate-limit) | Security team | Application code via `Log::` |
| Server Logs | WHAT happened at infrastructure level | SRE, infra team | Nginx/PHP-FPM/system |
| Telescope | HOW the Laravel runtime behaved | Developers | Laravel Telescope |

- **Audit Trail ≠ Application Logs.** Audit records a mutation. Application
  logs record operational events (success, failure, exceptions).
- Do NOT log every successful request. Log only operations where operational
  visibility is useful.
- Do NOT use application logs as a replacement for audit records.

## 2. Channels

Laravel channels are environment-configurable. The `stack` channel is the
default for development; production should route to persistent, rotated
storage (daily + optional external sink).

| Channel | Use |
|---------|-----|
| `stack` (default) | Development — routes to console |
| `daily` | Production — daily rotating files |
| `errorlog` | PHP error log fallback |
| `slack` | Critical alerts (ops page) |
| `syslog` | System-level forwarding (optional) |

Retention is defined in [retention.md](../operations/retention.md) and is
**configurable at the infrastructure/deployment level**. Application log
retention is **separate** from Audit Trail retention.

## 3. Log Levels

Use a level that matches the failure classification — do NOT treat every
rejected request as an ERROR.

| Level | When |
|-------|------|
| `error` | Unexpected exception, DB exception, queue job failure |
| `warning` | External service failure (recoverable), security-relevant rejection, expected-but-unusual condition |
| `notice` | Normal-but-significant events (e.g. successful audit export) |
| `info` | Informational operational events (e.g. `user.deactivate.completed`) |
| `debug` | Detailed debug context (disabled in production) |

## 4. Structured Logging

Use stable **event/action names** — never free-text messages that change.

```php
Log::warning('auth.login.failed', [
    'request_id' => $requestId,
    'user_id'    => $user?->id,
    'ip'         => request()->ip(),
    'reason'     => 'invalid_credentials',
]);
```

**Event name pattern:** `entity.action[.status]`

| Event Name | Status | Level |
|------------|--------|-------|
| `auth.login.failed` | — | warning |
| `auth.login.locked` | — | warning |
| `auth.logout` | — | info |
| `auth.session.revoked` | — | info |
| `user.create.failed` | — | error |
| `user.update.failed` | — | error |
| `user.delete.failed` | — | error |
| `user.activate.failed` | — | error |
| `user.deactivate.failed` | — | error |
| `user.lock.failed` | — | error |
| `user.unlock.failed` | — | error |
| `password.change.failed` | — | error |
| `password.reset.failed` | — | error |
| `role.permission.update.failed` | — | error |
| `user.role.update.failed` | — | error |
| `audit.export.failed` | — | error |
| `notification.send.failed` | — | error |

> **Convention:** for operations where logging the *success* is useful for
> operational visibility, emit a `.completed` event at `info` level. The event
> name list above covers failures and security events (the high-signal ones).
> Add `.completed` events sparingly — only where success visibility is useful
> (e.g. `audit.export.completed`).

### Context Envelope

Every log call that emits a failure, warning, or security event MUST include:

| Field | Description |
|-------|-------------|
| `action` | Stable event/action name |
| `status` | `failed`, `rejected`, `locked`, `completed` (where meaningful) |
| `request_id` | Correlation/request ID (always) |
| `user_id` | Authenticated user ID, or `null` |
| `resource_id` | Relevant resource subject, or `null` |
| `route` | Route name |
| `method` | HTTP method |
| `exception` | Exception class (on failures) |
| `message` | Safe exception message (no internals) |
| `duration_ms` | Execution duration where useful |
| `environment` | `app.env` |
| `timestamp` | Server time |

## 5. Failure Classification

### Expected Failures (NOT errors)

| Failure | Classification | Log Level |
|---------|----------------|-----------|
| Invalid validation | Business/input rule | `info` / `notice` (or no app log) |
| Authentication failure | Security | `warning` (structured security log) |
| Authorization denied | Security/authorization | `warning` (structured log) |
| Rate limit exceeded | Security/rate-limit | `warning` (structured log) |
| Business rule rejection | Domain | `info` / `warning` depending on significance |
| Account locked | Security | `warning` (security log) |

### Unexpected Failures (errors)

| Failure | Log Level |
|---------|-----------|
| Database exception | `error` |
| Unexpected exception (uncaught) | `error` |
| Queue job failure | `error` |
| External service failure (severe) | `error` / `warning` depending on severity |
| Transaction rollback | `error` |

## 6. Transaction Failure

```
Begin transaction
    ↓
Execute mutation
    ↓
Failure
    ↓
Rollback
    ↓
Log failure (with rollback indicator)
    ↓
Return appropriate response
```

- The log entry MUST indicate that the transaction failed / rolled back where
  that information is meaningful.
- **Do not create an audit record claiming a successful mutation before the
  transaction commits.** Audit records are written **within the same
  transaction as the mutation, before the COMMIT**, and only persist if the
  transaction commits successfully. A rolled-back transaction must not
  leave a false-success audit record.
- A failed transaction produces a **failure** log (e.g. `user.update.failed`)
  and **no** audit record.

## 7. Audit vs Application Log

### User deactivation succeeds

| Log Type | Entry |
|----------|-------|
| Audit Trail | `Admin 15 deactivated User 42` |
| Application Log | `user.deactivate.completed` |

### User deactivation fails (DB exception)

| Log Type | Entry |
|----------|-------|
| Audit Trail | _(none — no successful mutation)_ |
| Application Log | `user.deactivate.failed` with `exception=DatabaseException`, `request_id=...` |

### Security-relevant rejected attempt

Record the appropriate security/audit information **without** falsely
representing the mutation as successful. A failed attempt produces a
security `warning` log and **no** audit mutation record.

## 8. Request / Correlation ID

Every relevant application log MUST include the request/correlation ID.
This allows the full request chain to be correlated:

```
HTTP Request → Controller → Service → DB → Event → Queue → Notification
```

- The correlation ID is **generated at middleware level** (see FOUND-008).
- **Generation:** a UUID (or ULID) generated in middleware on the first
  request. If the client provides an `X-Request-ID` header, that value is
  respected and propagated (echoed back in the response header).
- It is available globally via a helper or context accessor.
- **Propagation:** automatically attached to all structured log calls via
  Laravel's `Log::withContext(['request_id' => $id])` at middleware level.
- **Response:** included in the `X-Request-ID` response header on every
  HTTP response, and in the `meta.request_id` field of API responses.
- **Asynchronous jobs:** the correlation ID is passed into the job's
  constructor and re-established via `Log::withContext` at job execution.
- **Security/privacy:** the correlation ID must NOT contain sensitive data
  — use an opaque UUID/ULID, not user PII.
- **Logs must NOT log raw request bodies indiscriminately.** Correlation ID
  is metadata for tracing, not a substitute for request data.

## 9. Exception Handling

The application MUST use a centralized exception-handling strategy
(`app/Exceptions/Handler.php`). Do NOT duplicate exception logging in every
controller or service.

The handler MUST:

1. Log unexpected exceptions automatically (with full context).
2. Return safe API responses (generic message, machine error code, `request_id`).
3. Return appropriate web error pages (no stack traces in production).
4. Avoid leaking implementation details.
5. Preserve the request/correlation ID in every response.
6. Distinguish expected application errors (HttpException) from unexpected
   system errors (`\Throwable`).

> **Note:** Laravel does NOT log `HttpException` (404/405/403/422) by
> default — they are in the base `$dontReport` list. To make 4xx errors
> observable, add a global middleware that logs `4xx` (excluding 404 crawler
> noise) to the application log channel.

## 10. Logging Privacy

Logs are potentially sensitive operational data.

- **Sensitive-field redaction:** never log sensitive values.
- **Safe contextual logging:** include IDs and categories, not raw values.
- **Environment-aware stack traces:** full stack trace in local/staging,
  suppressed in production.
- **Production-safe exception messages:** return generic messages to clients;
  full detail only in server-side logs.
- **Restricted access:** log storage must be access-controlled.
- **Retention:** see [retention.md](../operations/retention.md).

### Never Log

- passwords
- password hashes
- bearer tokens
- session secrets
- reset tokens
- API secrets
- private keys
- complete authentication credentials
- raw request bodies (indiscriminately)

Never log raw request bodies indiscriminately. Extract only the fields needed
for debugging (IDs, status, reason codes), never credentials or secrets.

## 11. Retention

|| Data Type | Default | Config Key |
||-----------|---------|------------|
|| Application logs | 30 days | `retention.application_logs.days` |
|| Security logs | 90 days | `retention.security_logs.days` |
|| Audit logs | Indefinite | `retention.audit_logs.days` |
|| Telescope data | 7 days | `retention.telescope.days` |

Application logs and Audit Trail retention are **separate policies**. See
[retention.md](../operations/retention.md) for the full schedule and enforcement.

## 12. Testing

Testing MUST verify:

- Expected failures produce the correct log level / event name
- Unexpected exceptions are logged
- Sensitive data is not logged
- Request/correlation ID is present in log entries
- Audit records are written within the same transaction as the mutation (before COMMIT), persisting only on successful commit
- Failed transactions do not create false successful audit events
- Queue failures are observable
- Authorization / security failures are appropriately observable

See [QA Tracker](../../planning/qa-tracker.md) — Logging QA section.

## ADR References

- ADR-013: Application Logging Strategy
- ADR-008: Audit Trail vs Telescope separation