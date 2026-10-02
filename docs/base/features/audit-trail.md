# Audit Trail

## Overview

Use an established audit package rather than building from scratch.

Create an application-level audit abstraction so the application is not tightly coupled to the package API.

Audit Trail is NOT interchangeable with Laravel Pulse.

## Purpose

Audit Trail is intended for:
- Administrators
- Security users
- Operational users
- Non-technical users

Audit records must be read-only through the UI.

## Capabilities

| Capability | Description |
|-----------|-------------|
| View | Audit list view |
| Detail view | Full audit record details |
| Search | Full-text search |
| Filter | Filter by actor, action, date range, etc. |
| Pagination | Standard paginated results |
| Export | Asynchronous export of audit records |

## Metadata

Each audit record must capture:

| Field | Description |
|-------|-------------|
| Actor | Who performed the action (user_id) |
| Action | What was done (action type) |
| Subject | What was affected (subject_type, subject_id) |
| Before | State before change |
| After | State after change |
| Metadata | Additional context (request_id, IP, user agent) |
| IP | Client IP address |
| User Agent | HTTP user agent |
| Request/Correlation ID | Traceability to request |
| Timestamp | When it happened |
| Lock reason | Where relevant |
| Source/context | Where relevant |

## Source of Truth

Audit source of truth: **mutation caller**.

Do NOT make observers the primary audit mechanism.

Audit logging should be done explicitly in the Action/Service layer where the
mutation occurs, not passively via model observers.

See [Application Components](../architecture/application-components.md) for
the component responsibility model and [Application Boundaries](../architecture/application-boundaries.md)
for transaction/after-commit rules.

## Model Audit (Auditable Trait)

The `User` model carries an `Auditable` trait (`App\Models\Concerns\Auditable`)
that wraps spatie/activitylog. **Actions** call `$user->audit()`, inside their
own transaction:

```php
// In the action that performs the mutation, inside DB::transaction:
$user->audit('user.locked', $causer);
$user->audit('auth.login_failed', null, ['identifier' => $identifier]);
```

- `performedOn` = the model itself (`$this`)
- `causedBy` = explicit causer argument (admin / system)
- `event` = snake_case event name (e.g. `user.locked`, `user.deactivated`)
- `properties` = event-specific values only

Controllers MUST NOT call `audit()` for a mutation an action already performs —
that is a duplicate record for one change. See
[Application Boundaries](../architecture/application-boundaries.md) §
Action-First Audit Logging Standard.

## Reference implementation: the Role feature

Role is the migrated, complete example. Copy its shape. A model that is
audited carries the trait:

```php
// app/Models/Role.php
use App\Models\Concerns\Auditable;

class Role extends \Spatie\Permission\Models\Role
{
    use Auditable;
}
```

and every mutating action writes its own row inside its own transaction:

```
app/Actions/Concerns/PersistsRole.php   $role->audit($event, $causer, [...])   ← created / updated
app/Actions/V1/Role/RoleDeleteAction.php        $role->audit('role.deleted', ...)
app/Actions/V1/Role/RoleRestoreAction.php       $role->audit('role.restored', ...)
app/Actions/V1/Role/RoleForceDeleteAction.php   $role->audit('role.force_deleted', ...)
app/Actions/V1/Role/RoleAssignAction.php        $user->audit('user.roles_assigned', ...)
```

Both role controllers (`Web\V1\RoleController`, `Api\V1\Role\RoleController`) contain
**no audit call at all**. They validate, authorise, call the action and shape the
response. That is the finished state, not an intermediate one.

Three details worth copying:

1. **Properties say what changed, not that something changed.** `role.deleted`
   logs `revoked_users` and `revoked_permissions`; `role.created` /
   `role.updated` log the granted permission set. "A role was updated" is not
   actionable during an incident. A row the log already cannot use is a row
   nobody will read.
