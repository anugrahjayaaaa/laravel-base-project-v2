# Architecture Decision Records (ADRs)

> Records decisions that affect architecture. Format: ADR-xxx + title + status + context + decision + consequences.

## ADR-001: API-first architecture

**Status**: Accepted
**Context**: The base project must be usable by web, mobile, and other frontend frameworks independently from Laravel internals.
**Decision**: All core capabilities have a clean API boundary. API contracts designed for independent frontend consumption.
**Consequences**: API resources as serialization contract; no business logic in resources; versioned `/api/v1/`.
**Related**: REQ-AUTH-001, REQ-API-001, AUTH-002, API-001

## ADR-002: UI-independent core

**Status**: Accepted
**Context**: Business logic must not depend on Blade/Vue/React. UI is replaceable.
**Decision**: Business logic lives in Actions/Services (domain layer). UI layer communicates via API only.
**Consequences**: No business logic in controllers or views. Resources handle only serialization.
**Related**: P0-001, AUTH-005, API-002

## ADR-003: Database queue with Redis compatibility

**Status**: Accepted
**Context**: Base project should work without Redis but remain Redis-compatible for production scaling.
**Decision**: Default queue is `database`. Architecture uses Laravel abstractions (Queue, Cache, Lock, RateLimiter) so Redis can be swapped in via config only.
**Consequences**: Business logic never references Redis directly. All infrastructure via facades.
**Related**: QUEUE-001, FOUND-001, CACHE-001

## ADR-004: Role-derived permissions

**Status**: Accepted
**Context**: User permissions must reflect their role's permissions automatically. Copying permissions to each user creates consistency risks.
**Decision**: User → Role → Permissions. Role changes automatically propagate. Do NOT physically copy permissions unless explicitly required.
**Consequences**: Spatie Permission package used. Single source of truth = roles.
**Related**: RBAC-001, RBAC-004, REQ-RBAC-001

## ADR-005: Separate active/inactive and locked/unlocked

**Status**: Accepted
**Context**: A single status field conflates lifecycle (active/inactive) with security state (locked/unlocked). This leads to accidental lockouts or security confusion.
**Decision**: Use distinct fields: `is_active`, `is_locked`, `email_verified_at`, `last_activity_at`, password security state.
**Consequences**: Separate permission gates: `users.activate`, `users.deactivate`, `users.lock`, `users.unlock`.
**Related**: REQ-AUTH-008, USER-006, USER-007

## ADR-006: Session/device strategy

**Status**: Accepted
**Context**: Users may have multiple devices (Web, Mobile) but should not have concurrent sessions on the same device type.
**Decision**: One active Web session at a time; one active Mobile session at a time. Web and Mobile can coexist. Central session management abstraction.
**Consequences**: Login on Web B revokes Web A. Login on Mobile B revokes Mobile A. Central abstraction prevents scattered invalidation.
**Related**: AUTH-006, AUTH-009, AUTH-010, SES-001, INACT-001

## ADR-007: Password history strategy

**Status**: Accepted
**Context**: Password reuse undermines security. Need to prevent users from cycling back to old passwords.
**Decision**: Store password hashes in history table. New password cannot match N previous passwords. Configurable via Settings: `security.password_history.enabled`, `security.password_history.count`.
**Consequences**: Password change validates against history. History stored as hashes only (never plaintext).
**Related**: PWD-003, PWD-004, REQ-AUTH-009, REQ-AUTH-010

## ADR-008: Audit Trail vs Technical Observability separation

**Status**: Accepted

**Context**: Audit Trail (business/security accountability), Application Logs
(technical behavior), Security Logs (security-relevant events), Server Logs
(infrastructure), and Pulse (runtime metrics) serve different audiences
and purposes. Without a clear contract, teams conflate them, log sensitive data,
fail to correlate failures across layers, and create false audit records on
transaction rollback.

**Decision**: Five-tier classification:
1. **Audit Trail** — WHO did WHAT (business/security accountability).
   Source of truth = mutation caller. Written **within the same transaction
   as the mutation, before the COMMIT**, and only persists if the transaction
   commits successfully. A rolled-back transaction produces no audit record.
