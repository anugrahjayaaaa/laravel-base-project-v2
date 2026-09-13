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

## ADR-008: Audit Trail vs Telescope separation

**Status**: Accepted
**Context**: Audit Trail (business/security accountability) is frequently conflated with Telescope (technical debugging). They serve different audiences and purposes.
**Decision**: Audit Trail is the primary audit mechanism (non-technical users). Telescope is for technical debugging only. Do not merge concerns. Audit source of truth = mutation caller, not observers.
**Consequences**: Two separate systems. Audit records stored in audit table with full metadata. Telescoped logs are technical only.
**Related**: AUDIT-001, AUDIT-003, MONITOR-001, REQ-AUDIT-001

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