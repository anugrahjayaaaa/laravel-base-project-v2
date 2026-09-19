# Progress

> Allows a new AI session to understand project state without reading the entire conversation.

## Current Phase

| Phase | Title | Status |
|-------|-------|--------|
| 0 | Architecture & project conventions | DONE |
||| 1 | Laravel foundation & environment | DONE |
|| 2 | Database foundation | DONE |
|| 3 | Authentication foundation | IN PROGRESS |
| 4 | User lifecycle & user management | PLANNED |
| 5 | Password/security lifecycle | PLANNED |
| 6 | RBAC & authorization | PLANNED |
| 7 | Feature availability / feature flags | PLANNED |
| 8 | Settings | PLANNED |
| 9 | Notification/mail/queue | PLANNED |
| 10 | Audit Trail | PLANNED |
| 11 | Monitoring/observability | PLANNED |
| 12 | API V1 | PLANNED |
| 13 | Security hardening | PLANNED |
| 14 | Storage/backup/retention | PLANNED |
| 15 | Comprehensive testing | PLANNED |
| 16 | Documentation verification | PLANNED |
| 17 | Full regression / final review | PLANNED |

## Current Task

DB-001 — Create base migration scaffold (Phase 2, DONE — migrations seeded)

## Next Phase

Phase 3 — Authentication foundation (AUTH-001: Define authentication requirements, PLANNED)

## Completed Tasks

Phase 0 (P0-001 through P0-011) — architecture documentation and planning system.

Phase 1 (FOUND-001 through FOUND-010, + CACHE-001, QUEUE-001, CORR-001, UI-001
through UI-006, SOFT-001, TABLE-001, FLAG-001) — Laravel 13 foundation,
API foundation, AdminLTE UI foundation, and feature flags:

- Laravel 13.31.0 initialized (PHP 8.3)
- `.env.example` configured: APP_NAME="Laravel Base Project", MySQL default,
  CACHE_STORE=file, QUEUE_CONNECTION=database, SESSION_DRIVER=database,
  PENNANT_STORE=database (Laravel Pennant), PERISCOPE_ENABLED=true (Periscope)
- Config: cache=file default / Redis available; auth=web+sanctum API guard;
  queue=database default / Redis compatible
- Sanctum ^4.0 installed, API guard configured, User has HasApiTokens
- Spatie Permission ^6.0 installed, migrations + config published, User has HasRoles
- Spatie ActivityLog ^4.8 installed (Phase 10 integration pending)
- Telescope ^5.0 installed, config + migrations published. Periscope v0.3
  (seanbarton/laravel-periscope) added as companion UI at /periscope, reading
  the same Telescope data, inheriting Telescope authorization. No separate
  migration or auth mechanism.
- Scramble ^0.13 (dev) installed
- Pint ^1.27 for PSR-12 code style, pint.json preset=psr12
- Correlation ID middleware (FOUND-008 / CORR-001): GenerateRequestCorrelationId
  registered in bootstrap/app.php, X-Request-ID response header, 3 tests
- Health check endpoint (FOUND-010): GET /api/v1/health via HealthCheckController +
  HealthCheckService + HealthCheckResource, 4 tests
- Cache config (CACHE-001): file default, Redis available
- Queue config (QUEUE-001): database default, Redis compatible
- AdminLTE 4.9.1 vendored into public/vendor/adminlte/ (UI-001, not via npm)
- Application shell (UI-002): header + sidebar + footer shared partials
- Shared UI components (UI-003): button, input, badge, alert, empty-state,
  loading-state, error-state, sortable-th, action-menu, confirm-action
- Confirmation modal (UI-004): reusable modal with danger/warning/info variants
- Theme toggle (UI-005): system default + manual override, localStorage persisted,
  no theme flash (head inline script), icon-only toggle
- UI foundation cleanup (UI-006): partials renamed (no app-* prefix), theme
  fixed, i18n removed from UI foundation, style guide updated
- Soft delete convention (SOFT-001): documented + SoftDeletes on User model
- Table conventions (TABLE-001): sortable-th component, Bootstrap pagination
- Laravel Pennant (FLAG-001): installed, features table migration published + migrated,
  @feature/@featureany Blade directives available
|- Laravel Periscope (MONITOR-001): companion UI for Telescope at /periscope,
  inherits Telescope authorization via Telescope::check(), 4 tests

Phase 2 (DB-001, DB-002) — database foundation:

- Base migration scaffold: Laravel defaults + Spatie permission tables (DB-001)
- RoleSeeder created + wired into DatabaseSeeder (DB-002)

Architecture gap-closing pass — added:

- `docs/base/architecture/application-components.md` (component responsibility model + canonical flow + source-of-truth rules)
- `docs/base/dependencies/` (`overview.md`, `dependency-matrix.md`)
- `docs/base/governance/dependency-governance.md`
- `docs/base/architecture/decision-records/DEP-001` through `DEP-007`
- `docs/base/ui/` (`ui-architecture.md`, `ui-authorization.md`, `design-system.md`)
- Enhanced: `application-boundaries.md` (transaction/after-commit rules),
  `audit-trail.md` (transaction boundaries), `queue.md` (after-commit dispatch),
  `logging.md` (5-way observability + correlation ID propagation),
  `observability.md` (5-way classification + security logs),
  `retention.md` (security logs), `user-management.md` (lifecycle transitions +
  state distinctions), `authentication.md` (session revocation + last_activity
  policy), `rate-limiting.md` (precedence + categories + race conditions),
  `authorization.md` (system role protection + superadmin bypass table),
  `soft-delete.md` (deletion policy + cascade rules), `roles-permissions.md`
  (system role protection), `ai-execution-guide.md` (dependency + transaction
  rules + source-of-truth rules).

## Blocked Tasks

None — no implementation has started yet.

## Known Issues

**Resolved conflicts** (closed in the gap-closure pass):
- Scramble API documentation package — resolved: Scramble is the
  selected tool (see DEP-007). All documentation updated.
- `last_activity_at = NULL` policy — resolved: unlocking does NOT populate
  `last_activity_at`; NULL preserved until actual user activity (see ADR-018).
- ADR numbering collision — resolved: dependency ADRs renamed from ADR-001–007
  to DEP-001–DEP-007 to avoid collision with architecture ADRs (ADR-001–018).
- Audit transaction-timing ambiguity — resolved: audit records are written
  within the transaction (before COMMIT); after-commit is for jobs/events only.
- Stale `fruitcake/laravel-cors` reference — resolved: CORS is handled natively
  by Laravel's `HandleCors` middleware (Laravel 11+). Updated in
  `docs/base/security/web-security.md`.
- Stale `security_login_failures` table name — resolved: updated to
  `failed_login_attempts` in `docs/base/operations/troubleshooting.md` and
  `docs/base/dependencies/overview.md` (both now use consistent table name).
- Stale `SESSION_DRIVER=sanctum`/`passport` reference — resolved: updated in
  `docs/base/operations/troubleshooting.md`.
- Stale feature-matrix section IDs (`#22`, `#27`, `#47`, `#254`, `#48`) —
  resolved: section column removed; phase/priority columns preserved.
- Missing Security Logs in retention table — resolved: added
  `docs/base/security/data-protection.md`.

**Open** (documented design decisions, not bugs):
|- CACHE-002 remains intentionally deferred — no application-level cached data
  requiring cache::tags() grouped invalidation. Pattern documented in cache.md.
|- i18n remains intentionally deferred to the final project-wide phase. No
  lang/ directory; all Phase 1 views use static text (style-guide.md §160).

## Architecture Changes

- ADR-013: Application Logging Strategy (added)
- DEP-001 through DEP-007: Dependency decision records (added)
- Cascade rules now permitted per-relationship when justified (updates
  ADR-012; see `docs/base/data/soft-delete.md`)
- Application component responsibility model documented
  (`docs/base/architecture/application-components.md`)
- Transaction/after-commit semantics formalized
  (`docs/base/architecture/application-boundaries.md`)
- 5-way observability classification (Audit Logs, Application Logs, Security
  Logs, Server Logs, Telescope) formalized
- System role protection formalized (superadmin bypass table, system role
  deletion/renaming restrictions)

## Test Status

Not yet started (Phase 15).

## Documentation Status

|| Area | Status |
||------|--------|
|| Architecture | Complete |
|| Dependencies | Complete |
|| Security | Complete |
|| API | Complete |
|| Data | Complete |
|| Infrastructure | Complete |
|| Features | Complete |
|| Testing | Complete |
|| Operations | Complete |
|| UI | Complete |
|| Planning | Complete |

## Next Steps

1. ~~Begin Phase 1: Laravel foundation & environment~~ — Phase 1 complete & merged to main (commit f54d0c9)
  (FOUND-001 through FOUND-010, CACHE-001, QUEUE-001, CORR-001,
  UI-001 through UI-006, SOFT-001, TABLE-001, FLAG-001 with Laravel Pennant)
2. ~~Begin Phase 2: Database foundation~~ — Phase 2 complete (DB-001 base migrations, DB-002 RoleSeeder wired)
3. Begin Phase 3: Authentication foundation — IN PROGRESS

## Summary

All documentation and planning system complete. Phase 1 implementation
complete (FOUND-001 through FOUND-010, CACHE-001, QUEUE-001, CORR-001,
UI-001 through UI-006, SOFT-001, TABLE-001, FLAG-001 with Laravel Pennant).
Phase 1 merged to main (commit f54d0c9).
Phase 2 complete (DB-001 + DB-002). Next step: Phase 3 — authentication foundation (AUTH-001).