2. **Application Logs** — WHAT happened technically (errors, warnings, info).
   Structured, event-named, always include correlation ID.
3. **Security Logs** — Security-relevant events (failed login, account lock,
   password reset activity, security policy violations). May overlap with Audit
   Trail events but retained separately for security monitoring.
4. **Server Logs** — infrastructure level (Nginx/PHP-FPM). Managed by infra.
5. **Laravel Pulse** — HOW healthy the Laravel runtime is (metrics only).

Audit Trail is the primary audit mechanism (non-technical users). Pulse is
for technical debugging only. Do not merge concerns. Audit source of truth =
mutation caller, not observers.

**Consequences**: Two separate systems for audit and technical observability.
Security logs retain separately from application logs. Audit records stored in
audit table with full metadata. Pulse metrics are technical only. Audit source
of truth = mutation caller. Security Logs have shorter retention than audit
(see retention.md).

**Related**: AUDIT-001, AUDIT-003, MONITOR-001, SEC-001, SEC-002, RETAIN-001,
  REQ-AUDIT-001

## ADR-009: API versioning

**Status**: Accepted
**Context**: API will evolve. Need a strategy that doesn't break existing clients.
**Decision**: URL versioning (`/api/v1/`, `/api/v2/`). V1 remains stable. Application logic shared when behavior identical; separate implementation when behavior changes.
**Consequences**: Versioned code structure: Controllers/Api/V1, Resources/Api/V1. Deprecation warnings via headers.
**Related**: API-001, API-002, REQ-API-001

## ADR-010: Database ID strategy

**Status**: Accepted
**Context**: Need a consistent primary key strategy. UUIDs are powerful but add complexity.
**Decision**: Default to integer/bigint. Use UUID only when concrete requirement exists (e.g., public-facing IDs requiring unpredictability).
**Consequences**: Most tables use auto-increment bigint. UUID requires explicit justification.
**Related**: DB-001, REQ-NFR-004

## ADR-011: Soft delete strategy

**Status**: Accepted
**Context**: Not all models should be soft-deletable. Some (audit, permissions) must never be deleted; some (sessions) should expire.
**Decision**: Soft delete on User only. No soft delete on Audit, Permission, Settings. Sessions use lifecycle cleanup.
**Consequences**: `deleted_at` column only on selected tables. Queries exclude soft-deleted by default.
**Related**: USER-005, AUDIT-001, DB-001, Soft Delete doc

## ADR-012: Cascade relationship strategy

**Status**: Accepted
**Context**: Blindly adding CASCADE to foreign keys can cause unintended data loss.
**Decision**: Cascade only when parent-child lifecycle semantically requires it (e.g., Role deletion cleans up role_user pivot). For critical entities, use restricted deletes with explicit error handling.
**Consequences**: Careful foreign key design. Documented cascade rules per relationship.
**Related**: DB-001, RBAC-001

## ADR-013: Application Logging Strategy

**Status**: Accepted
**Context**: Audit Trail, Application Logs, Server Logs, and Pulse serve
distinct audiences. Without a clear contract, teams conflate them, log
sensitive data, fail to correlate failures across layers, and create false
audit records on transaction rollback. Laravel also does not log
`HttpException` (4xx) by default, making security-relevant rejections
invisible in monitoring.
**Decision**: Five-tier logging/observability model (see also ADR-008):
1. **Audit Trail** — WHO did WHAT (business/security accountability).
   Source of truth = mutation caller. Written **within the same transaction
   as the mutation, before the COMMIT**, and only persists if the transaction
   commits successfully. A rolled-back transaction produces no audit record.
2. **Application Logs** — WHAT happened technically (errors, warnings, info).
   Structured, event-named, always include correlation ID.
