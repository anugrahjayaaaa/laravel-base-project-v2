# Base Documentation

Reusable foundation documentation for the Laravel Base Project.

> **Base vs Custom**: Base docs define the reusable foundation. Custom docs define project-specific features and must never modify the meaning of Base docs. See [Planning README](../planning/README.md).

## Sections

| # | Category | Purpose |
|---|----------|---------|
| 1 | [Architecture](../base/architecture/principles.md) | Core principles, boundaries, conventions |
| 2 | [Security](../base/security/security-baseline.md) | Auth, sessions, rate limiting, web security |
| 3 | [API](../base/api/api-architecture.md) | Versioned API, resources, contracts, docs |
| 4 | [Data](../base/data/database-conventions.md) | DB strategy, soft deletes, relationships, concurrency |
| 5 | [Infrastructure](../base/infrastructure/queue.md) | Queue, Redis, cache, storage, backup, logging |
| 6 | [Features](../base/features/user-management.md) | User mgmt, auth, RBAC, feature flags, settings |
| 7 | [Testing](../base/testing/testing-strategy.md) | Test matrix, scenarios, definition of done |
| 8 | [Operations](../base/operations/deployment.md) | Deployment, environment, retention, troubleshooting |

## Key Architectural Principles

1. **API-first** — all core capabilities have clean API boundaries
2. **UI-independent** — business logic never depends on Blade/Vue/React
3. **Secure by default** — security enforced at backend boundary
4. **Separation of responsibilities** — FormRequest → Controller → Action → Model → Policy → Resource
5. **Single source of truth** — no unnecessary state duplication

## Scope Boundaries

**Must NOT include**: Plan system, License system, Billing, Subscription, project-specific business logic, domain modules, customer-specific workflows.

Those are added later as separate packages/modules.