# Dependency Overview

> **Single source of truth** for Base Project dependencies.
> Phase 1 (Laravel Foundation + Environment) is complete — all Required and
> Planned dependencies are installed in `vendor/`. See the task tracker for
> remaining phases.

## Repository State

|| Artifact | Present? |
|-----------|----------|
|| `composer.json` | Yes |
|| `composer.lock` | Yes |
|| `app/` directory | Yes (base + Models/User) |
|| `config/` directory | Yes |

All dependency decisions in this document are derived from the architectural
documentation in `docs/base/` and `docs/planning/` (particularly the
Implementation Roadmap, Dependency Map, and ADRs). Phase 1 dependencies are
installed and verified against `composer.lock`.

## Dependency Inventory

|| Area | Implementation | Composer Package | Status | Priority | Direct App Usage |
||------|----------------|------------------|--------|----------|------------------|
|| Framework | Laravel | `laravel/laravel` (app) + `laravel/framework` (core) | Required (core) | P0 | Yes |
|| API Auth | Sanctum | `laravel/sanctum` | Required | P0 | Through Auth layer |
|| RBAC | Spatie Permission | `spatie/laravel-permission` | Required | P0 | Through Authorization layer |
|| Audit Trail | Spatie Activitylog | `spatie/laravel-activitylog` | Required | P0 | Prefer Audit abstraction |
|| Technical Observability | Telescope + Periscope | `laravel/telescope` + `seanbarton/laravel-periscope` | Required | P1 | Restricted (technical users) |
|| Feature Flags | Laravel Pennant | `laravel/pennant` | Required | P1 | Through Feature facade |
|| API Documentation | Scramble | `dedoc/scramble` | Planned | P1 | Documentation only |
|| Queue (default backend) | Laravel Queue | Native | Core | P0 | Through Queue facade |
|| Cache (default backend) | Laravel Cache | Native | Core | P0 | Through Cache facade |
|| Redis (production option) | Redis backend | `predis/predis` or `phpredis` | Optional / Deployment | P2 | Never directly |
|| Backup | Spatie Backup | `spatie/laravel-backup` | Planned | P2 | Infrastructure |
|| External Monitoring | (deployment choice) | `sentry/sentry-laravel`, etc. | Deployment-specific | P3 | Through logging/observability |

## Sections

