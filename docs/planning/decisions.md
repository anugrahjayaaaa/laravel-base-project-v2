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

## ADR-023: the ungated health endpoint gets a ceiling and a cached check set

**Status**: Accepted
**Date**: 2026-10-17
**Context**: `GET /api/v1/health` is the one endpoint in the app with no gate of
any kind — no auth, no permission, no feature flag — decided deliberately in
Phase 11 (`F3`) because its caller is a monitor that cannot log in. A read-only
pass over Phase 11 measured what that decision left uncapped. The route sits in
the `api` group, whose middleware is `[SubstituteBindings]`, so nothing bounded
the request count: an anonymous caller could buy an unbounded number of real
round trips. One uncached run is a PDO connect, a cache write **and** a read
back, a queue `size()` and three storage operations — measured at 0 queries on
the harness (`array` cache, `sync` queue) and at 3 queries plus 1 write on
`database`/`database`, i.e. a real cost that scales with whoever is calling.

**Decision**:

1. **The gate stays off.** The endpoint keeps no authentication, no permission
   and no feature flag. This ADR does not revisit `F3`; it addresses the two
   things `F3` did not decide — how much work one request costs, and how many
   requests one caller may make.
2. **The check set is cached for 10 seconds** in `health_check:results`
   (`HealthCheckService::check()`). It is deliberately short: a third of the
   shortest interval a legitimate caller polls at, so the status served is never
   one the caller would have measured differently. What it buys is a ceiling of
   six executions a minute per server however many callers arrive. The payload
   is `array<string,string>` only — primitives, because the Redis store returns
   `__PHP_Incomplete_Class` for anything richer.
3. **A store that cannot answer is reported, never thrown.** `Cache::remember`
   cannot work without the cache store, and the cache store is one of the things
   being checked. When it fails, `check()` logs
   `health_check.results_cache.failed` and runs the checks uncached, so the one
   endpoint you reach when Redis is down answers `cache: fail` instead of 500 —
   it must not hide the failure it exists to name. **Amended by ADR-024 (clause
   6):** the fallback asks the `file` store before giving up, and `check()` reads
   before it computes, because `remember()` runs the callback before attempting a
   store it cannot write to — which made the ceiling disappear whenever the cache
   needed reporting.
4. **A rate ceiling, keyed on IP**: `RateLimiter::for('health')`,
   `throttle:health` on the route, number from
   `health_rate_limit_per_minute` (default **120**/min, validated 1..600).
   The key unit is an IP because there is no user to key on, and 120 rather than
   the 3-15 the per-user limiters use because that unit is weak: one address can
   be a carrier's NAT or a corporate egress shared by thousands of people. With
   the cached check set, an allowed request is roughly one cache read, so the
   ceiling keeps the count finite rather than metering traffic. Operator-tunable
   for the reason every other limiter is: the person who discovers the shared
   address must be able to raise it without a deploy.
5. **The refusal is JSON unconditionally** (`responseFor('health', jsonOnly:
   true)`). This route has no web branch, so `expectsJson()` is not a question
   about the caller's preference — and a monitor that sends no `Accept` header
   would have been handed the `back()` redirect, which reads as a 200 login page
   to anything that follows it, i.e. as UP.

**Consequences**: The response `timestamp` is the response time while the checks
behind it are up to 10 seconds old — `status` and `checks` stay consistent with
each other because both come from the same cached set, so a monitor is never
told two different things. The gated web dashboard (`/health`) calls the same
service and gets the same cache. The limiter reads `SystemSetting` on every
request, so a database that is down *and* a settings cache that is cold makes
the limiter itself 500 before the checks run — the same shape every other
limiter in the app already has, and still a non-2xx for the monitor.

**Related**: ADR-008, ADR-017, ADR-019, F3 (`docs/planning/phase-11-monitoring-observability.md`),
`docs/base/security/rate-limiting.md`, `HealthCheckEndpointTest`,
`ThrottleJsonResponseTest`

## ADR-024: the flag reaches the writes, and the permission follows the flag

**Status**: Accepted
**Date**: 2026-10-17
**Context**: A security pass over the Phase 11 surface (Pulse, Vantage, the health
endpoint, the queue conventions) found four defects, all of them one shape: a
control that exists at the edge of a module and not inside it.

