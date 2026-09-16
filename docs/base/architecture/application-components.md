# Application Component Responsibilities

> Defines the responsibility model for every application-layer component.
> Every feature must conform to these boundaries.

## Component Responsibilities

### Controller

- HTTP orchestration only.
- Translate the HTTP request into an application operation.
- Return an HTTP response / Resource.
- Must not contain substantial business logic.
- Must not contain authorization decisions (use Form Request `authorize()`
  + Policy `can:` middleware).

### Form Request

- Request validation.
- Request-level authorization (`authorize()` method).
- Normalization/preparation of request data when justified.
- Must not contain large business workflows.

### Policy

- Authorization decisions (resource/user capability).
- Must not contain unrelated business workflows.
- One Policy per resource (Laravel convention).

### Action

- One focused application operation.
- Appropriate for a cohesive use case or mutation.
- Do NOT create Actions merely to wrap a single trivial model call
  (e.g., `$user->delete()`). Inline the call in the controller when the
  operation is genuinely trivial.

### Service

- Reusable application/domain workflow when multiple consumers or a
  meaningful orchestration justify it.
- Avoid "God Services".
- Do NOT create a Service class solely because a Services folder exists.

### Model

- Persistence, relationships, casts, scopes, and appropriate model-level
  behavior.
- Do NOT hide major business workflows in models. Model behavior should be
  limited to data access and simple invariants.

### Resource

- API serialization/presentation.
- Must NOT contain business logic.
- Must NOT perform mutations.

### Event

- Communicate that something happened.
- Use where decoupling, notifications, integrations, or secondary reactions
  benefit from it.
- Do NOT turn every method into an event.

### Listener

- React to events.
- Keep listeners focused on their single reaction.

### Job

- Asynchronous/background work.
- Email, notifications, exports, cleanup, long-running processing.

### Observer

- NOT the primary source of truth for business mutations.
- NOT the primary audit mechanism.
- Do NOT hide critical business logic inside observers.
- The mutation caller is responsible for explicit business behavior and audit
  logging.
- Observers may be used ONLY for genuinely appropriate model lifecycle
  concerns or explicitly justified fallback behavior.

### Repository

- Do NOT introduce repositories by default.
- Use ONLY when there is a concrete persistence
  abstraction/replacement/testing requirement that justifies one.
- Most Laravel applications do not need a repository layer — Eloquent
  already provides the persistence abstraction.

## Canonical Request Flow

```
HTTP/API Request
  ↓
Controller            (HTTP orchestration)
  ↓
Form Request          (validation + authorization)
  → Policy            (authorization — can also be checked here)
  ↓
Action / Service      (application/business logic)
  ↓
DB Transaction        (BEGIN)
  ↓
Model / persistence   (perform mutation)
  ↓
Audit record          (written within transaction, before commit)
  ↓
COMMIT
  ↓
dispatchAfterCommit   (events, jobs, notifications)
  ↓
Event → Listener      (if decoupled reaction needed)
  ↓
Job                   (asynchronous work, e.g. email)
  ↓
Resource / Response   (serialization + HTTP response)
```

The exact sequence may vary by use case — for example, a read operation may
skip the Action/Service and go Controller → Resource directly. A write
operation that does not need decoupled side effects may skip the Event.

## Source of Truth Rules

| Concern | Source of Truth |
|---------|-----------------|
| Business mutation | Mutation caller (Action/Service layer) |
| Audit Trail record | Mutation caller (Action/Service layer) |
| Authorization decision | Policy / authorization layer |
| API serialization | Resource / response layer |
| Asynchronous work | Job |
|| Technical observability | Logging / Telescope / Periscope / monitoring infrastructure |

### Key Implications

- **Audit is written by the mutation caller**, not by model observers. The
  observer is NOT the audit source of truth.
- **Audit records must be written within the same transaction as the mutation**
  and only committed if the mutation succeeds. A failed transaction produces
  no audit record.
- **Events/jobs that depend on committed database state must be dispatched
  after commit** (via `dispatchAfterCommit` or `DB::afterCommit()` callback).
- **Serialization happens at the Resource layer** — no business logic in
  Resources.

## When NOT to Introduce an Abstraction

- A single-implementation interface (no second consumer needs it).
- A factory that produces one product type.
- A Service class that only delegates to a model.
- A Repository over Eloquent (unless a concrete replacement need exists).
- A separate class/trait for logic that fits in a method.

## ADR References

- ADR-002: UI-independent core
- ADR-008: Audit Trail vs Telescope separation
- ADR-011: Soft delete strategy
- ADR-012: Cascade relationship strategy

## Related

- [Application Boundaries](./application-boundaries.md)
- [Folder Structure](./folder-structure.md)
- [Audit Trail](../features/audit-trail.md)
- [Monitoring](../features/monitoring.md)