# Architecture Decision Records

> Key ADRs that shape the architecture. Full list with details in `[decisions.md](../../planning/decisions.md)`.
> **Numbering convention:** Architecture ADRs use `ADR-001` through `ADR-018` (inline in `decisions.md`).
> Dependency-selection ADRs use `DEP-001` through `DEP-007` (files in `decision-records/`).
> This separation prevents numbering collisions. To determine the next ADR number: count existing architecture ADRs in `decisions.md` + 1 for the next architecture ADR; count files in `decision-records/` + 1 for the next dependency ADR.
>
> 18 architecture ADRs total (ADR-001 through ADR-018), plus 7 dependency-specific
> ADRs in `docs/base/architecture/decision-records/` (DEP-001 through DEP-007).

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

## ADR-008: Audit Trail vs Technical Observability separation
|- Five-tier classification: Audit Trail (who/what) / Application Logs (what) / Security Logs (security events) / Server Logs (infra) / Telescope (how Laravel behaved).
|- Audit Trail source of truth = mutation caller, not observers.
|- Telescope is for technical debugging only; NOT a replacement for Audit Trail.

## ADR-009: API versioning
|- Versioned APIs: `/api/v1/...`, future `/api/v2/...`
|- V1 remains stable when V2 is introduced.
|- Application logic shared when behavior identical; separate when behavior diverges.

## ADR-010: Database ID strategy
|- Default: integer/bigint.
|- UUID only when concrete requirement exists.

## ADR-011: Soft delete strategy
|- Soft delete on selected entities only (not every model).
|- Not on Audit, Permission, Settings. Session uses lifecycle cleanup.
|- Per-entity deletion policy required.

## ADR-012: Cascade relationship strategy
|- Cascade allowed when child records have no independent lifecycle and semantics are unambiguous.
|- Restrict/no-action when deletion would cause unacceptable data loss.
|- Cascade behavior documented per relationship. Never blanket cascade.

## ADR-013: Application Logging Strategy
|- Five-tier: Audit Trail (accountability) / Application Logs (technical) / Security Logs / Server Logs (infra) / Telescope (runtime debugging).
|- Failures classified: expected (validation, authn, authz, rate-limit, business rule → warning/info) vs unexpected (DB exception, uncaught error, queue failure → error).
|- Structured logs use stable event/action names; always include correlation ID.
|- Centralized exception handler logs once; never in every controller/service.
|- Transactions: audit record written within the transaction, before COMMIT; failure log includes rollback indicator; no false-success audit on failure.
|- Sensitive data never logged; stack traces are environment-aware; production-safe messages.
|- See `docs/base/infrastructure/logging.md`.

## ADR-014: System role protection
|- System roles (superadmin/admin/user) cannot be deleted, renamed, or destructively altered.
|- Cannot remove the last valid superadmin.
|- Superadmin bypasses where explicitly allowed only; NOT everywhere.

## ADR-015: UI as replaceable API client
|- UI is a client of the application/API. No business logic in views.
|- UI authorization is for visibility/UX only; backend is the security boundary.
|- Design system uses semantic tokens; implementation-independent.

## ADR-016: Cascade delete when justified (updates ADR-012)
|- Cascade IS allowed when justified; not forbidden by default.
|- Intentional, documented per relationship.

## ADR-017: Configuration vs runtime settings
|- Two-layer: config files (infrastructure) vs database settings (operational).
|- Runtime settings managed via UI with validation, authorization, audit, cache invalidation.
|- Secrets never moved into database settings.

## ADR-018: last_activity_at and never-logged-in policy
|- baseline update point for `last_activity_at` is successful login.
|- `last_activity_at = NULL` (never logged in) is handled by the enabled `inactivity_lock_grace_enabled` setting and `inactivity_lock_grace_days`; when disabled, only the normal inactivity threshold applies.
|- Unlocking an account does NOT populate `last_activity_at` — NULL is preserved until the user performs an actual application action. Unlock is not user activity.
|- Inactivity lock revokes sessions/tokens.

## Dependency-decision ADRs (DEP-001–DEP-007)

See the files in [`docs/base/architecture/decision-records/`](./decision-records/):

- DEP-001: Sanctum for API Auth
- DEP-002: Spatie Permission for RBAC
- DEP-003: Spatie Activitylog for Audit Trail
- DEP-004: Telescope for Technical Observability
- DEP-005: Native Laravel First
- DEP-006: Database Queue with Redis Compatibility
- DEP-007: API Documentation Strategy (Scramble)

Full details:
[`docs/base/architecture/decision-records/`](./decision-records/) and
[`docs/base/dependencies/`](../dependencies/overview.md).