1. **The flags gated the dashboards, not the recording.** `pulse` and `vantage`
   were enforced by route middleware, so switching Pulse off during an incident
   stopped the page and kept the writer. Both packages decide whether to record
   once, at their own boot, from an env master switch (`PULSE_ENABLED`,
   `VANTAGE_ENABLED`); `viewPulse`/`viewVantage` were route details rather than
   module switches, and the vendor default for `viewVantage` admits **any
   authenticated user**.
2. **The flag had no authority over the permission.** A role that already held
   `pulse.view` kept it while the module was off, and the role matrix kept
   offering it: the grant outlived the flag with nobody able to see it to remove
   it. The permission index advertised it too (`PermissionCatalog` was built
   before any flag needed an opinion).
3. **The health endpoint's cache fallback ran the checks uncached.** ADR-023
   clause 3 answered a store that cannot answer by running all seven round trips
   per request — the amortisation it exists to provide disappeared in exactly the
   situation that makes the endpoint matter, on the one route in the app with no
   gate in front of it.
4. **`queues.retry` answered "may this person" and not "how often".** It is the
   only mutating control on the queue dashboard, and a permission with no ceiling
   lets a compromised operator account re-dispatch work as fast as it can click.

**Decision**:

1. **The flag stops the writes, at call time.** `Pulse::filter(fn () =>
   FeatureCatalog::isActive('pulse'))` in `AppServiceProvider::boot()` — the
   package's supported extension point, consulted at INGEST, so it reaches the
   worker as well as the request and costs no store read on a request that writes
   nothing. Vantage has no such hook, so an `Event::listen(Looping::class, ...)`
   listener refreshes `config('vantage.enabled')` — the key its recorders re-read
   inside `handle()` — once per daemon iteration. Not `JobProcessing`: the
   package listens on that event and registered first (packages boot before app
   providers), so an app listener on the same event is one job behind. The env
   master switches (`PULSE_ENABLED`, `VANTAGE_ENABLED`) stay, as deploy-time
   kills; nothing is read at boot from the flag store, because boot runs before
   the `features` table exists on a fresh install.
2. **The gates are `flag AND permission`.** `viewPulse` and `viewVantage` answer
   `FeatureCatalog::isActive(...) && $user->can(...)`. Two readers of one question
   must not be able to disagree, and the vendor's `Authorize` middleware (were
   the routes ever re-enabled) would otherwise read an unflagged gate. Note the
   limit: `Gate::before` short-circuits before any gate callback, so superadmin's
   bypass is untouched by design — the flag stops them at
   `EnsureFeatureIsEnabled`, which consults no actor (D-3).
3. **A switched-off module is revoked, hidden, and cannot be re-granted.**
   `PermissionCatalog::FEATURE_BY_RESOURCE` maps a resource prefix to its flag
   (one place answers "whose permission is this"); `FeatureToggleAction` and
   `FeatureBulkToggleAction` call `FeatureRevokePermissionsAction` inside their
   transaction, which deletes both pivots for every held permission in two
   statements whatever the selection — flat in the size of the batch, as
   `FeatureFlagPerformanceTest` requires — and records the names in the audit
   row's `revoked` property. The role matrix and the permission index filter by
   `PermissionCatalog::visibleNames()`.
4. **The revoke is final.** Switching the flag back on re-grants nothing:
   restoring would need the previous holdings stored somewhere, and it would
   silently undo the state the operator asked for. Re-granting is a deliberate act
   through the role matrix.
5. **A hidden grant is preserved, and a crafted one is refused.**
   `PersistsRole` assigns the intersection of what was posted with what the
   matrix can offer, and keeps grants it cannot render. Hiding alone would be a
   data-loss bug (a config-level kill switch leaves the grant in place with no
   checkbox to keep it), and not filtering would leave the write path open to a
   request that names a hidden permission id.
