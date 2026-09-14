# DEP-003: Spatie Activitylog for Audit Trail

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Audit Trail (Phase 10 `AUDIT-001`, Phase 1 `FOUND-006`)

## Context

The Base Project requires an Audit Trail — a record of WHO did WHAT to
WHICH resource and WHEN. This is distinct from application logs (technical
WHAT), server logs (infrastructure), and Telescope (runtime HOW). The audit
trail must be:
- Read-only in the UI
- Permission-gated for view/export
- Tied to the request/correlation ID
- Written within the same database transaction as the mutation, before the
  COMMIT (no false-success records on rollback)

## Decision

Use `spatie/laravel-activitylog` for the audit trail, behind an
application-level `Audit` abstraction layer.

## Alternatives Considered

- **Laravel model events + observers**: The architecture explicitly rejects
  observers as the primary audit mechanism (ADR-008). Observers fire at
  unpredictable times relative to transactions and do not capture
  application-level context (request_id, IP, user agent) cleanly.
- **Custom audit table**: A `audits` table with manual inserts in Actions.
  Activitylog provides the same schema with less code and better
  causer/subject tracking. Custom implementation would duplicate this.
- **Laravel Telescope logs**: Telescope records technical traces, not
  business-security accountability. Different audience and purpose.
  Explicitly not a substitute (ADR-008).
- **Laravel Pulse**: Provides metrics/monitoring for queues, caches,
  exceptions — not audit trails. Wrong concern.

## Why This Decision

Activitylog provides:
- `activity_log` table with causer/subject/event/causal relationships
- Built-in `causedBy()`, `performedOn()`, `withProperties()` fluent API
- morphMap support for arbitrary subject/causer types
- Battle-tested by the Laravel community (established package)

The Base Project documents (audit-trail.md) explicitly state: "Use an
established audit package rather than building from scratch."

## Consequences

- Application code does NOT call Activitylog directly. All audit writes go
  through a dedicated `Audit` service class (abstraction layer).
- Audit records are written **within the same database transaction** as the
  mutation, **before the COMMIT**, and only persist if the transaction commits
  successfully. The `Audit` abstraction enforces this by being called from
  within the Action/Service layer inside the transaction scope.
- Sensitive data (passwords, tokens) must be scrubbed before storing in
  `properties` — the abstraction handles this.
- Version constraint: `^4.8` (NOT v5, which requires PHP 8.4+).

## Security Implications

- Audit records contain IP addresses and user agents — treat as sensitive
  operational data with restricted access.
- Audit export requires the `audit.export` permission.
- Audit view requires the `audit.view` permission.
- Audit table is append-only — no delete endpoints exposed.

## Maintenance Implications

- Activitylog v4.x is the active line for PHP 8.3. Do not upgrade to v5
  until the project targets PHP 8.4+.
- Schema is minimal and stable — `activity_log` table.
- The abstraction layer means version upgrades only affect the `Audit`
  service class.

## Reversal / Replacement

- Replace the `Audit` abstraction's implementation with a custom audit
  backend.
- The `activity_log` table schema would be replaced; the abstraction's API
  contract (causer, subject, action, metadata) remains stable.
- All application code calling `Audit::record(...)` is unaffected.
