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

| Laravel Abstraction | Responsibility | Convention |
|---------------------|---------------|------------|
| Form Request | Request validation and request-level authorization | Controller MUST call `$request->validated()` — never access raw input |
| Controller | HTTP orchestration | Thin — delegate to Actions. Owns page-specific view data only |
| Action/Service | Application/business logic | Self-audits for complex/shared logic |
| View Composer | Data every page in a group needs (app shell, shared forms) | `app/View/Composers/`, registered in `AppServiceProvider`. One lookup, all callers |
| Model | Persistence/model behavior | Owns its own derived value (`User::initials()`, `UserStatusEnum::badgeClass()`) |
| Policy | Authorization decisions | — |
| Resource | API serialization/presentation | — |
| Middleware | Cross-cutting request/application boundaries | — |
| Event | Domain/application event communication | — |
| Listener | Reaction to events | — |
| Job | Asynchronous/background processing | — |
| Notification | User notification abstraction | — |
| Observer | Only where model lifecycle behavior is genuinely appropriate; never as primary business/audit source of truth | — |

## Where View Data Comes From

A view reads variables. It never reaches for a model, a setting or a service.
Which layer supplies them:

| Kind of data | Home | Example |
|--------------|------|---------|
| App shell, same for every authenticated page | View Composer | `menuGroups`, `currentUserName`, password-expiry warning |
| Shared across a group of pages | View Composer | `roles`, the username/email change policy (`AccountOptionsComposer`) |
| Specific to one page, derived from the request | Controller | `users`, `counts`, `failedLoginCount`, `settings` |
| Derived from one record | The record itself | `User::initials()`, `UserStatusEnum::badgeClass()` |

Two consequences worth stating:

- A value the API also needs does not belong in a composer. It belongs in an
  Action, a Service, or the model/enum, and the composer reads it from there.
  A composer that exists only to hand one view a class string is a controller
  closure with a different name.
- Data that only one page reads does not belong in a composer either. A
  composer runs for every view it is registered against, so a per-page query
  registered broadly becomes a query on every admin page.


## Single Source of Truth

Do not duplicate state unnecessarily. Example: Role permissions are effective permissions derived from the user's role. Do NOT physically copy all role permissions into every user unless a future requirement explicitly requires it.

## ADR References

- [ADR-001: API-first architecture](../../planning/decisions.md#adr-001)
- [ADR-002: UI-independent core](../../planning/decisions.md#adr-002)
- [ADR-004: Role-derived permissions](../../planning/decisions.md#adr-004)
- [ADR-005: Separate active/inactive and locked/unlocked](../../planning/decisions.md#adr-005)