6. **The health endpoint reads before it computes.** `check()` reads the
   configured store, then the `file` store, and only then runs the probes; the
   write is best effort to both. This amends ADR-023 clause 3: `Cache::remember()`
   runs the callback before attempting the store, so a store that cannot be
   written ran the whole probe set on every request. Reading first means a store
   that cannot answer costs a read and nothing else. The residual is unchanged
   and now pinned: with both stores down, the checks run per request.
7. **`queues.retry` gets a ceiling** — `RateLimiter::for('queue-retry')`,
   `throttle:queue-retry` on the retry route, `SystemSetting`
   `queue_retry_rate_limit_per_minute` (default **10**/min, validated `1..120`),
   keyed on user id + IP like `audit-export`. Its over-limit response is a
   redirect with a session `error` flash rather than a field error: the queue
   monitor is a vendor layout that renders `session('error')` and has no
   `$errors` bag, so the standard shape would reload the page saying nothing.

**Consequences**: One map (`FEATURE_BY_RESOURCE`) is now the single answer to
"which flag owns this permission", pinned by `PermissionFeatureMapTest` — every
resource is mapped or on `UNMAPPED_RESOURCES`, every mapped flag is declared, and
the sidebar's own `feature` keys must agree with it. `features` is deliberately
unmapped: the page that re-enables a flag cannot be behind a flag.
`sessions` own no permissions. The revoke writes the pivots
directly rather than through `detach()`, so a soft-deleted role cannot come back
holding permissions for a module that was switched off while it was gone
(`config('permission.teams')` is false here; with teams on, a delete keyed on
`permission_id` alone would reach every team). A worker started before a flip
applies it from its next loop, and `queue:listen` / a synchronous connection
never fires `Looping` at all — those keep `VANTAGE_ENABLED` as their only switch.

**Related**: ADR-022, ADR-023 (clause 3 amended), P11-B4, Phase 11 §15,
`docs/base/features/monitoring.md`, `docs/base/security/rate-limiting.md`,
`docs/base/authorization.md`, `PermissionFeatureMapTest`,
`FeaturePermissionRevokeTest`, `PulseRecordingKillSwitchTest`,
`VantageRecordingKillSwitchTest`, `HealthCheckCacheFallbackTest`,
`QueueRetryThrottleTest`

## ADR-025: the API document is exported at deploy, not built per request

**Status**: Accepted
**Date**: 2026-10-21
**Context**: `/docs/api.json` was measured at ~700 ms per request on the 47-path
document. Scramble ships its own answer — `scramble:cache` — and it measured at
**no benefit**: 703/736/707 ms uncached against 738/747/724 ms cached, three
requests each in one process, cache state verified through the configured store.
What that command caches is a `GeneratorResult` (the route/FormRequest
analysis); the request cost is `spec()` serialising 381 KB of OpenAPI array from
it — which runs every time either way.

The second half of the problem was availability. Scramble was `require-dev`
(DEP-007), so `composer install --no-dev` removed the package and both docs
routes with it — while `api_docs_public` and the `api_docs.view` permission kept
promising a surface an operator could switch on. On the deployment that
mattered, the setting did nothing.

**Decision**: Promote `dedoc/scramble` to `require`, and serve the document from
a file produced once per release.

- The deploy runs `php artisan scramble:export --path="storage/app/openapi.<APP_ENV>.json"`
  after the config and route caches. A failed export fails the deploy.
- `App\Support\OpenApi\ExportedDocument` decorates Scramble's
  `CacheableGenerator` (bound in `AppServiceProvider::register()`) and answers
  both routes — `/docs/api.json` and the UI's embedded `spec` — from that file.
  A missing, unreadable or invalid file falls back to live generation.
- `bin/deploy-rollback.sh` deletes the file after restoring: a spec for the
  failed release outliving it would document routes that no longer exist.
- The file name carries `APP_ENV`, so an export made in development can never be
  served here or read by the test suite.
- The gate is unchanged: `api_docs_public` (off by default) or `api_docs.view`.

**Alternatives considered**: `scramble:cache` at deploy — rejected on the
measurement above. A spec committed to the repository, or built in CI and
shipped as an artifact — rejected: it makes the spec a second source of truth
that can disagree with the release, and the deployed export is the same idea
without the commit. A custom documentation generator — rejected in DEP-007.