3. **Server Logs** — infrastructure level (Nginx/PHP-FPM). Managed by infra.
4. **Laravel Pulse** — HOW healthy the Laravel runtime is (metrics only).
Failures are classified: expected (validation, authn, authz, rate limit,
business rule → warning/info) vs unexpected (DB exception, uncaught error,
queue failure → error). Transactions log rollback explicitly and never
produce a false-success audit record. Sensitive data is never logged; stack
traces are environment-aware. Correlation ID is generated at middleware
level and propagated to jobs. A global 4xx-logging middleware makes
HttpException observable.
**Consequences**: Centralized exception handler logs once (not in every
controller). Stable event/action names. Structured context envelope
(action, status, request_id, user_id, resource_id, route, method, exception,
message, duration_ms, environment, timestamp). See
`docs/base/infrastructure/logging.md`.
**Related**: FOUND-008 (correlation ID middleware), AUDIT-001/AUDIT-003
(audit in Actions), MONITOR-001 (Pulse), RETAIN-001

## ADR-014: System role protection

**Status**: Accepted
**Context**: System roles (`superadmin`, `admin`, `user`) must be protected from
deletion, renaming, and destructive permission manipulation. Accidents or
malicious actors could lock out all administrators or break baseline operation.
**Decision**: System roles cannot be deleted, renamed, or have their
system-assigned permissions stripped in a way that breaks baseline operation.
The last valid superadmin cannot be removed. Superadmin bypasses where
explicitly allowed but NOT everywhere (last-superadmin guard, system-role
protection, sensitive-data redaction, session revocation all remain enforced).
**Consequences**: Role/role assignment mutations are guarded by checks within
the mutation transaction. API is the enforcement point; UI hiding is for UX only.
**Related**: RBAC-005, AUTH-001, RBAC-001

## ADR-015: UI as replaceable API client

**Status**: Accepted
**Context**: The Base Project is API-first. The UI must be replaceable without
rewriting business logic, and must not leak brand-specific design tokens.
**Decision**: The UI is a client of the application/API. Business logic must not
live in Blade/views. UI permission checks are for visibility/UX only; the
backend is the security boundary. The design system uses semantic tokens
(`primary`, `secondary`, `success`, `warning`, `danger`, `info`, `surface`,
`text`, `muted`, `border`) and is implementation-independent.
**Consequences**: Admin UI may use Blade/AdminLTE; later replaceable by
Vue/React/mobile. No business logic in views. Design system tokens are semantic.
**Related**: ADR-001, ADR-002, AUTH-005, UI-001

## ADR-016: Cascade delete when justified (updates ADR-012)

**Status**: Accepted
**Context**: ADR-012 originally stated cascade delete is forbidden by default.
The approved decision is updated: cascade rules ARE allowed when appropriate.
**Decision**: Cascade delete may be used when child records have no independent
lifecycle and deletion semantics are unambiguous. Restrict/no-action should be
used when deleting the parent would create unacceptable data loss. Soft-deleted
parents require careful handling of child records. Cascade behavior must be
intentional and documented per relationship. Never blindly add cascade to every
foreign key.
**Consequences**: Foreign key design is per-relationship, intentional, and
documented. No blanket cascade or blanket restrict. This updates ADR-012.
**Related**: ADR-012, DB-001, RBAC-001

## ADR-017: Configuration vs runtime settings

**Status**: Accepted
**Context**: Some project settings are deployment-static (infrastructure), while
others are operational (changeable at runtime by administrators). They must be
managed differently: validation, authorization, audit, cache invalidation.
**Decision**: Two-layer configuration:
- **Configuration**: `.env` / `config/*.php` — infrastructure (DB, Redis, mail,
  queue/cache drivers, secrets). Not manageable via UI.
- **Runtime Settings**: database settings (registration.enabled, failed-login
  policy, inactivity threshold, rate-limit values, password policy). Managed via
  UI with validation, authorization, audit, and cache invalidation.
Secrets must never be moved into database settings merely for convenience.
**Consequences**: Settings management UI operates only on runtime settings.
Technical infrastructure settings remain in config files. All settings changes
are audited.
**Related**: SET-001, SET-002, FOUND-008, USER-006

## ADR-018: last_activity_at and never-logged-in policy

