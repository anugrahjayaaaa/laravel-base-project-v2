# Application Architecture

## High-Level Architecture

```
Core/Application
      ↓
API / Web / Mobile clients
      ↓
Replaceable UI
```

## Layer Responsibilities

| Layer | Responsibility |
|-------|----------------|
| **Domain** | Core business logic, entities, value objects |
| **Application** | Use cases, orchestrates domain, coordinates services |
| **Interface** | Adapters for external systems (DB, APIs, queues) |
| **Presentation** | HTTP controllers, resources, form requests |
| **UI** | Blade / Vue / React / Mobile (replaceable) |

## API-first Design

- All core capabilities must have a clean API boundary.
- API contracts must be designed so frontend/mobile developers can consume them independently from Laravel internal implementation.
- Version: `/api/v1/...` with future `/api/v2/...`
- Application/domain logic may be shared when behavior is identical.
- When behavior changes substantially, create a separate Action/Service implementation.
- V1 must remain stable when V2 is introduced.

## UI Independence

- Business logic must not depend on Blade, Vue, React, or any specific UI.
- UI hiding is not security — feature availability enforced at backend boundary.

## Dependency Rules

- Technical infrastructure settings remain in config files.
- Operational application settings may be managed from the dashboard.
- Business logic must not depend directly on Redis — use Laravel abstractions.
- Secrets belong in environment/deployment configuration.

## ADR References

- ADR-001: API-first architecture
- ADR-002: UI-independent core
- ADR-003: Database queue with Redis compatibility