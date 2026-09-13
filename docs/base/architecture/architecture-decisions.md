# Architecture Decision Records

> Key ADRs that shape the architecture. Full list with details in `[decisions.md](../planning/decisions.md)`.

## ADR-001: API-first architecture
- All core capabilities have a clean API boundary.
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