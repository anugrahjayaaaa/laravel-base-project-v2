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
`source`, `ip`, `user_agent` and `request_id` into every row. A hand-rolled `activity()` call
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

`Auditable::audit()` adds `source`, `ip`, `user_agent` and `request_id` to every row itself.
A caller passes only what is specific to its event, and may override a derived
value by including that key in `$properties` (a queued job passes
`source => system`). The single key is `source`; the former `channel` key is
retired and must not be reintroduced.

The trait is the single model-level audit entry point. Do NOT audit inside
observers — the action is responsible for explicit audit logging.

### Two rules the actions are expected to follow

Both shipped in Phase 10 (`P10-D8`, `P10-D9`) and both are pinned by tests, so a
future action that breaks either fails the suite rather than the incident review.

**Name the actor whenever the account is known.** A self-service event — someone
signing in, changing their password, saving their own profile — is audited with
the subject as the causer. `causer_id IS NULL` on those rows is only correct when
nobody was acting: a failed login, a scheduled sweep, an `InactivityLock` from
the timer. `AuditActorTest` enumerates them.

**Record what a destructive event destroyed.** `user.force_deleted` whose row
points at an id that no longer resolves is unactionable — the identity left the
database, so it has to be in the audit row. The delete, force-delete, restore and
email-change-cancel actions pass the identifying fields in `properties`
(`target_id`, `target_email`, `target_username`, and `reactivated` where the
restore also re-enabled a locked account).

### The type columns store aliases, not class names

`subject_type` and `causer_type` hold `user`, `role`, `feature_flag`,
`system_setting` — the keys of `App\Support\MorphMap` — because
`Relation::morphMap()` is registered in `AppServiceProvider`. Renaming a model
therefore does not orphan its history, and the export does not leak the
application namespace into an operator's file.

The map is **permissive**, not `enforceMorphMap()`, deliberately: enforcing makes
`getMorphClass()` throw on an unmapped class, which would fail a mutation from
inside its own transaction the first time a model gains `Auditable` without a map
entry. A model that later becomes auditable must be added to `MorphMap::ALIASES`
— `AuditMorphMapTest` fails the suite when one is missed.

The map is global, so it also governs `model_has_roles`, `model_has_permissions`,
`personal_access_tokens` and `notifications`. Phase 10 backfilled those too
(`2026_10_09_010000_backfill_morph_aliases.php`); an un-backfilled FQCN row in
one of them is *invisible* to a relation query rather than merely wrong, which
locks users out instead of mislabelling a row.

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

It derives `source`, `ip`, `user_agent` and `request_id` from the same `auditContext()` as
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

### What shipped (Phase 10, Group E)

```
POST /activity-logs/exports          audit.export + throttle:audit-export
    ↓ audit_exports row (pending, expires_at = now + 7 days)
GenerateAuditExport                  chunkById 500, fputcsv, local disk
    ↓ audit-exports/{user_id}/{uuid}.csv
row → ready + AuditExportReadyNotification (database + mail)
    ↓
GET /activity-logs/exports/{id}/download    audit.export + ownership check
```

| Piece | Where |
|---|---|
| Request / dispatch | `AuditExportRequestAction` |
| Job | `App\Jobs\GenerateAuditExport` |
| Row + status enum | `App\Models\AuditExport`, `App\Enums\AuditExportStatus` |
| Download + ownership | `AuditExportDownloadAction` |
| Completion notice | `AuditExportReadyNotification` |
| The download button in the inbox | `NotificationInboxAction::inbox()` → `$actions` |
| Nightly sweep | `routes/console.php` (`audit-export-retention`) |

Six decisions the implementation fixes, none of which the flow above states:

- **The filter travels with the request.** The export form posts the same
  filters the viewer had applied, and `Activity::scopeFiltered()` is the ONE
  implementation of that filter — copied into the job it is a second query that
  agrees with the first until somebody adds a filter to one of them, and then
  the file holds rows the page did not show.
- **The file is assembled before it is written.** A `php://temp` stream takes
  every row, and only a complete CSV reaches the disk. Writing rows into the
  destination as they arrive leaves a truncated file behind every failed
  attempt — and a half-written CSV opens, parses, and is quietly short.
- **The download route checks ownership, not just the permission.**
  `audit.export` answers "may extract"; it does not answer "may download extract
  #41". Past the window the answer is **410**, not 404: the file existed, and 404
  would send the requester hunting for a typo in a link that was correct.
- **A failed job marks its row `failed`.** A row stuck at `pending` is a spinner
  that never resolves; nothing anywhere would say the export died.
- **The sweep unlinks the file before deleting the row**, and never touches a row
  whose job is still `pending`. A row whose file is gone is a link that 410s;
  a file whose row is gone leaks disk forever, because nothing else knows the
  path existed.
- **The notice carries the link, so the file is reachable.** `toArray()` adds
  `action_label` + `url` (a **path**, not an absolute URL) beside the usual
  `subject` / `lines`, and the inbox renders it as a button. The first version
  shipped the notice without them: the file existed, the notification arrived,
  and no screen in the application led to it. `NotificationInboxAction` is what
  decides whether that stored URL is safe to link to — `data` is a JSON column
  its writer controls and the value reaches an `href`, so only a same-origin
  absolute path (`/…`, not `//host`) produces a button. That is an optional half
  of the notification payload, which is why it belongs to every notification and
  not to an export-specific branch in the inbox.

## API Surface

The reader and the export exist on **both** channels. They share the Actions, the
Form Requests and the rate limiter; only the serialization differs.

| Operation | Web | API |
|---|---|---|
| List | `GET /activity-logs` | `GET /api/v1/activity-logs` |
| Detail | `GET /activity-logs/{activity}` | `GET /api/v1/activity-logs/{activity}` |
| Request export | `POST /activity-logs/exports` | `POST /api/v1/activity-logs/exports` → **202 + row** |
| List own exports | (inside the index page) | `GET /api/v1/activity-logs/exports` |
| Download | `GET /activity-logs/exports/{export}/download` | `GET /api/v1/activity-logs/exports/{export}/download` |

Three things worth knowing before writing a client:

- **The API reuses the `audit-export` limiter.** HTTP is not a way to get a
  larger allowance than a browser gets; both are capped by
  `system_settings.audit_export_rate_limit_per_hour`.
- **`download_url` appears only when the file exists.** It is the model's own
  `isDownloadable()`, so the payload cannot disagree with the route about what is
  available. The disk `path` is never emitted — that is a private-storage
  location, and it keeps working in a client's head after the sweep unlinks the
  file.
- **Ownership is checked before expiry**, so another operator's expired export
  answers 403 rather than 410. 410 means *your* file existed and is gone.

`{activity}` is constrained to a number on both channels: `/activity-logs/exports`
is one segment shorter than the download route, and an unconstrained parameter
would swallow it depending on registration order.

The audit table holds every actor's IP and user agent, so `audit.view` is
access to behavioural data about staff — not a generic read permission. Neither
channel may widen it.

## Security

- Read-only through UI and API alike.
- Export requires `audit.export` permission on both channels.
- View requires `audit.view` permission.
- The download route checks ownership before expiry, on both channels.
- Never store sensitive data in plaintext in audit logs.
- Consider log rotation/archiving for long-term retention.

## ADR References

- ADR-008: Audit Trail vs Technical Observability separation
- ADR-001: API-first architecture — the reason the reader is not web-only