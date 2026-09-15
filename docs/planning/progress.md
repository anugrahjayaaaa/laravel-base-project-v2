# Progress

> Allows a new AI session to understand project state without reading the entire conversation.

## Current Phase

| Phase | Title | Status |
|-------|-------|--------|
| 0 | Architecture & project conventions | DONE |
||| 1 | Laravel foundation & environment | DONE |
|| 2 | Database foundation | IN PROGRESS |
| 3 | Authentication foundation | PLANNED |
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

DB-001 — Create base migration scaffold (Phase 2, IN_PROGRESS)

## Completed Tasks

Phase 0 (P0-001 through P0-011) — architecture documentation and planning system.

Phase 1 (FOUND-001 through FOUND-007) — Laravel 13 foundation & environment:
- Laravel 13.31.0 initialized (PHP 8.3)
- `.env.example` configured: APP_NAME="Laravel Base Project", MySQL default, CACHE_STORE=file, QUEUE_CONNECTION=database, SESSION_DRIVER=database
- Config: cache=default file, database=default mysql, auth=web+sanctum API guard
- Sanctum ^4.0 installed, API guard configured, User has HasApiTokens
- Spatie Permission ^6.0 installed, migrations + config published, User has HasRoles
- Spatie ActivityLog ^4.8 installed
- Telescope ^5.0 installed, config + migrations published
- Scramble ^0.13 (dev) installed
- Pint ^1.27 for code style

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
|- Phase 1 implementation in progress (found-001 through found-007 complete; found-008 onwards pending).

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

1. ~~Begin Phase 1: Laravel foundation & environment~~ — Phase 1 complete (FOUND-001 through FOUND-007)
2. Begin Phase 2: Database foundation (DB-001 base migration scaffold) — IN PROGRESS
3. Implement authentication, user management, security, RBAC in subsequent phases

## Summary

All documentation and planning system complete. Phase 1 implementation
complete (FOUND-001 through FOUND-007). Next step: Phase 2 — database
foundation migrations (DB-001).