**Status**: Accepted
**Context**: `last_activity_at` tracks the last successful login. Users who
have never logged in have `last_activity_at = NULL` and must not be excluded
from inactivity policy. Updating on every request is noisy and unnecessary.
Unlocking an account is an administrative action, not user activity — it
must NOT populate or change `last_activity_at`.
**Decision**: `last_activity_at` is updated **only after a successful login**
(Login-Only Strategy). It is not updated for ordinary mutations or HTTP
requests. Inactivity is configurable via `inactivity_lock_days`,
`inactivity_lock_grace_enabled`, and `inactivity_lock_grace_days`.
Never-logged-in users (`last_activity_at = NULL`) are included in the
inactivity query; when the grace toggle is enabled, the threshold is
`inactivity_lock_days + inactivity_lock_grace_days`, otherwise it is only
`inactivity_lock_days`. **Unlocking does not set
`last_activity_at`** — NULL is preserved until the user performs an actual
application action (successful login or meaningful mutation). Inactivity is
implemented as a scheduled background process. Inactivity lock revokes
active sessions/tokens.
**Consequences**: Inactivity query must handle NULL explicitly. The grace
threshold is a setting, not a hard-coded assumption. Session/token revocation
on inactivity lock is enforced in the background process. Unlock operations
must NOT update `last_activity_at` — unlocking is an admin action, not user
activity. Audit records for unlock events are separate from activity tracking.
**Related**: AUTH-006, INACT-001, SES-001, SET-001

## ADR-019: AdminLTE as replaceable UI template

**Status**: Accepted
**Context**: The Base Project needs an initial admin UI for the developer
experience. A ready-made admin template avoids reinventing common UI
components (dashboard, forms, tables, navigation). However, ADR-002
(UI-independent core) requires the UI layer to be replaceable without
affecting application architecture.
**Decision**: AdminLTE 4 is used as the **initial/default UI template**,
vendored from the official GitHub release ZIP into `public/vendor/adminlte/`.
It is NOT installed via npm. AdminLTE is a presentation-layer concern only —
not a dependency of business logic, services, models, policies, or
authorization. The UI layer (Blade + AdminLTE) is isolated behind layout
templates; swapping to Vue/React/mobile only changes the UI layer.
**Consequences**: AdminLTE assets live in `public/vendor/adminlte/` and are
isolated from application-specific assets in `public/assets/`. No npm/Vite
dependency for AdminLTE is introduced. The exact version is recorded in
`docs/base/ui/ui-adminlte-setup.md`. AdminLTE is not added to `package.json`.
**Related**: ADR-002, UI-001

## ADR-020: Telescope stays installed on production, gated off by default

**Status**: **SUPERSEDED** 2026-10-02 — the subject no longer exists. Replaced
by the Pulse gating described in
[implementation-roadmap.md](implementation-roadmap.md) § Phase 11 and
[DEP-004](../base/architecture/decision-records/DEP-004-laravel-pulse-observability.md).
Kept as the record of why the prod-gating problem was taken seriously: the
"forgotten `.env` key records every request" failure mode is exactly what the
`pulse` feature flag now prevents, by putting the toggle in a database-backed
store instead of an env var.

**Context**: ADR-008 classifies Telescope as a technical debugging tool, not an
audit or monitoring mechanism. The original intent was fast slow-query
diagnosis on the production VM, but `config/telescope.php` ships
`'enabled' => env('TELESCOPE_ENABLED', true)`, so an unset `.env` key leaves it
recording every request. With `'driver' => 'database'` each request writes a row
to `telescope_entries`, and nothing in the shipped config prunes that table —
so a forgotten toggle degrades the server and grows storage without bound.
Moving Telescope to `require-dev` does not solve it on its own: periscope
hard-requires telescope, so composer keeps Telescope in the production set and
`composer install --no-dev` still installs it.

**Decision**: Keep telescope and periscope in `require` and make the toggle
explicit and safe-by-default.

1. `'enabled'` default becomes `false`, not `true`.
2. `RequestWatcher` off — it persists request bodies (passwords, tokens) to
   `telescope_entries` unencrypted.
