# Architecture Decision Records

> Key ADRs that shape the architecture. Full list with details in `[decisions.md](../planning/decisions.md)`.
> 13 ADRs total (ADR-001 through ADR-013).

## ADR-001: API-first architecture
|- All core capabilities have a clean API boundary.
- API contracts consumable by frontend/mobile independently from Laravel internals.

## ADR-002: UI-independent core
- Business logic does not depend on Blade/Vue/React.
- UI is replaceable; backend is the source of truth.

## ADR-003: Database queue with Redis compatibility
- Default queue: database.
- Architecture remains Redis-compatible but Redis is not mandatory.
- Business logic does not depend directly on Redis.

## ADR-004: Role-derived permissions
- User → Role → Permissions
- Role permission changes automatically affect all users assigned to that role.
- Do not physically copy permissions into every user unless explicitly required.

## ADR-005: Separate active/inactive and locked/unlocked
- Do NOT use a single status field for all account states.
- is_active, is_locked, email_verified_at, last_activity_at, password-related security state are separate concepts.

## ADR-006: Session/device strategy
- Only one active Web session at a time (Web B login revokes Web A).
- Only one active Mobile session at a time.
- Web + Mobile can remain active simultaneously.
- Central authentication/session management abstraction; no scattered invalidation logic.

## ADR-007: Password history strategy
- Store hashes only; never plaintext.
- New password cannot match configured number of previous hashes.
- History: enabled/disabled + configurable count via Settings.

## ADR-008: Audit Trail vs Telescope separation
- Audit Trail: business/security accountability (non-technical users).
- Telescope: Laravel technical debugging (technical users).
- Do not merge these concerns.
- Audit source of truth is the mutation caller, not observers.

## ADR-009: API versioning
- Versioned APIs: `/api/v1/...`, future `/api/v2/...`
- V1 remains stable when V2 is introduced.

## ADR-010: Database ID strategy
- Default: integer/bigint.
- UUID only when concrete requirement exists.

## ADR-011: Soft delete strategy
- Soft delete on User. Not on Audit, Permission, Settings.
- Session uses lifecycle cleanup.

## ADR-012: Cascade relationship strategy
- Cascade only when parent-child lifecycle semantically requires it.
- Do not blindly add cascade to every relationship.

## ADR-013: Application Logging Strategy
- Four-tier logging: Audit Trail (who/what → accountability) vs Application Logs (what → technical) vs Server Logs (infra) vs Telescope (how Laravel behaved).
- Failures classified: expected (validation, authn, authz, rate-limit, business rule → warning/info) vs unexpected (DB exception, uncaught error, queue failure → error).
- Structured logs use stable event/action names; always include correlation ID.
- Centralized exception handler logs once; never in every controller/service.
- Transactions: audit record created only AFTER commit; failure log includes rollback indicator; no false-success audit on failure.
- Sensitive data never logged; stack traces are environment-aware; production-safe messages.
- See `docs/base/infrastructure/logging.md`.

## ADR-001 (Dependency): Sanctum for API Auth
See [`ADR-001-sanctum-api-authentication.md`](./decision-records/ADR-001-sanctum-api-authentication.md)

## ADR-002 (Dependency): Spatie Permission for RBAC
See [`ADR-002-spatie-permission-rbac.md`](./decision-records/ADR-002-spatie-permission-rbac.md)

## ADR-003 (Dependency): Spatie Activitylog for Audit Trail
See [`ADR-003-spatie-activitylog-audit-trail.md`](./decision-records/ADR-003-spatie-activitylog-audit-trail.md)

## ADR-004 (Dependency): Telescope for Technical Observability
See [`ADR-004-telescope-technical-observability.md`](./decision-records/ADR-004-telescope-technical-observability.md)

## ADR-005 (Dependency): Native Laravel First
See [`ADR-005-native-laravel-first.md`](./decision-records/ADR-005-native-laravel-first.md)

## ADR-006 (Dependency): Database Queue with Redis Compatibility
See [`ADR-006-database-queue-redis-compatible.md`](./decision-records/ADR-006-database-queue-redis-compatible.md)

## ADR-007 (Dependency): API Documentation Strategy (Scribe)
See [`ADR-007-api-documentation-strategy.md`](./decision-records/ADR-007-api-documentation-strategy.md)

> Full details for dependency decisions are in
> [`docs/base/architecture/decision-records/`](./decision-records/) and
> [`docs/base/dependencies/`](../dependencies/overview.md).