**Consequences**: Measured after the change, same probe: **11.0 / 15.7 ms per
request** from the file against **751 / 807 ms** live (first call in a fresh
process 118 ms against 6.6 s, which is autoload, not this change). Production
now installs a documentation package; it does no application work — `app/`
touches Scramble only in the binding and the docs gate. The settings surface
finally matches what it advertises. `response()->json()` re-encodes the decoded
array, so key order follows the file and an empty `paths` object would serialise
as `[]`; the generated document never has empty `paths`, and the fallback covers
a broken file.

**Related**: DEP-007, P12-A1, D-4, Phase 12,
`docs/base/api/documentation.md` § *Availability and access*,
`.github/workflows/ci-cd.yml`, `bin/deploy-rollback.sh`, `OpenApiExportTest`,
`ApiDocsUiRenderTest`

## ADR-026: backups use spatie/laravel-backup, queued, on a dedicated disk

**Status**: Accepted
**Date**: 2026-10-30
**Context**: Phase 14 Group B needs a real backup of MySQL and `storage/app`,
not a hand-rolled dumper. This change adds `spatie/laravel-backup` (`^10.3`) to
`composer.json` — the package was **not** a dependency before Phase 14 — and its
config is a full `backup.php` file. Two properties drive the design: the package
reads its config through a `Config` object built when a command is constructed,
and its default source is the whole project while its default destination is the
`local` disk — the disk it would be backing up.

**Decision**:

- **Source**: MySQL (the configured connection) plus `storage/app`, excluding
  `storage/app/backups` and `storage/app/backup-temp` — an archive that
  contains its own predecessors grows without bound.
- **Destination**: the dedicated `backups` disk (`storage/app/backups`). Never
  the default/local disk.
- **Execution**: `ExecuteSystemBackupJob` on the `backups` queue **and the
  `backups` connection**. The connection exists because a backup runs for
  minutes while every other job finishes in seconds: both queue drivers
  re-select a job whose reservation is older than `retry_after`, and the retry
  window is read from the consuming connection. On the shared connection a
  second worker would re-reserve the row mid-run, increment `attempts` and —
  because the job declares `$tries = 1` — fail a backup that is still writing.
  `bin/run-workers.sh backups` is the worker that consumes it
  (`BACKUP_QUEUE_RETRY_AFTER` 2000 > `$timeout` 1800). The scheduler and the
  `POST /storage/backup` trigger both dispatch it; no synchronous backup runs
  inside a request.
- **Schedule**: `routes/console.php` ticks every minute and asks
  `SystemBackupSchedule`, which reads `backup_enabled` and `backup_schedule`
  at run time. It does **not** register `backup:run` from a config value:
  the package would freeze that value at build time.
- **Retention**: `backup_retention_daily|weekly|monthly` (defaults 7/4/12) are
  applied by `SystemBackupRunner::applyRetention()` immediately before the
  command runs, then the package's scoped `Config` instance is forgotten so the
  next command rebuilds it. Same reason: the object is scoped, so a second job
  in one worker would otherwise keep the first run's windows.
- **Pre-check**: the raw source estimate (files plus MySQL
  `data_length + index_length`) must fit in the destination's free space with a
  10% reserve. Compression only shrinks the real artifact, so the estimate is
  an upper bound: it may refuse a backup that would have fit, never start one
  that would not.
- **Notifications**: the package's own channels stay disabled
  (`backup.notifications.notifications = []`). Outcomes go through
  `NotificationAdminEventAction` to holders of `backup.execute`, the people who
  can act on them.
- **Encryption**: production runs refuse to start without
  `BACKUP_ARCHIVE_PASSWORD` (`SystemBackupRunner::run()`), `verify_backup` is
  on so a corrupt archive fails the run, and a failed run deletes
  `backup-temp`, where the dump is written in plaintext.

**Alternatives considered**: `mysqldump` + `tar` in a custom command —
rejected: the package already models sources, destinations, retention and
verification, and a custom implementation would re-derive all four.
Configuring `Schedule::command('backup:run')` from a boot-time setting —
rejected: the scheduler process keeps the value it registered, so changing the
time would need a restart, exactly what the phase forbids. Registering the
package's mail notifier — rejected: it mails a hardcoded config address, not
the operators holding the permission.

