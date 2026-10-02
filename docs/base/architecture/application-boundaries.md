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
- Treat audit logging as an after-commit side effect (dispatch audit as a
  post-commit job/event). Audit records must be written WITHIN the same
  transaction as the mutation (before COMMIT), persisting only on successful
  commit. A failed transaction must not produce a false-success audit entry.
- Assume an external side effect succeeded before the database transaction
  commits.

### Rollback Behavior

If a mutation fails:

1. The transaction rolls back.
2. No successful state is falsely represented.
3. No audit record claims the mutation succeeded.
4. The failure is observable through appropriate application/security logging
   (event/action name + failure status + request/correlation ID).

## Action-First Audit Logging Standard

Every state mutation is audited by the **Action that performs it**, inside that
action's own transaction. This is the single rule that gives complete audit
coverage for every entry point — web, API, jobs, commands and bulk handlers — with
one write site per mutation.

### The rule

1. An Action class that mutates state (create / update / delete / bulk)
   MUST write its own audit record inside its `DB::transaction`, via the
   `Auditable` trait: `$model->audit($event, $causer, $properties)`.
2. An Action class that only reads MUST NOT write audit records.
3. **Controllers MUST NOT call `audit()` or `bulkAudit()` for a mutation that an
   Action already performs.** Controllers orchestrate: validate, authorise,
   call the action, shape the response. A controller-level audit call on top of
   the action's is a duplicate record for one mutation.
4. A bulk handler MUST NOT write its own audit rows for mutations it delegates to
   actions. Loop the action — one record per subject, same properties, same
   transaction — instead of a second aggregate writer. `Auditable::auditBulk()`
   exists for the rare case where one insert is genuinely correct, and derives
   the same context as `audit()`.
5. Properties MUST record the decision, not restate the model. `role.deleted`
   logs how many accounts lost access; `role.created` logs the permission set
   granted. "A role was updated" cannot be used during an incident review.

**Worked example: the Role feature** — model carries `Auditable`, each action
writes its own row in its own transaction, both role controllers contain no
audit call. See [Audit Trail § Reference implementation](../features/audit-trail.md#reference-implementation-the-role-feature)
for the file-by-file shape and the per-feature migration status.

### `Auditable::audit()` is the only entry point

There is one signature for writing an audit record, and one implementation of it:

```php
$model->audit('user.deleted', $causer);                  // nothing extra to say
$user->audit('auth.login_failed', null, ['identifier' => $id]);
$user->audit('system_job.ran', null, ['source' => 'system']);  // override derived
```

Callers pass **only what is specific to their event**. Everything that answers
*where did this come from* is derived inside `Auditable::audit()` and added
automatically:

| Property | Derived as | Override by |
|---|---|---|
| `source` | `web` or `api`, from the request path | pass `source` in `$properties` |
| `ip` | `request()->ip()` | pass `ip` |
| `user_agent` | `request()->userAgent()` | pass `user_agent` |

This is why there is no second helper trait. The alternative was tried and it
drifted: each caller assembled its own context, `request()->is('api/*') ? 'api'
: 'web'` was copied across six files, and the same value was written under two
key names (`source` and `channel`) with only some call sites carrying a user
agent — so two rows describing the same kind of event could not be compared.
**`channel` is retired**; the single key is `source`.

A caller-supplied value wins over the derived one, which is how a queued job
states `source => system` rather than being mislabelled `web` for having no
request in scope. `App\Jobs\Concerns\AuditsSystemActivity` relies on this.

### Events with no state change

Some auth events describe a **request**, not a mutation: `auth.login_failed`,
`auth.account_locked`, `auth.verification_resent`. There is no transaction for
them to write inside, and forcing one buys nothing — there is no state that could
roll back and leave an orphan row. They are still audited from the action that
handles them, through the same `$model->audit()`; the transaction rule applies to
mutations.

### No audit row for an event that changed nothing about the subject

An action that finds no subject does **not** write a subject-less row. A login
attempt against an address that is not an account is already recorded, keyed by
identifier and IP, in `failed_login_attempts`; a reset request for an unknown
address is recorded by `password_reset_tokens`. Writing an `activity()` row with
no subject would be the same fact a second time, in the wrong shape — and it
needs a second implementation of the context, which is exactly the drift this
standard removes.

So the two writers that remain are the only two:

- `Auditable::audit()` — the normal path, on any model using the trait. The
  feature-flag actions are the documented exception: a Pennant flag has no Eloquent
  model, so `FeatureToggleAction` writes through `activity()` and takes its
  context from `User::auditContext()`.
- `App\Jobs\Concerns\AuditsSystemActivity` — jobs and commands, which have no
  authenticated causer. It stamps `causer => SYSTEM` rather than fabricating a
  user, and delegates its context to `User::auditContext()` like everything else.

`Controller::audit()` still exists for the Profile controllers only and is
tracked as AUD-006; it delegates to `User::auditContext()` so its rows are
identical, and it is deleted when Profile is migrated. Do not add new callers.

### Why the Action, not the controller

The same mutation is reachable from more than one place. `UserDeleteAction` is
called by the web row button, the API endpoint, and the bulk bar. Auditing in the
action means those paths cannot drift apart and cannot double-count; auditing in
the controller means every new caller has to remember to add the call, and the
bulk path has to be fixed separately. This is the same reasoning as DEP-003's
transaction rule, applied to ownership.

### Bulk mutations

`BulkActionHandler::executeBulk()` loops the per-entity action, so the bulk bar
inherits the action's audit for free:

```php
'delete' => $users->each(fn (User $user) =>
    app(UserDeleteAction::class)->run($user, auth()->user())),
```

Where an action does NOT audit its mutation, the handler's
`getAuditEvent()` still returns the aggregate event and the controller writes it
(`UserBulkActionHandler` — lock / unlock / activate / deactivate / restore /
force_delete mutate columns directly and have no action to audit them). Return
an empty event name once the action audits itself; the controller skips the
aggregate write on a falsy event. That is the mechanism behind
`RoleBulkActionHandler`, which audits per role and returns no aggregate row.

### When adding a mutation

- New action → write the audit record in the action, before the transaction
  closes.
- Existing action → move the controller's `audit()` call into it, then delete
  the controller call. Do not leave both.
- Read-only action → no audit.

`tests/Feature/Audit/ActionFirstAuditTest.php` pins the three properties this
rule guarantees: one mutation writes exactly one record, every subject in a bulk
mutation is recorded, and a rollback leaves no orphan audit row.

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