3. `ModelWatcher` off — the most expensive watcher, serialises every model.
4. `QueryWatcher` on — the actual requirement behind this decision.
5. Slow-query diagnosis does not depend on Telescope. Production uses the MySQL
   slow log plus a `DB::listen()` threshold logger; the ordered ladder is in
   `phase-11-monitoring-observability.md` §7.

Disabling costs nothing at runtime: `TelescopeServiceProvider::boot()` returns
before `Telescope::start()` when `telescope.enabled` is false, so no watcher is
registered.

**Consequences**: Toggling requires three steps, not one — `config:clear`,
`config:cache`, and an fpm reload — because cached config freezes `env()` and
opcache otherwise keeps serving the previous value. `php artisan
telescope:prune` is mandatory after a debugging session. `.env` is excluded from
the rsync sync, so the toggle survives deploys; that is intentional, and is why
the code default must be `false` rather than relying on `.env` alone. A VM that
forgets the key records nothing, which is the intended failure mode.

**Related**: ADR-008, ADR-013, Phase 11, MONITOR-001

## ADR-021: no automated database rollback — a bad migration is fixed forward

**Status**: Accepted

**Context**: `bin/deploy-rollback.sh` restores the previous release's code when
the health check fails, and the deploy takes a `mysqldump` before running
`migrate --force`. What it deliberately does not do is revert the database.

The tempting version runs `migrate:rollback` when the health check turns red.
That is unsafe here for two reasons:

1. The health check only curls `/up` and `/vendor/theme.css`. Neither touches
   the schema. A red check is just as likely to be a missing vendored asset or
   a worker that never reached `RUNNING` as it is a bad migration, so the
   rollback would drop columns that were never the problem.
2. `migrate --force` applies every pending migration in one batch. A rollback
   reverses the whole batch, including migrations that were correct, and
   discards whatever rows arrived between the deploy and the detection.

**Decision**: No `migrate:rollback` in the deploy path. A bad migration is
repaired with a **new forward migration** on the next push.

1. Every migration ships a real `down()` regardless. It keeps `migrate:fresh`
   and local seeding working, and it leaves the option open.
2. The `mysqldump` taken before migrating is the recovery path, and it is
   restored by hand, never by the pipeline. An automated dump restore would
   silently discard live writes.
3. Forward-fixing is also the shorter path in practice. A drop that has already
   run needs a re-create plus a data backfill, which is one file either way —
   and the forward version keeps the write history that arrived after the bad
   release shipped.
4. `bin/deploy-rollback.sh restore` stays code-only. It leaves the schema in
   whatever state the failed deploy produced, so a mismatched release is a
   known and deliberate outcome, not a surprise.

**Consequences**: After a rollback the VM serves the previous release's code
against the newer schema. That is acceptable here because migrations are
additive — old code ignores columns it does not know about. A migration that
renames or drops something the previous release still uses would break this
assumption, and it needs a staged rollout instead of a rollback. The cost is
that a genuinely destructive migration cannot be undone quickly; the dump is
the only route, and it costs a maintenance window.

**Related**: ADR-019, `bin/deploy-rollback.sh`, `.github/workflows/ci-cd.yml`

## ADR-022: Vantage queue monitor, gated like every other module (supersedes D-1)

**Status**: Accepted 2026-10-16 — supersedes the "recommendation stands: not
yet" deferral recorded as D-1 in
[phase-11-monitoring-observability.md](phase-11-monitoring-observability.md) §6.

**Context**: Phase 11 named `storviaio/vantage` as its second dashboard but
deferred the install (D-1), on four findings read from the package *source*: its
`viewVantage` gate admits any authenticated user, `store_payload` defaults to
`true`, its config has no `middleware` key and its provider calls
`loadRoutesFrom()` bare (so there is no hook for `feature:vantage`), and it is a
single-maintainer package that has already changed owners. The owner revisited
the deferral on 2026-10-16 and chose to install it, because the two gaps it
closes are real: Pulse's queues card is a throughput graph, not a live backlog
count, and `failed_jobs` keeps one row per job, so the retry chain is lost — the
stack trace that survives is the one that did not explain the first failure.