2. **The action is the only writer.** A shared `PersistsRole` concern holding
   `$role->audit(...)` for two actions is the *same rule* applied twice — that
   is reuse, not a second mechanism. What is NOT acceptable is a per-domain
   audit trait (`AuditsUserState`, `AuditsAuthActivity`) that assembles its own
   context: that is how two writers for one event get created. One writer is
   `Auditable`.
3. **A bulk handler adds no aggregate row.** `RoleBulkActionHandler::getAuditEvent()`
   returns `''` because looping the per-role actions already produced one row
   per role. A handler that also wrote a summary row would double every record.

### Adding audit to a new feature

1. Add `Auditable` to the model. That is the whole setup.
2. Write `$model->audit($event, $causer, $props)` inside the action's
   `DB::transaction`, immediately after the state change.
3. Pass `$causer` through from the controller. An action with two callers (an
   admin path and a self-service path) writes different events per caller, so
   the causer must be an argument rather than read from the request.
4. Record the properties an incident review would need. Not the model's fields —
   the *decision*.
5. Grep the controllers for the same event name. A hit means a duplicate row.

Read-only actions write nothing. `RoleIndexAction` is the example.

### "But my thing is not an Eloquent model"

This comes up, and the answer has been wrong twice. **"It is not a model" is not
a reason to reach for `activity()` — it is a reason to check whether the state
has a table.**

Feature flags were the case: Pennant owns its store, so the code claimed there
was nothing to hang a subject on and wrote the event by hand. But the state
lives in a real `features` table, one row per flag, and that row had no class
attached. The audit row ended up with a causer and properties but no subject —
the viewer renders `#`, and nobody can inspect what changed. So:

**Grep the migrations for the table before you accept the excuse.**

```bash
grep -rn "Schema::create" database/migrations/ | grep -i <thing>
```

If the state is persisted in a table, add a model over it and give it the
trait. Do **not** invent a throwaway table whose only purpose is to be an audit
target — that one really is a new row for no reason. `App\Models\FeatureFlag` is
read-only by design: the package still owns every write, the model exists to give
the row an identity.

A genuine subject-less event is one where **no state is persisted at all** — a
failed login attempt, a password-reset request. Those are already recorded in
tables built for them (`failed_login_attempts`, `password_reset_tokens`), so the
extra activity row would be the same fact twice in the wrong shape. Do not write
it, and do not invent a subject for it.

**Whatever you do, there is one entry point.** `Auditable::auditContext()` merges
`source`, `ip` and `user_agent` into every row. A hand-rolled `activity()` call
that omits them is not a stylistic difference — it is the only row in the table
that cannot be traced back to a request.

### Migration status: complete

Every audit write is in an action, a service or a job. No controller writes an
audit row, and there is no helper left for one to call — `Controller::audit()`
and `Controller::bulkAudit()` were deleted, so a controller cannot reach for
them even by mistake.

| Call site | State |
|-----------|-------|
| `Web\V1\RoleController`, `Api\V1\Role\RoleController` | done — no audit call |
| User, System Setting, Auth actions | done — audited in the action |
| `Web\V1\ProfileController`, `Api\V1\ProfileController` | done — the actions own all 7 rows |
| `Web\V1\UserController`, `Api\V1\User\UserController` bulk | done — loops the per-user action |
| `FeatureToggleAction`, `FeatureBulkToggleAction` | done — `FeatureFlag` model over the `features` table |

**One `activity()` call remains in `app/`, and it is the entry point:**

```bash
rg -n 'activity\(' app -g '!*.md' | grep -v 'Activity('
```

- `app/Models/Concerns/Auditable.php:44` — the trait's own `audit()`.

The other two hits are prose: `FeatureToggleAction.php:92` and
`FeatureFlag.php:15` both explain what an *earlier* hand-rolled call did wrong.
Check what a hit actually is before treating it as a writer; counting lines and
asserting the number is how a false "no activity() calls remain" gets written
into a doc — and equally how a stale "two calls remain" survives.

