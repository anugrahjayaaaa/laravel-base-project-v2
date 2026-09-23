# Audit Trail

## Overview

Use an established audit package rather than building from scratch.

Create an application-level audit abstraction so the application is not tightly coupled to the package API.

Audit Trail is NOT interchangeable with Telescope.

## Purpose

Audit Trail is intended for:
- Administrators
- Security users
- Operational users
- Non-technical users

Audit records must be read-only through the UI.

## Capabilities

| Capability | Description |
|-----------|-------------|
| View | Audit list view |
| Detail view | Full audit record details |
| Search | Full-text search |
| Filter | Filter by actor, action, date range, etc. |
| Pagination | Standard paginated results |
| Export | Asynchronous export of audit records |

## Metadata

Each audit record must capture:

| Field | Description |
|-------|-------------|
| Actor | Who performed the action (user_id) |
| Action | What was done (action type) |
| Subject | What was affected (subject_type, subject_id) |
| Before | State before change |
| After | State after change |
| Metadata | Additional context (request_id, IP, user agent) |
| IP | Client IP address |
| User Agent | HTTP user agent |
| Request/Correlation ID | Traceability to request |
| Timestamp | When it happened |
| Lock reason | Where relevant |
| Source/context | Where relevant |

## Source of Truth

Audit source of truth: **mutation caller**.

Do NOT make observers the primary audit mechanism.

Audit logging should be done explicitly in the Action/Service layer where the
mutation occurs, not passively via model observers.

See [Application Components](../architecture/application-components.md) for
the component responsibility model and [Application Boundaries](../architecture/application-boundaries.md)
for transaction/after-commit rules.

## Model Audit (Auditable Trait)

User model carries an `Auditable` trait (`App\Models\Concerns\Auditable`)
that wraps spatie/activitylog. Controllers call `$user->audit()` directly:

```php
// In controller (thin, after action runs):
$user->audit('user.locked', $request->user(), ['reason' => 'security']);
```

- `performedOn` = the model itself (`$this`)
- `causedBy` = explicit causer argument (admin / system)
- `event` = snake_case event name (e.g. `user.locked`, `user.deactivated`)
- `properties` = optional context array

The trait is the single model-level audit entry point. Do NOT audit inside
observers — the caller is responsible for explicit audit logging.

## Transaction Boundaries

- Audit records must be written **within the same database transaction** as
  the mutation, and **before the COMMIT**.
- A successful mutation produces one audit record.
- A failed transaction (rollback) produces **no** audit record and no
  "successful mutation" claim. The failure must be observable via
  application/security logging instead (e.g. `user.update.failed` with
  exception, request_id).
- Audits that capture before/after state must reflect the **committed** state
  only.

## Asynchronous Export

Conceptual flow:

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
Temporary download
    ↓
Expire/delete export file
```

- Audit exports must be asynchronous.
- Audit exports must use private storage.
- Export files have lifecycle/expiration policy.

## Security

- Read-only through UI.
- Export requires `audit.export` permission.
- View requires `audit.view` permission.
- Never store sensitive data in plaintext in audit logs.
- Consider log rotation/archiving for long-term retention.

## ADR References

- ADR-008: Audit Trail vs Telescope separation