**Decision**: Install `storviaio/vantage ^0.1.25` and impose this project's
standard on it rather than accept its defaults.

1. **The app declares the routes, not the package.** `config/vantage.php` sets
   `routes => false`; `routes/web.php` declares the six web routes so they carry
   `feature:vantage` and `can:queues.*` — the same two gates `/pulse` and
   `/health` have. The vendor group cannot be given middleware, which is exactly
   what D-2 recorded as the reason Pennant "does not fit"; declaring the routes
   removes the objection. Route names stay the vendor's (`vantage.*`) because its
   Blade views build every link from them.
2. **`queues.view` + `queues.retry` in `PermissionCatalog`.** Reading the queue
   and re-queueing a job are different acts, on the `audit.view`/`audit.export`
   rule; `queues.retry` guards the one mutating route.
3. **`viewVantage` gate overridden** in `AuthServiceProvider` to `queues.view`,
   neutralising the package default ("any authenticated user"). Our routes use
   `can:` and do not consult it — it is the safety net for the package's own
   `AuthorizeVantage` middleware if vendor routes were ever re-enabled.
4. **`store_payload => false`.** The package default (`true`) persists full job
   payloads; redaction covers named keys, not the export filters, model ids and
   mail arguments this project's jobs carry.
5. **`vantage:prune` on the scheduler**, daily, `--force --keep-processing`,
   reading `vantage.retention_days`. `vantage_jobs` is otherwise unswept.
6. **The JSON API stays off** (`vantage.api.enabled` keeps the package default
   `false`) — the one-sidedness `ChannelParityTest` records for Pulse and health.

7. **Retry answers from the framework's failed-job payload, not a Vantage one.**
   With `store_payload => false` the vendor's retry could never succeed: it
   re-dispatches the job from `vantage_jobs.payload`, which is always null here,
   so every press answered "Payload might be missing or corrupted".
   `AppServiceProvider::register()` binds
   `Storvia\Vantage\Support\JobRestorer` to
   `App\Support\Vantage\FailedJobPayloadRestorer`, which falls back to
   `queue.failer` — the `failed_jobs` row `queue:retry` reads, written by the
   worker on the same final attempt Vantage marks `failed` — keyed on the
   payload uuid both stores already share, and hands it to the package's own
   restore path so its `class_exists`/`allowed_classes`/`instanceof` checks
   still apply.

`VANTAGE_ENABLED` stays the deploy-time switch (like `PULSE_ENABLED`); the new
`vantage` Pennant flag is the runtime kill switch.

**Consequences**: A fourth source of truth for job data beside Pulse,
`failed_jobs` and the log, plus a `vantage_jobs` / `vantage_job_tags` pair in the
base schema and a nightly prune to own. The declared routes can drift on a vendor
upgrade (a new vendor route would be invisible) — accepted, and the reason the
route names are pinned to the vendor's. Item 7 makes retry the one path that
depends on two stores agreeing: `failed_jobs` keeps the payload, `vantage_jobs`
keeps the attempt, and only their overlap is retryable — an operator who flushes
the failed jobs (or `queue:prune-failed`) loses retries for rows Vantage still
shows for `vantage.retention_days` (14), which the restorer logs rather than
dispatches wrongly. Accepted, because the alternative is the payload column this
ADR keeps empty. The one follow-up this ADR left open — the vendor layout's two
third-party CDN scripts and nonce-less inline script under the nonce-only CSP —
was **closed 2026-10-17**: `AddSecurityHeaders::policy()` is scoped to
`vantage.*` as it already is to `pulse`, so those two CDNs and `'unsafe-eval'`
travel only with the Vantage routes, and the four script-bearing vendor views
are overridden under `resources/views/vendor/vantage/` to nonce them. Widening
`script-src` to two third-party CDNs stayed a route-name-bound decision rather
than an application-wide one; pinned by `ContentSecurityPolicyTest`.

**Related**: ADR-008, DEP-004, D-1/D-2 (superseded), P11-B1..B4,
`docs/base/features/monitoring.md`, `tests/Feature/Vantage/VantagePermissionGateTest.php`