The full finding-by-finding record, including the transaction gaps found in
follow-up and the two files that were invisible to the suite, is in
[`docs/qa/remediation-tracker.md`](../../qa/remediation-tracker.md).

`Auditable::auditBulk()` does not appear here because it is a raw `DB::table()`
insert, not an `activity()` call. **Any hit that is real code is a second
writer.**

A grep that still finds an event name in a controller is the check for step 5.
Two of the bugs found during this migration were exactly that: one event
written by both an action and a controller (two rows per resend), and one
`getAuditEvent()` that contradicted its own docblock.

### Context is automatic

`Auditable::audit()` adds `source`, `ip` and `user_agent` to every row itself.
A caller passes only what is specific to its event, and may override a derived
value by including that key in `$properties` (a queued job passes
`source => system`). The single key is `source`; the former `channel` key is
retired and must not be reintroduced.

The trait is the single model-level audit entry point. Do NOT audit inside
observers — the action is responsible for explicit audit logging.

## Bulk mutations

A bulk operation is not a different kind of audit. It is N mutations, so it
produces N rows — one per subject, each written by the same single-subject
action in the same transaction. There is no aggregate row on top.

```php
// UserBulkActionHandler / RoleBulkActionHandler: loop the action.
foreach ($users as $user) {
    $this->deleteAction->run($user, $causer);   // the action writes user.deleted
}
```

`Auditable::auditBulk()` exists for the cases where a caller genuinely has a
list and one insert is correct — a few hundred rows in one statement rather
than N builder round trips inside a transaction that already holds the locks:

```php
User::auditBulk('role.bulk.assigned', $records, $causer);
```

It derives `source`, `ip` and `user_agent` from the same `auditContext()` as
`audit()`. It has no `type` parameter for a caller to get wrong — the earlier
`Controller::bulkAudit()` took one, no call site passed it, and every bulk row
taken through the API recorded `source => web`.

Prefer the loop. Reach for `auditBulk()` only when a single insert is
measurably the reason it is not slow.

**What the loop costs, measured.** The user bulk path is ~2.0 queries per user
at n=100 (one `UPDATE`, one `INSERT`), linear, and it grew from one batch
`UPDATE` when `lock`/`unlock`/`activate` were routed through their actions. That
is the trade, and it was taken deliberately: a batch `UPDATE` cannot run the
guard each action applies, so it would happily lock a user that
`UserLockAction::validate()` refuses.

`auditBulk()` would remove the `INSERT` but not the `UPDATE` — the guard is
per-user — and would add a second mutation path to keep correct. At the 10-row
selection cap (`BulkUserRequest::MAX_SELECTION`) neither is worth it. Revisit
only if a caller genuinely needs hundreds of rows in one request, and then the
right answer is a queued job, not a faster loop.

## Transaction Boundaries

- Audit records must be written **within the same database transaction** as
  the mutation, and **before the COMMIT**.
- A successful mutation produces one audit record.
- A failed transaction (rollback) produces **no** audit record and no
  "successful mutation" claim. The failure must be observable via
  application/security logging instead (e.g. `user.update.failed` with
  exception, request_id).
- Audits that capture before/after state must reflect the **committed** state
  only.

## Asynchronous Export

Conceptual flow:

```
User requests export
    ↓
Create export job
    ↓
Queue
    ↓
Generate file
    ↓
Store in private storage
    ↓
Notify user
    ↓
Temporary download
    ↓
Expire/delete export file
```

- Audit exports must be asynchronous.
- Audit exports must use private storage.
- Export files have lifecycle/expiration policy.

## Security

- Read-only through UI.
- Export requires `audit.export` permission.
- View requires `audit.view` permission.
- Never store sensitive data in plaintext in audit logs.
- Consider log rotation/archiving for long-term retention.

## ADR References

- ADR-008: Audit Trail vs Technical Observability separation