- [Native Laravel Capabilities](#native-laravel-capabilities)
- [Laravel Sanctum](#laravel-sanctum)
- [Spatie Laravel Permission](#spatie-laravel-permission)
- [Spatie Laravel Activitylog](#spatie-laravel-activitylog)
- [Laravel Telescope](#laravel-telescope)
- [Laravel Periscope (Telescope Companion)](#laravel-periscope-telescope-companion)
- [Laravel Pennant](#laravel-pennant)
- [API Documentation](#api-documentation)
- [Redis Compatibility](#redis-compatibility)
- [Optional Backup Package](#optional-backup-package)
- [Production Observability Integrations](#production-observability-integrations)
- [Intentional Non-Dependencies](#intentional-non-dependencies)

---

## Native Laravel Capabilities

Laravel itself provides sufficient functionality for the following concerns.
**No Composer package is introduced** for these — they are documented here
explicitly so future authors do not reintroduce packages that duplicate
framework-provided capabilities.

|| Capability | Laravel Component | Used For |
||------------|------------------|----------|
|| Authentication foundation | Sanctum (package) + native Auth | API bearer tokens, web session auth |
|| Form Requests | `Illuminate\Http\Request` validation | Request validation + request-level authorization |
|| Validation | `Illuminate\Validation` | All input validation at trust boundaries |
|| Policies | `Illuminate\Auth\Access\Policy` | Resource-level authorization decisions |
|| Gates | `Illuminate\Auth\Access\Gate` | Action-level permission checks |
|| Middleware | `Illuminate\Foundation\Http\Middleware` | Cross-cutting concerns (auth, rate limit, correlation ID) |
|| Events | `Illuminate\Events` | Domain/application event dispatch |
|| Listeners | `Illuminate\Events\Listener` | Reaction to events |
|| Notifications | `Illuminate\Notifications` | User notification abstraction |
|| Mail | `Illuminate\Mail` | Email delivery |
|| Queue | `Illuminate\Queue` | Asynchronous/background processing |
|| Scheduler | `Illuminate\Console\Scheduling` | Cron/scheduled jobs |
|| Cache | `Illuminate\Cache` | Caching abstraction |
|| Rate Limiter | `Illuminate\Cache\RateLimiter` | Rate limiting |
|| API Resources | `Illuminate\Http\Resources\Json\JsonResource` | API serialization |
|| Soft Deletes | `Illuminate\Database\Eloquent\SoftDeletes` | Soft deletes (User only) |
|| Database Transactions | `Illuminate\Database\Connection` | Transaction management |
|| Encryption | `Illuminate\Encryption` | Field encryption |
|| Hashing | `Illuminate\Hashing` | Password hashing (bcrypt/argon2) |
|| Logging | `Illuminate\Log` + Monolog | Structured application logs |
|| Filesystem | `Illuminate\Filesystem` | Storage abstraction |
|| HTTP Client | `Illuminate\Http\Client` | Outbound HTTP calls |
|| Exception Handling | `Illuminate\Foundation\Exceptions\Handler` | Centralized error responses |

**Why not packages?** Laravel's native implementations are mature, tested,
framework-version-aligned, and already maintained by the Laravel team.
Reimplementing or replacing them with a third-party package would add
transitive dependencies, upgrade friction, and maintenance burden without
commensurate value.

**Direct app usage:** These capabilities are used via Laravel facades/imports
directly. They are framework-provided, not package-coupled, so coupling to
them is standard Laravel practice and acceptable.

---

## Laravel Sanctum

|| Field | Value |
||-------|-------|
|| Composer package | `laravel/sanctum` |
|| Status | Required |
|| Priority | P0 |
||| Architecture area | Authentication foundation |
||| Package constraint | `^4.0` (Laravel 13 compatible) |

### Purpose

Sanctum provides the **authentication infrastructure** for the Base Project:

- API authentication via bearer tokens
- Web/mobile authentication support
- Token/session management foundation

### Why Sanctum

Sanctum is lighter than Passport and sufficient for the Base Project's
API-first, web+mobile dual-channel model. Passport (full OAuth2 server) is
overkill for a foundation project that needs bearer tokens and session
management, not an OAuth2 authorization code grant server. Sanctum provides
token issuance, revocation, and session cookie SPA auth without the
infrastructure overhead of a full OAuth2 server.

### Architecture Rules

- Sanctum is the **authentication infrastructure** (who are you?).
- **Application-specific security policies remain in the Base Project**
  (account locking, session invalidation, logout-all-devices, inactivity
  tracking). These are NOT Sanctum responsibilities.
- Business logic must not bypass Sanctum's guards — API routes use
  `auth:sanctum` middleware at the route layer (see `routes/api.php`).
- Sanctum handles token issuance and validation; the application handles
  token lifecycle policy (expiration, revocation triggers).

### Token Expiration Policy

Documented in `docs/base/security/session-security.md` and
`docs/base/security/authentication.md`:

- Default mobile/API token expiration: **7 days** (configurable)
- Session/token revocation must be possible independently from expiration
- Revocation triggers (application layer, not Sanctum):
  - Password change/reset
  - Account lock
  - Account deactivation
  - Logout current device (revoke current session only)
  - Logout all devices (revoke all sessions)

### Device/Session Strategy

Documented in ADR-006 and `docs/base/security/session-security.md`:

- One active Web session at a time (Web B login revokes Web A)
- One active Mobile session at a time
- Web + Mobile can remain active simultaneously
- Central authentication/session management abstraction — no scattered
  invalidation logic in controllers

### Security Considerations

- Token revocation is application-layer enforced. Sanctum provides the token
  model; the application decides when to revoke.
- Never store or log bearer tokens in application logs.
- Tokens must be transmitted over TLS only.
- API routes are gated by `auth:sanctum` — do not weaken this to `auth:web`
  on API routes.
- Token revocation is not instantaneous across all Sanctum states without
  explicit invalidation — the application must call `revoke()` on the token
  model or delete tokens explicitly.

### Maintenance / Upgrade Considerations

- Sanctum version must track the Laravel framework version. Laravel 13
  requires Sanctum 4.x.
- Sanctum's migration (`personal_access_tokens` table) must be published and
  run at Phase 1 foundation.
- Upgrade Sanctum only in lockstep with a Laravel framework upgrade.

### Testing Implications

- API endpoints must be tested with `Sanctum::actingAs($user)` for
  authenticated requests.
- Token revocation flows must be tested end-to-end (issue token → revoke →
  verify rejected).
- Logout-all-devices must be verified against Sanctum's token collection.

### Application-Level Abstraction

An application-level auth/session management abstraction should wrap Sanctum
calls so the application is not tightly coupled to the `PersonalAccessToken`
model API. All session lifecycle operations (`login`, `logout`,
`logoutCurrentDevice`, `logoutAllDevices`, `revokeClientSessions`, etc.)
should route through this abstraction.

### Replacement Strategy

If Sanctum is removed in the future:
- Replace with Passport (full OAuth2) or a custom token implementation.
- The abstraction layer above Sanctum isolates this change to the auth
  service layer.
- All route middleware (`auth:sanctum`) and `Sanctum::actingAs()` test calls
  would be updated.

---

## Spatie Laravel Permission

|| Field | Value |
||-------|-------|
|| Composer package | `spatie/laravel-permission` |
|| Status | Required |
|| Priority | P0 |
||| Architecture area | RBAC & Authorization (Phase 6 `RBAC-001`) |
||| Package constraint | `^6.0` (Laravel 13 compatible) |
||| PHP version constraint | PHP 8.3+ compatible |

### Purpose

- Roles and permissions
- Role-permission relationships
- User-role assignment
- Authorization foundation

### Why Spatie Permission

Spatie Permission is the de-facto standard Laravel RBAC package. It provides
permission caching, role hierarchy, and a clean Eloquent API that integrates
with Laravel's native `Gate` and `Policy` system. Reimplementing RBAC with
correct caching, race-condition handling, and Eloquent relations is
non-trivial and error-prone — Spatie's mature implementation is appropriate.

### Architecture Rules

- **Permission-based feature access** — features are gated by permission
  checks (`can('user.create')`, `can('audit.view')`).
- **Permission-based menu visibility** — UI menus render based on permission
  checks, but authorization is always enforced at the backend boundary
  (UI hiding is never a security mechanism).
- **Role changes** — role permission changes automatically affect all users
  assigned to that role (derived/effective authorization).
- **Superadmin behavior** — can bypass normal authorization where explicitly
  allowed; does NOT bypass every boundary. Documented explicit exceptions.

### Source of Truth

Role permissions are **effective permissions derived from the user's role**.
Do NOT physically copy all role permissions into every user unless a future
requirement explicitly requires it. The role is the source of truth; the
package + application authorization layer resolves effective permissions at
request time.

This is documented in ADR-004 and `docs/base/security/authorization.md`.

### Permission Model

```
User → Role → Permissions
```

Permission names follow the pattern `resource.action`:

|| Permission | Meaning |
||------------|---------|
|| `users.view` | View users |
|| `users.create` | Create users |
|| `users.edit` | Edit users |
|| `users.delete` | Delete users |
|| `users.activate` | Activate users |
|| `users.deactivate` | Deactivate users |
|| `users.lock` | Lock users |
|| `users.unlock` | Unlock users |
|| `roles.manage` | Manage roles |
|| `permissions.manage` | Manage permissions |
|| `audit.view` | View audit trail |
|| `audit.export` | Export audit records |

### Superadmin Protection

Documented in `docs/base/security/authorization.md`:

- Cannot delete the last valid superadmin
- Cannot deactivate the last valid superadmin
- Cannot accidentally remove all critical superadmin capabilities
- Critical system role operations are protected at the application layer

### Cache Considerations

- Spatie Permission caches role/permission assignments. Cache must be
  invalidated when roles or permissions change.
- The application must call `app()[\Spatie\Permission\PermissionRegistrar::class]->clearAppCache()`
  (or equivalent) after any role/permission mutation.
- This invalidation should be handled in the role/permission management
  Actions (application abstraction layer), not scattered across controllers.

### Security Considerations

- Authorization is enforced via Laravel `Gate`/`Policy` at the route/controller
  boundary (`can:` middleware, `authorize()` in Form Requests).
- Superadmin bypass is explicit, not blanket. Critical boundaries
  (password change, account state, audit export) are never bypassed even for
  superadmin unless explicitly allowed.
- Permission names are seeded via the DatabaseSeeder — never hardcoded in
  views or controllers. Permission lists are derived from the database.

### Maintenance / Upgrade Considerations

- Spatie Permission has minor-schema releases. Version bumps are tied to
  Laravel + PHP version compatibility.
- The `permission` cache must be flushed after any package upgrade that
  changes the schema.
- Spatie Permission 6.x targets Laravel 10-12/13 — verify compatibility
  before upgrading.

### Testing Implications

- Roles and permissions are seeded (DB-002) — tests rely on seeded roles.
- `Gate::before` closure for superadmin must be tested (cannot 403 on
  explicitly allowed actions; must still 403 on critical protected actions).
- Role permission propagation must be tested: change a role's permissions →
  verify affected users' effective permissions changed.

### Application-Level Abstraction

Permission checks route through Laravel's native `Gate`/`Policy` system
(`can:`, `authorize()`), which delegates to Spatie Permission's
`HasRoles`/`HasPermissions` traits on the User model. The application should
not call Spatie's `assignRole`/`givePermissionTo` directly in controllers —
these belong in role/permission management Actions.

### Replacement Strategy

- The `HasRoles`/`HasPermissions` traits are used on the `User` model. To
  replace the package, swap the trait usage and reimplement
  `getUserPermissions()`, `hasRole()`, `can()` resolution.
- The Gate/Policy layer isolates most application code from the package API.

---

## Spatie Laravel Activitylog

|| Field | Value |
||-------|-------|
|| Composer package | `spatie/laravel-activitylog` |
|| Status | Required |
|| Priority | P0 |
||| Architecture area | Audit Trail (Phase 10 `AUDIT-001`) |
||| Package constraint | `^4.8` (PHP 8.3/Laravel 13 compatible, NOT v5 — v5 requires PHP 8.4+) |

### Purpose

- Audit Trail (business/security accountability)
- Recording WHO did WHAT to WHICH resource

### Why Activitylog

Activitylog provides a battle-tested, minimal audit table schema, activity
causer/subject tracking, and causal/morphMap support out of the box. It is
the established audit package referenced by `docs/base/features/audit-trail.md`
("Use an established audit package rather than building from scratch").

### Audit Trail ≠ Other Observability Layers

Audit Trail answers: **WHO did WHAT to WHICH resource?**

Audit Trail is NOT the same as:
- Application Logs (WHAT happened technically) — see [logging.md](../infrastructure/logging.md)
- Server Logs (infrastructure-level)
- Telescope (HOW Laravel runtime behaved)
- Technical monitoring (debugging)

This separation is codified in ADR-008 and
`docs/base/infrastructure/observability.md`.

### Architecture Rules

- **Audit source of truth = mutation caller** (Action/Service layer), NOT
  model observers. Audit recording must be done explicitly in the Action/Service
  layer where the mutation occurs.
- An **application-level Audit abstraction** must sit above Activitylog so the
  application is not tightly coupled to the package API.
- **Transaction boundaries** — audit records must be written **within the same
  transaction** as the mutation, **before the COMMIT**. If a transaction
  rolls back, no audit record is written and a failure log is emitted
  instead.
- Audit records are **read-only** through the UI.

### Audit Metadata

Each audit record must capture:

|| Field | Source |
||-------|--------|
|| Actor (causer) | `activity()->causedBy($user)` |
|| Action (event) | Stable name (e.g. `user.created`, `user.deactivated`) |
|| Subject (subject) | `activity()->performedOn($model)` |
|| Before | Original model state (where meaningful) |
|| After | New model state (where meaningful) |
|| Metadata | request_id, IP, user agent, environment |
|| Timestamp | Auto-recorded |

### Sensitive-Data Handling

- Audit records must never store plaintext passwords, password hashes,
  bearer tokens, session secrets, reset tokens, API secrets, or private keys.
- The `withProperties(['*'])` should be used carefully — scrub sensitive
  fields (password, remember_token) before storing `properties`.
- See [logging.md](../infrastructure/logging.md) §10 (Logging Privacy) and
  `docs/base/security/data-protection.md`.

### Transaction Boundaries

- Audit records are written **within the same database transaction** as the
  mutation, **before the COMMIT**, and only persist if the transaction
  commits successfully. A rolled-back transaction must not leave a
  false-success audit record.
- If the transaction fails/rolls back, the audit record is discarded and a
  failure application log is written (e.g. `user.update.failed` with
  exception and request_id).
- This is enforced in the Action/Service layer (the audit abstraction), not in
  observers.

### Read-Only Authorization

- Audit view requires `audit.view` permission
- Audit export requires `audit.export` permission
- Audit records are read-only — no mutate/delete endpoints exposed

### Async Export

- Audit export is asynchronous (AUDIT-005) via a queued job
- Export files are stored in private storage, not public
- Export files expire per the retention policy (`retention.tmp_exports.hours`)

### Retention

- Audit logs: indefinite retention (security/legal compliance) — see
  `docs/base/operations/retention.md`
- Audit export files: 7 days after generation

### Security Considerations

- Audit records contain IP addresses and user agents — treat as sensitive
  operational data with restricted access.
- Access to audit viewing/exporting is permission-gated.
- Audit table grows indefinitely (by design) — retention/archiving strategy
  must be deployment-aware.

### Maintenance / Upgrade Considerations

- Activitylog v5 requires PHP 8.4+. The Base Project targets PHP 8.3
  (Laravel 13 compatible), so v4.x is the required constraint.
- Schema changes in major versions require migration review.
- The `activity` table is append-only — no destructive migrations expected.

### Testing Implications

- Every mutation Action must be tested: successful mutation → audit record
  created; failed mutation (rollback) → audit record NOT created + failure
  log emitted.
- Sensitive-field scrubbing must be verified: password changes must not leak
  hashes into `properties`.
- Audit metadata must be verified: request_id, IP, user agent present.

### Application-Level Abstraction

A dedicated `Audit` service/abstraction must wrap Activitylog calls. All
audit writes route through this abstraction (e.g.
`Audit::record('user.deactivated', $user, $target')`). Controllers and
Actions call the abstraction, never Activitylog directly.

### Replacement Strategy

- The `Audit` abstraction layer isolates application code. To replace
  Activitylog, reimplement the abstraction against a different audit backend.
- The `activity_log` table schema would be replaced; the abstraction's API
  contract stays stable.

---

## Laravel Telescope

|| Field | Value |
||-------|-------|
|| Composer package | `laravel/telescope` |
|| Status | Required |
|| Priority | P1 |
||| Architecture area | Monitoring / Observability (Phase 11 `MONITOR-001`) |
||| Package constraint | `^5.0` (Laravel 13 compatible) |

### Purpose

Technical/runtime observability of the Laravel application:

- Requests
- Exceptions
- Queries
- Jobs
- Commands
- Mail
- Notifications
- Cache
- Events
- Logs
- Runtime investigation

### Audience

Telescope is intended for **technical users**:

- Developers
- Technical administrators
- DevOps / SRE
- Authorized super administrators

Telescope is NOT for non-technical operational/security users — those use the
Audit Trail. This separation is ADR-008.

### Why Telescope

Telescope is Laravel's first-party technical debugging tool. It provides
deep runtime introspection with zero custom instrumentation. Reimplementing
its functionality (query logging, job tracing, exception capture) by hand
would duplicate substantial effort and is not appropriate for a foundation
project.

### Security / Production Requirements

- Telescope must be **disabled in production** unless explicitly enabled for
  an authorized technical user.
- Telescope route access is gated via a `Gate::allowIf` check in
  `TelescopeServiceProvider` — only `web` env or users with a designated
  permission may access it.
- Telescope data retention: 7 days (`retention.telescope.days`), auto-purge.
- Telescope must NOT replace the Audit Trail. Telescope records technical
  traces; Audit Trail records business/security accountability.

### How It Integrates

- Installed via Composer at Phase 1 (`FOUND-007`).
- `TELLESCOPE_ENABLED=false` in production by default.
- Access controlled via `TelescopeServiceProvider::gate()`.
- Data retention managed via Telescope's config + the retention job
  (`RETAIN-001`).

### Maintenance / Upgrade Considerations

- Telescope version must track the Laravel framework version.
- Telescope's migrations create its own tables — review before major upgrades.
- Disable Telescope in production unless actively debugging.

### Testing Implications

- Telescope must not be loaded in the test environment (performance).
- Tests should set `TELLESCOPE_ENABLED=false` in `phpunit.xml`.

### Application-Level Abstraction

Telescope is a **technical tool**, not an application dependency. Application
code must NOT depend on Telescope APIs. Telescope observes the application;
the application does not call Telescope.

---

## Laravel Periscope (Telescope Companion)

||| Field | Value |
|||-------|-------|
||| Composer package | `seanbarton/laravel-periscope` |
||| Status | Required (companion to Telescope) |
||| Priority | P1 |
||| Architecture area | Monitoring / Observability |
||| Package constraint | `^0.3` (Laravel 13 compatible) |

### Purpose

Periscope is a **companion UI** for browsing, filtering, and searching
Telescope's existing data (requests, exceptions, queries, jobs, mail,
notifications, cache, events, logs). It does NOT replace Telescope — it
augments it with a streamlined interface for technical debugging.

- Telescope remains the data collector and primary dashboard (`/telescope`).
- Periscope provides an additional dashboard (`/periscope`) that reads the
  same `telescope_entries` table.
- Periscope inherits Telescope's authorization via `Telescope::check($request)`.
- No separate auth, gate, role, policy, or migration.
- Periscope excludes its own requests from Telescope watchers and auto-disables
  debugbar on dashboard routes (default package behavior).

### How It Integrates

- Installed via Composer alongside Telescope.
- Routes auto-registered at `/periscope` (configurable via `PERISCOPE_PATH`).
- Authorization middleware calls `Telescope::check($request)` — identical
  mechanism to Telescope's `/telescope` route.
- Reads Telescope's `telescope_entries` and `telescope_entries_tags` tables —
  no new database tables or migrations.
- `PERISCOPE_ENABLED=false` to disable in production or when Telescope is off.

### Architecture

- Telescope = data collector + primary debugging dashboard.
- Periscope = companion UI for browsing/filtering/searching that data.
- Both are technical tools for technical users only.
- Neither replaces the Audit Trail (business/security accountability).
- This separation is codified in DEP-004 (Telescope) and `observability.md`.

### Security Considerations

- Periscope access is gated by the same Telescope authorization mechanism.
- Periscope reads Telescope's data — no additional data exposure beyond what
  Telescope already provides.
- Disable Periscope in production if Telescope is disabled (set
  `PERISCOPE_ENABLED=false`).

---

## Laravel Pennant

||| Field | Value |
|||-------|-------|
||| Composer package | `laravel/pennant` |
||| Status | Required |
||| Priority | P1 |
||| Architecture area | Feature Availability (`FLAG-001`) |
||| Package constraint | `^1.26` (Laravel 13 compatible) |

### Purpose

Lightweight feature flag layer for enabling/disabling features at runtime
without code deployment. Follows the feature availability architecture
documented in `docs/base/features/feature-flags.md`.

### How It Integrates

- Installed as a Phase 1 foundation (`FLAG-001`).
- Storage via `PENNANT_STORE` env var (default: `database`).
- `features` table migration published and migrated.
- `@feature` / `@featureany` Blade directives available.
- No business-specific flags created in Phase 1 — added by future feature
  phases when a feature requires one.
- Feature availability is separate from authorization (documented in
  `feature-flags.md`).

---

## API Documentation

|| Field | Value |
||-------|-------|
|| Composer package | `dedoc/scramble` |
|| Status | Planned |
|| Priority | P1 |
||| Architecture area | API V1 (Phase 12 `API-003`) |
||| Package constraint | `^0.13` (Laravel 13 compatible; ^2.x not yet released) |

### Purpose

- Auto-generates API documentation from Laravel routes, Form Requests, and
  API Resources (annotation-free)
- API contract generation from code structure
- Provides an interactive API documentation UI for frontend/mobile developers
- Produces an OpenAPI specification (output, not the driving concern)
- Frontend/mobile developer usability for consuming the API independently

### Why Scramble

Per `docs/base/api/documentation.md`, Scramble is the documented
recommended tool:

> **Recommended documentation generators:**
> - **Scramble** — annotation-free, generates API documentation from Laravel
>   routes, Form Requests, and API Resources
> - OpenApiJson — alternative JSON output
> - Redoc — API docs UI renderer

Scramble is the selected tool because it extracts documentation directly from
Laravel routes, Form Requests, and API Resources — reducing drift between
code and docs. It natively supports Sanctum bearer-token authentication
documentation and produces an interactive documentation UI.

### Alternatives Considered

- **Swagger/Passport-docs self-hosted**: Requires manual API spec
  maintenance — drifts from code. Rejected.
- **Redoc (static)**: A UI renderer only — no generation capability. Not
  sufficient alone; Scramble generates the spec Redoc can render.
- **Custom documentation system**: Rejected per documentation.md: "Do not
  build a custom documentation system unnecessarily."

### How It Integrates

- Installed at Phase 12 (`API-003`).
- Configured via `config/scramble.php`.
- Documentation available at `/api/docs/v1` (versioned alongside the API).
- Scramble extracts auth requirements from route middleware (`auth:sanctum`).
- Scramble extracts validation rules from Form Requests — documentation is
  generated from the Form Request definition, ensuring DRY.

### Security Considerations

- Scramble docs may expose API structure — gate behind `auth:sanctum` or
  restrict to non-production environments.
- Never expose internal implementation details in example responses.
- API documentation must not include secrets or real tokens in examples.

### Maintenance / Upgrade Considerations

- Scramble version must track Laravel/PHP compatibility.
- Re-run `scramble:docs` after route/resource changes before release.

### Testing Implications

- Documented endpoints must be tested against actual route behavior.
- Scramble output must not drift from implementation.

### Application-Level Abstraction

Documentation-only package. Application code does not depend on Scramble at
runtime in production. Scramble is a dev dependency used for documentation
generation.

---

## Redis Compatibility

|| Field | Value |
||-------|-------|
|| Composer package | `predis/predis` (pure PHP) or `ext-redis` (PHP extension) |
|| Status | Optional / Deployment-specific |
|| Priority | P2 |
|| Architecture area | Queue & Cache backends (Phase 1 `CACHE-001`, `QUEUE-001`) |

### Purpose

Redis as a **drop-in backend** for Laravel's Queue, Cache, Rate Limiter, and
Lock facades.

### Architecture Decision

- **Database is the default** Base Project backend for queue and cache.
- **Redis is an optional production/high-throughput option.**
- Application/business logic must NOT depend directly on Redis-specific APIs.
- All infrastructure access is via Laravel abstractions: `Queue` facade,
  `Cache` facade, `RateLimiter`, `Lock`.

This decision is documented in DEP-006, `docs/base/architecture/dependency-rules.md`,
and `docs/base/infrastructure/redis-compatibility.md`.

### Configuration

|| Backend | Queue Driver | Cache Driver |
||---------|-------------|--------------|
|| Default | `database` | `file` (or `array` for tests) |
|| Redis (production) | `redis` | `redis` |

Redis is enabled by setting `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis`
in the deployment `.env` — no application code changes required.

### Why Not Mandatory

Redis requires external infrastructure (a running Redis server). The Base
Project must work with only Composer + PHP + a database (for queue) to keep
the barrier to entry low for new deployments. Redis can be swapped in via
config.

### Security Considerations

- Redis has no authentication by default — deployment config must enforce
  bind address, `requirepass`, and TLS.
- Do not expose Redis directly to the application without network isolation.

### Replacement Strategy

If Redis is abandoned, switch `CACHE_STORE` and `QUEUE_CONNECTION` back to
`database`/`file` — the application code is unchanged because it uses
Laravel facades exclusively.

---

## Optional Backup Package

|| Field | Value |
||-------|-------|
|| Composer package | `spatie/laravel-backup` |
|| Status | Planned / Optional |
|| Priority | P2 |
|| Architecture area | Backup & DR (Phase 14 `BACKUP-001`) |
|| Installed version | Not installed |
|| Required constraint | `^10.x` (Laravel 13 compatible) |

### Purpose

- Database backup
- Application/storage file backup
- Scheduled backup
- Backup retention
- Disaster recovery
- Restore testing

### Why Spatie Backup

`docs/base/infrastructure/backup-disaster-recovery.md` §Tools documents:

> Laravel `backup` package (`spatie/laravel-backup`) for database/file backups.

Spatie Backup is the selected candidate because it provides a battle-tested
backup scheduler, integrity verification, offsite upload support (S3, etc.),
and a clean Artisan command interface. Reimplementing backup scheduling,
verification, and offsite storage is inappropriate for a foundation project.

### Why Not Core

- Backup is an **infrastructure concern**, not application business logic.
- Backup strategy is deployment-specific (RPO/RTO vary per deployment).
- The Base Project provides the retention job skeleton and audit hook, but
  the specific backup mechanism is deployment-configured.

### How It Integrates

- Installed at Phase 14 (`BACKUP-001`).
- Scheduled via `app/Console/Kernel.php` (`backup:run`, `backup:clean`).
- Backup verification result stored in audit log (backup/restore operations
  are audited).
- Offsite storage configured per deployment (S3, etc.).

### Security Considerations

- Encrypted backups at rest (deployment responsibility).
- Access control on backup files (private storage).
- Never store secrets in version-controlled backup configuration.
- Audit backup/restore operations.

### Maintenance / Upgrade Considerations

- Backup version must track Laravel/PHP compatibility.
- Review backup configuration on every upgrade.

### Testing Implications

- Backup/restore procedure must be documented and periodically tested
  (restore testing recommended monthly).
- The retention job (`RETAIN-001`) must be tested for cleanup correctness.

---

## Production Observability Integrations

|| Field | Value |
||-------|-------|
|| Packages | `sentry/sentry-laravel`, `open-telemetry/sdk`, `datadog/dd-trace`, etc. |
|| Status | Deployment-specific |
|| Priority | P3 |
|| Architecture area | Observability (Phase 11 `MONITOR-001`) |

### Purpose

External observability platforms for production deployments.

### How It Integrates

Production projects may add an external observability platform **without
modifying the business/domain architecture**. The Base Project provides the
foundation:

- Laravel structured logging (`storage/logs/laravel-YYYY-MM-DD.log`)
- Correlation/request IDs (every log entry)
- Telescope (development/technical debugging)
- Exception handling (centralized, safe responses)
- Health check endpoint (`/api/v1/health`)

External platforms (Sentry, OpenTelemetry, Datadog, New Relic, ELK,
Grafana/Loki) plug into the existing Laravel logging + exception handling
layer. No application code changes are required — only a deployment-specific
service provider registration and config.

### Examples

|| Platform | Integration Point |
||----------|-------------------|
|| Sentry | Register `\Sentry\LaTeX\HttpFoundation\...` + `SENTRY_DSN` env var |
|| OpenTelemetry | `open-telemetry/sdk` via Laravel OTel bridge |
|| Datadog | `datadog/dd-trace` PHP extension |
|| New Relic | New Relic PHP agent |
|| ELK | Laravel log driver → filebeat/File input |
|| Grafana/Loki | Promtail + Loki log shipping from daily log files |

### Security Considerations

- External monitoring credentials belong in environment configuration.
- Do not send sensitive data (passwords, tokens, PII) to external platforms.
- The correlation ID allows correlating external-platform traces with
  internal application logs and audit records.

### Replacement Strategy

Each platform is swap-in/swap-out via the Laravel logging/monitoring config.
The application code is platform-agnostic.

---

## AdminLTE UI Template

||| Field | Value |
|||-------|-------|
||| Asset source | Official AdminLTE release ZIP (not npm) |
||| Asset location | `public/vendor/adminlte/` |
|||| Package constraint | AdminLTE v4.9.1 |
||| Added to `package.json` | No |
||| ADR | ADR-019: AdminLTE as replaceable UI template |
||| Task | UI-001 |

AdminLTE is the initial/default admin UI template. It is vendored from the
official GitHub release ZIP into `public/vendor/adminlte/` — NOT installed via
npm. This avoids coupling the application's frontend build pipeline to
AdminLTE's release cycle. AdminLTE is a presentation-layer concern only; the
Base Project architecture remains UI-independent (ADR-002).

See `docs/base/ui/ui-adminlte-setup.md` for the full installation strategy.

---

## Intentional Non-Dependencies

The Base Project intentionally does **not** introduce separate packages for
the following concerns. These are implemented using Laravel-native
functionality and/or Base Project application architecture (Actions,
Services, Form Requests, Models, Policies, Middleware, Events, Jobs,
Notifications).

|| Concern | Native Implementation | Why no package |
||---------|----------------------|-----------------|
|| Settings | `config/` + DB settings table + Settings model | Simple key/value store; framework config + a small table suffices |
|| Password policy | `Illuminate\Validation\Rules\Password` | Native Laravel rule provides IM8 policy (min, mixed, numbers, symbols) |
|| Password history | Custom `password_history` table + validation rule | Domain rule; trivial to implement, package adds overhead |
|| Password expiration | Custom `password_expires_at` + middleware | Account lifecycle state, not framework functionality |
|| Failed-login tracking | Custom `failed_login_attempts` table + LoginAction logic | Account-security concern; package-independent |
|| Account locking | `is_locked` column + `LOCKED_UNTIL` + LoginAction logic | Account state, handled at auth boundary |
|| Account activation | `is_active` + `email_verified_at` columns | User state; no package needed |
|| Email verification | Laravel native `MustVerifyEmail` | Framework provides this |
|| Session invalidation | Sanctum token revocation + custom session cleanup | Auth infrastructure concern |
|| Device strategy | Custom device/session tracking in auth layer | Business rule, not framework functionality |
|| Rate limiting | `Illuminate\Cache\RateLimiter` + middleware | Native Laravel rate limiter is sufficient |
|| API Resources | `Illuminate\Http\Resources\Json\JsonResource` | Native serialization |
|| Form Requests | `Illuminate\Http\Request` validation | Native validation + authorization |
|| Service layer | `app/Actions/`, `app/Services/` | Application architecture pattern, not a package |
|| Actions | `app/Actions/` | Use-case pattern, not a package |
|| Domain/application events | `Illuminate\Events` | Native event system |
|| Notification abstraction | `Illuminate\Notifications` | Native notification channels |
|| Error handling | `app/Exceptions/Handler.php` | Native exception handler |
|| Soft deletes | `Illuminate\Database\Eloquent\SoftDeletes` | Native trait |
|| UUID/ULID by default | Native Eloquent keys (bigint) | ADR-010: UUID only when concrete requirement exists |
|| Database transactions | `DB::transaction()` / `DB::beginTransaction()` | Native |
|| Request/correlation IDs | Custom middleware (`CORR-001`) | Simple request-ID generation; package is overkill |
|| Custom authorization policies | `Illuminate\Auth\Access\Policy` | Native policies + Gates |
||| Feature availability rules | Laravel native Gate + config | Lightweight feature flag layer, not a heavy package |
||| Feature flag storage | Laravel native cache/database | Use framework primitives over package abstractions |
||| AdminLTE UI template | Vendored release ZIP → `public/vendor/adminlte/` | NOT via npm — see ADR-019 and `docs/base/ui/ui-adminlte-setup.md` |

### Guiding Principle

Packages should only be introduced when they provide substantial,
well-maintained functionality that is inappropriate to reinvent. The Base
Project favors Laravel-native capabilities whenever the framework provides a
sufficient, tested implementation. This minimizes transitive dependencies,
reduces upgrade friction, and keeps the project maintainable.