**Consequences**: The package is a new production dependency, recorded here and
in `docs/planning/changelog.md`. The pre-check and retention are exercised by
`BackupStrategyTest` with the runner mocked, so the suite never shells out to
`mysqldump`. A backup is one attempt (`$tries = 1`) — a retry after a partial
artifact is an operator decision, not a queue default — which is why the job
must run on a connection whose `retry_after` exceeds its own timeout. The
`backups` disk sits inside `storage/app`, so the source set must keep excluding
it; that exclusion is asserted by the estimator's tests rather than assumed.
The disk shares the application's physical volume: a host or volume loss takes
the database and its backups together, so an off-host copy stays an operator
responsibility (`docs/base/infrastructure/backup-disaster-recovery.md`). A
production install that never set the archive password stops taking backups —
loudly, through `backup.failed` — rather than writing readable artifacts with
the database and `secure_documents` inside.

**Related**: P14-B1..B3, `docs/base/infrastructure/backup-disaster-recovery.md`,
`docs/base/infrastructure/queue.md`,
`docs/base/infrastructure/storage.md`, `docs/base/operations/retention.md`,
`tests/Feature/Storage/BackupStrategyTest.php`

## ADR-027: one global timezone, applied to the process at boot

**Status**: Accepted
**Date**: 2026-10-31
**Context**: Phase 14 found the timezone fragmented. `password_security_sweep_timezone`
scoped only the password/inactivity sweep, `backup_schedule` was compared against
`now()` locked to `config('app.timezone') = 'UTC'`, and everything else — audit
trail, health/meta payloads, API resource timestamps, settings page clocks —
rendered in the process zone. An operator could set a sweep time in Jakarta and
watch a backup fire at the UTC equivalent. Three surfaces pretending to know the
time, and only one of them configurable.

**Decision**:

- **One setting**: `app_timezone` (empty string = application default). The
  legacy `password_security_sweep_timezone` row is migrated and deleted by
  `2026_10_30_000000_move_sweep_timezone_to_app_timezone.php`.
- **Applied, not displayed**: `AppServiceProvider::bindTimezone()` reads the
  setting and calls `date_default_timezone_set()` plus
  `config(['app.timezone' => …])`. It runs at the end of `boot()` and again
  after a settings save, so an admin sees the change on the next page load
  instead of the next deploy.
- **The database agrees with it**: `applySessionTimezone()` sets
  `SET time_zone = <offset>` on every MySQL connection (on
  `ConnectionEstablished`, and again in `bindTimezone()` for connections
  already open), so `CURRENT_TIMESTAMP` defaults and `TIMESTAMP` conversion read
  the same wall clock the UI renders. It cannot be a `config/database.php` value
  — the zone lives in the database it configures.
- **The shipped base zone is captured once** in a static before any mutation.
  Reading `config('app.timezone')` as the fallback would, in a process that had
  already applied a zone, treat the previously applied zone as the default and
  drift further on every boot.
- **Try/catch around the read**: `boot()` also runs during `migrate` and
  `config:cache`, before `system_settings` exists. An unreadable setting
  applies the base zone explicitly rather than leaving whatever the previous
  process set.
- **Validation**: `nullable|string|timezone` plus an `exists` on active
  `timezones` rows — the same rule the dropdown offers.

**Alternatives considered**: display-only formatting per resource — rejected:
every new timestamp surface would have to remember the setting, and the
scheduler would still compare UTC clock time to a wall-clock setting. Keeping
the per-sweep timezone — rejected: two zones in one application means a backup
and a password sweep can be scheduled at the same "time" and fire hours apart.

**Consequences**:

- `now()`, Eloquent date parsing, `toIso8601String()`, Blade `->format()`, the
  health/meta API, the audit trail, and the CSV export all follow the zone with
  no per-surface code.
- **Existing rows are not rewritten.** A row written under UTC keeps its UTC
  digits; after the switch it reads shifted by the offset. `timezone:rebase`
  converts them once, and the documented cutover order is: enter maintenance →
  rebase (setting still old) → save the new `app_timezone` → resume. Rows
  written after the switch must not be rebased again.
