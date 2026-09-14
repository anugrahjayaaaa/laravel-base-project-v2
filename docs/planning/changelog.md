# Changelog

> Records meaningful architecture/planning changes. Do not record meaningless edits.

## Format

Each entry contains:
- **Date**
- **Change** (what was changed)
- **Reason** (why the change was needed)
- **Impact** (what's affected)
- **Related** (ADR/task/requirement ID)

---

## 2024-01-01 — Initial Architecture & Planning

|| Field | Value |
||-------|-------|
|| **Date** | 2024-01-01 |
|| **Change** | Created initial architecture documentation and planning system for Laravel Base Project v2 |
|| **Reason** | Need structured documentation/planning foundation before any implementation |
|| **Impact** | All subsequent phases; this establishes the foundation |
|| **Related** | P0-001 through P0-011 (Phase 0 tasks) |

### Components Created

|| Component | Description | Docs |
||-----------|-------------|------|
|| Architecture Principles | Core principles, separation of responsibilities, single source of truth | architecture/principles.md |
|| Application Architecture | High-level layer diagram, API-first, dependency rules | architecture/architecture.md |
|| Application Boundaries | Transaction boundaries, security boundary, feature availability | architecture/application-boundaries.md |
|| Folder Structure | Recommended directory layout for Laravel app | architecture/folder-structure.md |
|| Dependency Rules | Package governance, Redis compatibility, secret management | architecture/dependency-rules.md |
|| Naming Conventions | Code, DB, table, column naming standards | architecture/naming-conventions.md |
|| ADRs (12) | Architecture Decision Records (see decisions.md) | architecture/architecture-decisions.md |
|| Security Baseline | Overall security posture, headers, secrets, error handling | security/security-baseline.md |
|| Authentication | Login, session, lockout, verification requirements | security/authentication.md |
|| Authorization | RBAC, superadmin protection, feature availability | security/authorization.md |
|| Password Security | IM8 policy, history, expiration, reset flows | security/password-security.md |
|| Session Security | Token strategy, single-session-per-device, revocation triggers | security/session-security.md |
|| Rate Limiting | Per-endpoint limits, Redis compatibility, separation from failed-login | security/rate-limiting.md |
|| Web Security | HTTPS, HSTS, CSP, CSRF, CORS, security headers | security/web-security.md |
|| Data Protection | Encryption, storage, backup, retention | security/data-protection.md |
|| API Architecture | Versioned API, resources, response conventions, error contract | api/api-architecture.md |
|| API Versioning | Versioning strategy, deprecation policy | api/versioning.md |
|| API Response Contract | Envelope structure, pagination, filtering, sorting | api/response-contract.md |
|| API Error Contract | HTTP status codes, error codes, security in errors | api/error-contract.md |
|| API Documentation | OpenAPI approach, required sections, tools | api/documentation.md |
|| Database Conventions | ID strategy, naming, constraints, soft-delete, enums | data/database-conventions.md |
|| Soft Delete Strategy | When to use, decision matrix, implementation | data/soft-delete.md |
|| Relationships | Eloquent patterns, loading strategy, constraints | data/relationships.md |
|| Concurrency | Transaction boundaries, race conditions, retry | data/concurrency.md |
|| Queue | Database queue, Redis compatibility, job patterns | infrastructure/queue.md |
|| Redis Compatibility | When Redis is optional/required, abstractions | infrastructure/redis-compatibility.md |
|| Cache | Cache strategy, invalidation, TTL | infrastructure/cache.md |
|| Storage | Disk strategy, public/private/tmp, cloud | infrastructure/storage.md |
|| Logging | Channels, levels, correlation IDs, security | infrastructure/logging.md |
|| Observability | Monitoring structure, AUDIT vs Telescope separation | infrastructure/observability.md |
|| Backup & DR | Backup types, verification, restore procedure, RPO/RTO | infrastructure/backup-disaster-recovery.md |
|| User Management | User state model, operations, lifecycle | features/user-management.md |
|| Registration | Configurable registration, default role | features/registration.md |
|| Roles & Permissions | RBAC via Spatie, permission model, superadmin | features/roles-permissions.md |
|| Feature Flags | Feature availability enforcement, backend-first | features/feature-flags.md |
|| Settings | Structured settings, two-layer config, change management | features/settings.md |
|| Notifications | Channels, categories, mail config, queue integration | features/notifications.md |
|| Audit Trail | Audit package, abstraction, async export, metadata | features/audit-trail.md |
|| Monitoring | Audit/logs/Telescope/health check separation | features/monitoring.md |
|| Testing Strategy | Types, principles, coverage matrix | testing/testing-strategy.md |
|| Testing Matrix | Coverage grid per feature | testing/testing-matrix.md |
|| Definition of Done | 13 criteria checklist | testing/definition-of-done.md |
|| Deployment | Deployment steps, CI/CD, rollback, zero-downtime | operations/deployment.md |
|| Environment | Env vars, config files, secrets management | operations/environment.md |
|| Retention | Data type-specific retention schedule | operations/retention.md |
|| Troubleshooting | Common issues, debugging tools, log locations | operations/troubleshooting.md |
|| Planning README | Planning system overview | planning/README.md |
|| Requirements | Functional & non-functional requirements | planning/requirements.md |
|| Feature Matrix | Feature coverage map with priority/phase | planning/feature-matrix.md |
|| Implementation Roadmap | Phases 0-17, dependency map | planning/implementation-roadmap.md |
|| Task Tracker | Full task list (100+ items) with status | planning/task-tracker.md |
|| Dependency Map | Feature-level dependency graph | planning/dependency-map.md |
|| QA Tracker | All QA scenarios organized by feature | planning/qa-tracker.md |
|| AI Execution Guide | Rules for AI agents working on repo | planning/ai-execution-guide.md |

---

## 2025-09-14 — Logging Strategy Specification

|| Field | Value |
||-------|-------|
|| **Date** | 2025-09-14 |
|| **Change** | Rewrote `infrastructure/logging.md` with the full Application Logging Requirements spec: four-tier logging model (Audit Trail / Application Logs / Server Logs / Telescope separation), failure classification (expected vs unexpected), structured event/action names, context envelope, transaction-failure semantics, request/correlation ID propagation, centralized exception handling, logging privacy/redaction, and retention. Added ADR-013 and logging QA scenarios (QA-LOG-001 through QA-LOG-008) to the QA tracker. |
|| **Reason** | Establish single source of truth for Phase 1 implementation; prevent ad-hoc logging that conflates audit/application logs, logs sensitive data, or creates false audit records on rollback. |
|| **Impact** | All subsequent phases that emit logs or audit records; Phase 1 (correlation ID middleware), Phase 10 (audit trail), Phase 11 (Telescope). |
|| **Related** | ADR-013, FOUND-008, AUDIT-001/AUDIT-003, MONITOR-001, RETAIN-001, QA-LOG-* |