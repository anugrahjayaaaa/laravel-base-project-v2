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

### Transaction Model

```
BEGIN TRANSACTION
  → perform mutation
  → related mutation(s) where applicable
  → write audit record where appropriate
COMMIT
  → dispatch after-commit events / jobs / notifications
```

### After-Commit Dispatch

Jobs, events, and notifications that depend on committed database state MUST
be dispatched after the transaction commits. Use:

- `dispatchAfterCommit()` on Jobs
- `DB::afterCommit(fn() => Event::dispatch(...))` for events
- Notifications via `dispatchAfterCommit` when routed through jobs

### Do NOT

- Dispatch an email job before the transaction commits. If the transaction
  rolls back, the email would reference a user/record that does not exist.
- Write an audit record before the transaction commits. A failed transaction
  must not produce a false-success audit entry.
- Assume an external side effect succeeded before the database transaction
  commits.

### Rollback Behavior

If a mutation fails:

1. The transaction rolls back.
2. No successful state is falsely represented.
3. No audit record claims the mutation succeeded.
4. The failure is observable through appropriate application/security logging
   (event/action name + failure status + request/correlation ID).

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