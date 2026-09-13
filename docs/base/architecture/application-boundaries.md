# Application Boundaries

## Core Principle

The UI must NOT own business logic. The API/application boundary must remain usable regardless of UI replacement.

## Layer Mapping

```
Request
  ↓
Controller (HTTP orchestration)
  ↓
Action/Service (application/business logic)
  ↓
DB transaction
  ↓
Persist changes
  ↓
Commit
  ↓
Dispatch after commit
  ↓
Queue/event/notification
```

## Transaction Boundaries

- Do not dispatch side effects prematurely when transactional consistency matters.
- Side effects (queue jobs, events, notifications) are dispatched after commit.

## Security Boundary

- Security must be enforced at the backend/application boundary.
- Do not rely on UI visibility as a security mechanism.
- Input validation at trust boundaries.
- Do not expose internal details (stack traces, secrets, credentials) in responses.

## Feature Availability

Three conceptual layers:
1. **Authentication** — who are you?
2. **Authorization** — what can you do?
3. **Feature Availability** — is this capability available?

Feature availability must be enforced at the backend/application boundary. UI hiding is not security.

## Settings Enforcement

- Technical infrastructure settings remain in config files.
- Operational application settings may be managed from the dashboard.
- Settings changes: validation, authorization, audit, cache invalidation.