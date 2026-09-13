# Architecture Principles

## Core Principles

The application is designed as:

```
Core/Application
      ↓
API / Web / Mobile clients
      ↓
Replaceable UI
```

- The UI must NOT own business logic.
- The API/application boundary must remain usable if the UI is replaced by Blade, Vue, React, Mobile app, or another frontend framework.
- Business logic must remain in the application/domain layer.

## Separation of Responsibilities

| Laravel Abstraction | Responsibility |
|---------------------|---------------|
| Form Request | Request validation and request-level authorization |
| Controller | HTTP orchestration |
| Action/Service | Application/business logic |
| Model | Persistence/model behavior |
| Policy | Authorization decisions |
| Resource | API serialization/presentation |
| Middleware | Cross-cutting request/application boundaries |
| Event | Domain/application event communication |
| Listener | Reaction to events |
| Job | Asynchronous/background processing |
| Notification | User notification abstraction |
| Observer | Only where model lifecycle behavior is genuinely appropriate; never as primary business/audit source of truth |

## Single Source of Truth

Do not duplicate state unnecessarily. Example: Role permissions are effective permissions derived from the user's role. Do NOT physically copy all role permissions into every user unless a future requirement explicitly requires it.

## ADR References

- [ADR-001: API-first architecture](../planning/decisions.md#adr-001)
- [ADR-002: UI-independent core](../planning/decisions.md#adr-002)
- [ADR-004: Role-derived permissions](../planning/decisions.md#adr-004)
- [ADR-005: Separate active/inactive and locked/unlocked](../planning/decisions.md#adr-005)