- **Long-lived processes keep the old zone** until restarted: `queue:work` and
  `schedule:work` need `queue:restart` / a supervisor restart. `schedule:run`
  from cron bootstraps per tick and is safe.
- **`failed_jobs.failed_at`** is written by MySQL `useCurrent()`, i.e. the
  database session zone — which now follows this setting too (below), so it is
  the same wall clock the UI shows.
- **DST**: the sweep compares `H:i` every minute, so a transition that repeats
  or skips an hour can run the sweep twice or not at all that day. Zones
  without DST are unaffected; the password sweeps are idempotent, so a repeat
  costs a query, not correctness.
- **The database session zone follows the setting.** `applySessionTimezone()`
  runs on `ConnectionEstablished` and again in `bindTimezone()` for connections
  already open, sending `SET time_zone = <offset>`. It cannot live in
  `config/database.php`: the zone is a system setting, stored in the database
  itself. This is deliberate — the point is that a row inspected with a SQL
  client reads in the same wall clock the application renders, instead of
  requiring the reader to convert from UTC. It is not a per-user preference and
  not something a normal user can change; `app_timezone` is set once at
  installation by someone holding `settings.manage`.
  Sent as an offset (`+07:00`) rather than a zone name so it does not depend on
  the server's `mysql.time_zone*` tables being populated. Application digits are
  unaffected — they are sent as wall-clock strings — but a host that changes the
  setting will read its **existing** `TIMESTAMP` rows shifted by the difference
  once, because MySQL converts them. `DATETIME` columns are not converted and do
  not shift; `timezone:rebase` is the conversion path.
- **`config:cache` still contains `UTC`**; the runtime value comes from the
  provider, never from the cached file. Clearing the cache does not reset the
  applied zone.

**Related**: `docs/base/features/settings.md` § *System timezone*,
`docs/base/api/response-contract.md`, `docs/base/infrastructure/queue.md`,
`docs/base/security/password-security.md`,
`tests/Feature/Settings/GlobalTimezoneTest.php`,
`tests/Feature/Console/RebaseTimezonesCommandTest.php`

## ADR-028: the S3 driver ships with its adapter installed

**Status**: Accepted
**Date**: 2026-11-03
**Context**: Phase 14 Group A shipped S3 as a storage driver — the `s3` disk in
`config/filesystems.php`, the encrypted `storage_s3_*` settings, the capacity
probe's S3 branch, and a driver fieldset in the settings UI — but
`league/flysystem-aws-s3-v3` was never added to `composer.json`.
`laravel/framework` only *suggests* it, so the gap is invisible until someone
picks S3: `Storage::disk('s3')` then throws
`Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found` and
every storage operation fails. The docs advertised S3-ready the whole time.

**Decision**: require `league/flysystem-aws-s3-v3` (`^3.0`, resolved 3.35.3
against the installed `league/flysystem` 3.36.0). It pulls
`aws/aws-sdk-php` transitively; the adapter version tracks the Flysystem major,
so it moves with the framework rather than with our code.

