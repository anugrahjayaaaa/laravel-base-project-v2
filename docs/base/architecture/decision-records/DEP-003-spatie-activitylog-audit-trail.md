# DEP-003: Spatie Activitylog for Audit Trail

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Audit Trail (Phase 10 `AUDIT-001`, Phase 1 `FOUND-006`)

## Context

The Base Project requires an Audit Trail — a record of WHO did WHAT to
WHICH resource and WHEN. This is distinct from application logs (technical
WHAT), server logs (infrastructure), and Laravel Pulse (runtime health). The audit
trail must be:
- Read-only in the UI
- Permission-gated for view/export
- Tied to the request/correlation ID
- Written within the same database transaction as the mutation, before the
  COMMIT (no false-success records on rollback)

## Decision

Use `spatie/laravel-activitylog` for the audit trail, behind an
application-level abstraction layer — a model trait, not a service class. See
"Abstraction shape: trait, not service" below.

## Alternatives Considered

- **Laravel model events + observers**: The architecture explicitly rejects
  observers as the primary audit mechanism (ADR-008). Observers fire at
  unpredictable times relative to transactions and do not capture
  application-level context (request_id, IP, user agent) cleanly.
- **Custom audit table**: A `audits` table with manual inserts in Actions.
  Activitylog provides the same schema with less code and better
  causer/subject tracking. Custom implementation would duplicate this.
- **Laravel Pulse**: records runtime metrics (queue, cache, exceptions), not
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

## Abstraction shape: trait, not service

This DEP originally named a dedicated `Audit` service class with an
`Audit::record(...)` entry point. **What shipped is a trait**,
`App\Models\Concerns\Auditable`, called as `$subject->audit($event, $causer,
$properties)`. The DEP is corrected here rather than the code, because the trait
won for a recorded reason — not by accident.

The service shape lost because it makes each caller assemble its own context.
Every call site needs the same four derived values (`source`, `ip`, `user_agent`,
`request_id`) and none of them are specific to the event. Handed a service, that
derivation got copied — and it did: `request()->is('api/*') ? 'api' : 'web'`
existed in six files under two different key names (`source` and `channel`), with
only some of them carrying a user agent. A row written by one path could not be
compared with a row written by another.

Putting the derivation on the model that carries the trait makes
`Auditable::auditContext()` the one place it exists, and every caller takes it
without asking. A service would have needed the same discipline and had no
mechanism enforcing it.

Three call sites do not go through a model, and each has a named reason:

| Writer | Why it is not the trait |
|---|---|
| `App\Jobs\Concerns\AuditsSystemActivity` | A queued job has no subject model to call it through. Nullable causer, `source => system`. |
| `App\Services\InactivityLock` | Two callers, no single subject model; the service owns the row. |
| `Auditable::auditBulk()` | One insert for N rows; a bulk action over 200 users would be 200 round trips inside a transaction already holding locks on those rows. |

`Auditable::audit()` returns `void` — a caller that needs the row it just wrote
uses Spatie's builder directly, which is what `AuditViewerFilterTest` does to
back-date fixtures.

## Consequences

- Application code does NOT call Activitylog directly. All audit writes go
  through the `Auditable` trait (or one of the three writers above).
- Audit records are written **within the same database transaction** as the
  mutation, **before the COMMIT**, and only persist if the transaction commits
  successfully.
- **Who writes them**: the Action that performs the mutation. Controllers
  orchestrate and MUST NOT add an audit call for a mutation an Action already
  performs — that is a duplicate record for one mutation. See
  [Action-First Audit Logging Standard](../application-boundaries.md#action-first-audit-logging-standard)
  for the full rule, including bulk mutations.
- Sensitive data (passwords, tokens) must not be passed into `$properties`.
  **This is a convention each call site follows by hand, not something the
  abstraction enforces** — there is no scrubber. `AuditPropertyScrubTest` is what
  holds it: it scans every row after exercising the mutation paths and fails if
  a password, token, secret or `remember_token` value is present. A scrubber was
  deliberately not built; it would be machinery for a problem no current call
  site has, and it would hide the caller's mistake instead of failing on it.
  Revisit when a caller that logs sensitive state actually appears.
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
- The abstraction layer means a version upgrade touches the `Auditable` trait
  and the three non-trait writers, not every call site.
- `config/activitylog.php` is **published** (Phase 10, `P10-D3`) because two
  package defaults contradict documented project decisions: the 365-day
  `delete_records_older_than_days` would silently break the "audit logs are
  indefinite" retention promise the moment anyone scheduled `activitylog:clean`,
  and `subject_returns_soft_deleted_models => false` degrades the viewer's
  Target column to a bare `#id` for exactly the soft-deleted subjects an
  incident review is looking for.

## Reversal / Replacement

- Replace the `Auditable` trait's implementation with a custom audit backend.
  The `activity_log` table schema would be replaced; the contract the call sites
  rely on (causer, subject, event, and the `source` / `ip` / `user_agent` /
  `request_id` context `auditContext()` derives) remains stable.
- All 41 call sites of `$model->audit(...)` are unaffected by a swap behind the
  trait.