**Alternatives considered**: removing the S3 option until someone needs it —
rejected: the disk, the encrypted settings, the guard branch, the UI and their
tests already exist, so removal is a deletion of a Group A deliverable rather
than a smaller change, and the base project is meant to be usable against object
storage without edits. Leaving the option and failing with a clear message —
rejected: still no capability, just a nicer error. Environment-only S3 without
the settings card — rejected: the driver is a runtime setting by design
(ADR-027's reasoning applies to the disk too).

**Consequences**:

- **`composer.lock` grows** by the AWS SDK. It is a production dependency now,
  so `composer install --no-dev` on deploy must be allowed to fetch it and
  `composer audit` will cover it from here on.
- **No behaviour change for local installs.** Building the adapter makes no
  network call; only operations against the `s3` disk do, and only when the
  admin has selected that driver.
- **Credentials still come from `storage_s3_key` / `storage_s3_secret`**
  (encrypted, never rendered) with `AWS_*` env as the fallback the binder
  already reads.
- The `storage_management` flag still gates the management surface only; the
  disk binding and the capacity guard behave as before.

**Related**: P14-A1..A5, `docs/base/infrastructure/storage.md`,
`config/filesystems.php`, `app/Providers/AppServiceProvider.php`
(`bindStorageConfig`), `app/Services/Storage/StorageCapacityGuard.php`,
`tests/Feature/Storage/StorageDiskTest.php`

## ADR-029: the hot paths measured in Phase 14 get the cheaper shape

**Status**: Accepted
**Date**: 2026-11-03
**Context**: A Phase 14 performance pass over the storage and settings paths
found four costs that the feature itself did not need: the capacity gauge walked
the whole disk root through Flysystem on every call (one object and one extra
`stat()` per file, and it is called by `/api/v1/health` on every monitor poll),
a settings save wrote all ~40 keys whether or not their values changed
(70 queries per save, 67 of them SELECTs from `updateOrCreate`), the timezone
rebase committed one row at a time, and the `tmp` cleanup asked the disk API
three times per file for three answers the filesystem already had.

**Decision**:

- **`StorageCapacityGuard` has two entry points.** `metrics()` measures now and
  is the only one the guard may use; `cachedMetrics()` caches the walk for 60 s
  per disk root and is for display (`/settings`, `/health`,
  `HealthCheckService::checkStorage()`). The quota is read on every call, so
  lowering it shows on the next poll. `storedBytes()` walks the root with a raw
  `RecursiveIteratorIterator` instead of `Storage::allFiles()`.
- **A settings save writes only what differs.** One cached `getAll()` snapshot,
  then `array_filter` against it; a resubmitted secret is compared as plaintext
  and its ciphertext is only re-encrypted when the plaintext changed. The
  storage audit row is now derived from the filtered list, so it names exactly
  the keys that were written.
- **`timezone:rebase` runs one transaction per chunk.** Not one per row (the
  measured cost) and not one for the whole table: the command runs against a
  live database, where a long lock is the bigger risk. Dry-run opens none.
- **`CleanTemporaryStorageJob` reads metadata from the filesystem.** `tmp` is
  always a local disk, so one iterator yields mtime and size without the two
  extra adapter calls per file; the delete still goes through the disk API.

**Measured** (same machine, test harness — SQLite `:memory:`, so the times are
indicative and the query count is not):

| Path | Before | After |
| --- | --- | --- |
| `metrics()` over 1 000 files | 25.5 ms | 3.1 ms |
| `assertCanStore()` over 1 000 files | 30.3 ms | 10.4 ms |
| Settings save (full form) | 62.6 ms / 70 queries | 10.7 ms / 4 queries |
| Settings page render (1 000 files) | 87.4 ms | 45.2 ms |
| `tmp` cleanup over 1 000 files | 491 ms | 154 ms |
| `timezone:rebase`, 1 128 rows | 1 546 ms | 854 ms |

**Alternatives considered**: caching used bytes for the guard too — rejected:
the 95 % refusal is the one number that must be fresh, and a minute of lag is
exactly how an upload slips past a full disk. An upsert for the settings save —
rejected: the cost was the per-key `SELECT` from `updateOrCreate`, not the
round-trip count, so an upsert would still write every row and still bust the
cache for values that did not change. A batched `DATE_ADD`/`CASE` rebase —
rejected: dialect-specific SQL for a one-off command, against a per-row loop
that stays correct per row across a DST boundary. `Storage::allFiles()` for the
cleanup — rejected: it is the slower path, and the measured delta is in the
iterator, not in the delete.

**Consequences**:

- **The display gauge can lag by up to a minute.** An upload landing right after
  a poll does not move the bar until the next poll after the TTL. The guard is
  unaffected.
- **A save that changes nothing writes nothing** — including no cache bust. The
  values are unchanged, so a cached read is still correct; the audit row and the
  notification still fire, because those describe the save, not the rows.
- **A resubmitted S3 credential no longer rewrites its ciphertext.** Any reader
  that expected the ciphertext to change on every save was relying on the IV
  bug, not on a contract.
- **`timezone:rebase` holds a write lock for at most one chunk** (500 rows by
  default). A crash mid-table leaves the completed chunks converted and the
  rest not, which re-running from the same `--from`/`--to` completes.

**Related**: `app/Services/Storage/StorageCapacityGuard.php`,
`app/Actions/V1/System/SystemSettingsUpdateAction.php`,
`app/Services/Timezone/RebaseTimezones.php`,
`app/Jobs/CleanTemporaryStorageJob.php`,
`docs/base/infrastructure/storage.md`,
`tests/Feature/Storage/StorageDiskTest.php`,
`tests/Feature/Storage/StorageBenchmarkTest.php`

## ADR-030: PHPStan level 8 via Larastan, with the baseline as a debt ledger

**Status**: Accepted
**Date**: 2026-11-04
**Context**: Phase 15 Group D introduces the first static analyser the project
has ever run: `composer.json` carried Pint, PHPUnit, Mockery and Collision only,
and there is no `phpstan.neon`. The brief listed both PHPStan and Psalm under
"Pest/PHPStan/Psalm" and Enlightn beside them. The suite at 2644 tests proves
behaviour; it does not prove types, and level 8 is where "the parameter is
`string|null` and the caller never checked" becomes a compile-time-visible bug
rather than a runtime one on an untested branch.

**Decision**:

- **One analyser: PHPStan, level 8, via `larastan/larastan` as a dev
  dependency.** Larastan is the extension that gives PHPStan the Laravel-native
  reads — container resolution, Eloquent query-builder types, facade return
  types — without which level 8 on an application this size is noise, not
  signal. Analysis paths: `app/` only. `tests/` and `vendor/` are out of scope
  for the gate; test-code typing is not a production risk.
- **Psalm and Enlightn are declined** (recorded as deviation D-3 in
  `docs/planning/phase-15-comprehensive-testing.md`). Psalm has no first-class
  Laravel plugin and would be a second analyser over the same `app/` with a
  second baseline to keep honest — two ledgers, no owner. Enlightn's checks
  duplicate the in-house adversarial suite in `tests/Feature/Security` and add
  a dependency whose full value sits behind a Pro tier.
- **The committed baseline (`phpstan-baseline.neon`) is a debt ledger, not a
  mute button.** It exists because a first level-8 run over an unanalysed
  codebase reports 332 findings — overwhelmingly own-code type-precision debt
  (undocumented array shapes, stale `@return` docblocks, unresolvable-by-static
  Eloquent magic), not framework/vendor noise as the brief assumed. Fixing all
  of them inside Phase 15 would mean editing ~100 production files during a
  testing phase, which the phase's own Definition of Done forbids ("no
  production code changed except where a test exposed a defect"). The ledger
  records the state instead, and every entry must shrink, never grow. Its
  terms: `reportUnmatchedIgnoredErrors` is enabled, so the gate **fails** both
  on a new error and on an ignore that no longer matches — the ledger cannot
  silently grow, and it cannot silently stale. Every entry carries the message,
  the count, and a one-line reason comment beside it naming the debt class.
  Paying debt down means deleting entries in the same change that fixes them.

**Alternatives considered**: level 5 or 6 "to start" — rejected: the partial
levels miss exactly the nullable/mixed returns the suite cannot see, and
raising a level later is a new baseline generation over code that drifted.
Baseline-free immediate green by fixing every finding first — rejected: it is
Phase 15's job to prove coverage, not to rewrite `app/`, and a green gate that
took a month never ships. A per-directory roll-out (Actions first, controllers
later) — rejected: two policies in one config file is the stale-ignore problem
re-invented, and `app/` is one tree with one owner.

**Consequences**:

- `vendor/bin/phpstan analyse --memory-limit=1G` exits 0 is a Definition-of-Done
  line for every subsequent change touching `app/`.
- The first baseline may be large; its size is the measured debt, and
  `progress.md` records it so the number can only go down.
- CI placement is **P15-E3's decision** (`testing.yml` owns PHPStan per
  deviation D-7); this ADR only installs the runner and the `composer` script,
  so the gate is runnable locally and in `vendor/bin` form from day one.

**Related**: P15-D1, `docs/planning/phase-15-comprehensive-testing.md` § 6 and
§ 8 (D-3, D-7), `phpstan.neon`, `phpstan-baseline.neon`,
`docs/base/testing/testing-strategy.md`
