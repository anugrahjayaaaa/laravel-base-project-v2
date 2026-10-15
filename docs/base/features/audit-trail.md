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

## Metadata provenance — real inside a request, null outside

Every row carries `source`, `ip`, `user_agent` and `request_id`, all derived
once in `Auditable::auditContext()` rather than per call site.

**Inside an HTTP request** they are real. **Outside one they are `null`** — and
that is deliberate, not a gap. A queued job and an artisan command both run with a
synthetic request whose values are *defaults, not facts*: `is('api/*')` is false,
so a scheduler's row would say `source => web`, and `ip()` returns `127.0.0.1`.
A record an incident review is supposed to trust cannot carry a default.

`request_id` is bound per request by `GenerateRequestCorrelationId` and echoed on
the `X-Request-ID` header, so an operator holding a 403 body with
`meta.request_id` in it can tie that request to a row.

`App\Jobs\Concerns\AuditsSystemActivity` still exists for the case the derivation
cannot decide: a `sync`-queued job runs inside the web request that dispatched
it, and only the caller knows it was the scheduler. Its value wins — the context
is merged first, so a caller's `source` overrides the derived one.

## Reader

The read side, for whoever the audit trail is shown to. The write side below
documents how rows get there; this is how they come out, and the two share one
filter implementation so a file is never a second approximation of a list.

### Reading is not extracting

`audit.view` opens the trail; `audit.export` takes it out of the application.
Two permissions because they are two acts — one permission would let a read-only
auditor pull a copy of everything they can already see. And `audit.view` is not
a generic read permission at all: the table holds every actor's IP address and
user agent for every change, so it is access to behavioural data about staff.

### Filters

`search` (LIKE over `event` and `description`), `event`, `source`, `causer_id`,
`subject_type`, `date_from`, `date_to`, plus `sort`, `direction` and `per_page`.

`sort` and `direction` are the one pair that reaches the query as an
**identifier** rather than a value — `orderBy()` takes a column name, and no
bound parameter can stand in for one — so they are `Rule::in()` allowlists, not
string rules. Everything else is a bound value: a quote in `event` is data, and
the row simply matches nothing.

`search` is the one LIKE in the module. A caller can supply `%` and widen the
match; it cannot execute anything, but an export filtered by `search=%` is the
whole table, which is worth knowing before an operator assumes the box narrows
anything.

### The filter option lists are cached — the rows are not

The filter bar's four dropdowns (event names, sources, subject types, actors) are
derived from the whole table rather than hand-typed in Blade, because a
hand-typed list goes stale the moment an action adds an event. They are also
four `DISTINCT` queries on every page load, and one of them —
`DISTINCT json_extract(properties, '$."source"')` — is the single query in this
module that **no index can serve on any engine**.

So the option lists are cached (`AuditIndexAction::cacheKeys()`, TTL 1 hour) and
the **row list is not**. That split is deliberate and it is the same shape
`UserIndexAction` uses for its counts: a cached option list is derived from
every row rather than from a filter, so it is identical for every viewer, while a
cached row list would hand one viewer's rows to everyone else and would hide the
row that was written a minute ago — the one an investigation is looking for.

`Auditable::audit()` and `auditBulk()` bust the keys on every write, deferred to
`DB::afterCommit` for the reason `UserIndexAction::bustCache()` documents. Those
two methods are the only places a row enters the table from application code,
which is what makes one call the whole invalidation story.

**Consequence:** a new event name appears in the dropdown as soon as it is
written, because the write busts the cache. If a future writer bypasses
`Auditable` and inserts a row directly, the dropdown can lag by up to the TTL —
the rows themselves are still listed and still filterable by `event` in the URL.

Measured: option lists −90% at 2 000 rows and −96% at 20 000; index page
−19%, 10 queries → 6.

### Reading writes nothing

A row recording "an administrator read the audit trail" would be a row in the
trail being read. Both channels hold to that, and both are pinned:
`AuditPentestTest` probes all four verbs on both surfaces and asserts the row
count is unchanged.

### The table is append-only

No checkbox column, no bulk bar, no edit control, and
`App\Models\Activity` is `$guarded = ['*']` — a mass-assignment attempt throws
rather than silently dropping the attribute. That is structural, not a
convention: `AuditReadOnlyTest` walks the application for any path that writes
through the model, and the pentest tries every verb on every route.

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

### The file is ordered by `id`, not by the requested sort column

The export streams the filtered set with `chunkById`/`chunkByIdDesc`, and that
helper cursors on the primary key: it filters `WHERE id > <last id of the
previous page>` and forces the ordering to `id ASC` (or `id DESC`). Any other
leading sort column survives the rewrite as the query's primary key while the
cursor still walks `id`, and the two disagree — the second page re-reads part of
the first and the tail is never reached.

Shipped behaviour before 2026-10-13 was `ORDER BY created_at DESC, id DESC`
chunked by `id`: on 1200 rows it produced a file of 999 lines containing 500
distinct rows. A compliance extract with silently dropped rows is the worst
failure this feature has, so the export now orders by `id` alone.

On an append-only audit table the two orders are the same sequence — `id` is
already the tiebreaker `ActivityQueryRequest::SORTABLE` sorts on, precisely
because a bulk write gives many rows one `created_at`. So the file is still the
sequence the operator was looking at; what changed is that the cursor agrees
with it. `$sort` was removed from `GenerateAuditExport` and from
`AuditExportRequestAction::run()` rather than left as a parameter that no longer
reaches the query.

`test_the_export_holds_every_row_once_past_the_chunk_boundary` pins this: 1200
rows, one line each, no duplicates, descending `id`. The pre-existing
query-count test could not catch it — it measures 3 and 40 rows, both inside one
500-row chunk.

### Cells are inert in a spreadsheet

The `actor` and `target` columns carry `users.name`, and `name` is free text on
`PUT /profile`. `UserUpdateAction` runs `strip_tags()`, which removes markup and
does nothing to a leading `=`. So any authenticated account could set its display
name to `=cmd|'/c calc'!A0`, perform one action, and have that string sitting in
the file the incident responder opens on their own workstation. `fputcsv()` is
no defence — it quotes a field containing a comma or a quote, and neither is what
makes a cell a formula (CWE-1236).

Every cell therefore passes through `GenerateAuditExport::cell()`, which prefixes
a `'` when the first non-whitespace character is `=`, `+`, `-` or `@`. The `'` is
how Excel, LibreOffice and Sheets are told "this is text", and it is only ADDED
where it is needed — `test_an_ordinary_name_is_not_rewritten` exists because a fix
that stripped the payload instead of quoting it would satisfy the security probe
and destroy the evidence the extract exists to provide.

Leading whitespace is skipped before the check on purpose: a tab or a space in
front of the `=` is the standard way past a check that only looks at position 0.
`strspn()` does that in one pass, with no trimmed copy per cell.

Applied to **every** cell rather than to the two or three that carry user text —
measured at +0.0115 ms/row against 0.245, so ~4.7% — because an allowlist is
a place for the next user-controlled column to be forgotten.

`causerLabelFor()` falls back to the email when the name is empty, which reads
like a second way in. It is not one: the `email` rule on every write path
requires `local@domain`, so no reachable address begins with `=`. `name` is the
only attacker-chosen cell.

### The export is PHP-bound, and the cast was the cost

Measured at 5 000 rows: **2 466 ms wall, of which 12.4 ms is SQL.** The database
is never the constraint on this job — the row builder is.

The cost was `row()` reading `properties` four times per row (`source()`
internally reads `extra('source')`, plus `ip`, `request_id` and the JSON dump).
`properties` is cast `'collection'`, and **Eloquent does not memoise a primitive
cast**: `HasAttributes::transformModelValue()` calls `castAttribute()` on every
attribute read, and the `collection` case is `new Collection(json_decode($value,
true))`. A warm read costs the same as a cold one (16.3 µs vs 15.1 µs, ratio
1.02×) and `classCastCache` stays empty.

So the row is read once into a local and the four columns are lookups on the
result. Byte-identical output — a row whose `properties` is NULL still yields the
same empty cells, because `$props` is null on exactly the rows where `extra()`
returned its default. Build cost 0.3075 → 0.2128 ms/row (−31%).

`DB::table()` instead of Eloquent would be the larger lever (hydration is
0.176 ms/row) and is deliberately **not** taken: `causerLabel()` and
`subjectLabel()` would have to be reimplemented outside the model, which
duplicates the one place that decides what a deleted actor is called.

### …and that is the lever that was taken, once the rules moved

That reasoning was right about the cost and wrong about the ceiling being far
away. Hydrating 20 000 rows was measured at **1.58 ms/row**, so the job's 80-second
timeout stopped completing at roughly **50 000 rows** — and `activity_log` is never
pruned, so an export of a year-old installation could not finish at all. Three
retries did not help: each attempt ran the same full export and was killed at the
same 80 seconds.

The fix was to take the lever WITHOUT the duplication, by splitting the two
resolvers in `Activity` into pure functions of their inputs:

| | |
| --- | --- |
| `Activity::causerLabel()` | delegates to `Activity::causerLabelFor(?Model $resolved, $causerId, $marker)` |
| `Activity::subjectLabel()` | delegates to `Activity::subjectLabelFor(?Model $resolved, $subjectType, $subjectId)` |

The job now streams raw rows (`->toBase()`), resolves the chunk's distinct actor
and subject ids once (`labelMap()`, at most one query per morph type present), and
calls the SAME static functions the viewer calls. One copy of the rules answers
both surfaces, so the file cannot drift from the screen — including on the branch
that reads like a bug, a resolved non-`User` causer rendering `Deleted user (#id)`.

Measured on 20 000 rows, three runs each, same 124 queries before and after:

| | ms/row | wall |
| --- | --- | --- |
| hydrated models | 1.58 | 31.6 s |
| raw rows + shared resolvers | 0.245 | 4.4–5.2 s |

**6.4x.** Byte-identical output, verified by exporting the same fixture through
both implementations and diffing the files — the fixture includes a soft-deleted
actor, a NULL `properties` column, a NULL `event`, a NULL subject and a
system-style row, because those are the rows a rewrite breaks.

An earlier iteration that pre-resolved names to STRINGS measured 0.104 ms/row, but
it carried its own copy of the label rules. Paying ~2x to delete the second copy
is the right trade: the copy is what the 2026-10-13 note above was refusing, and
Eloquent does not memoise a cast, so every row was re-reading `name` off a model
anyway.

The 80-second timeout no longer binds. At 0.245 ms/row it covers roughly 300 000
rows; raising `$timeout` and `retry_after` is therefore **not** required, and is
still the wrong move to take alone — it moves the cliff rather than removing it.

### What shipped (Phase 10, Group E)

```
POST /activity-logs/exports          audit.export + throttle:audit-export
    ↓ audit_exports row (pending, expires_at = now + 7 days)
GenerateAuditExport                  chunkById(Desc) 500 ordered by id, raw rows + labelMap, fputcsv, local disk
    ↓ audit-exports/{user_id}/{id}.csv
row → ready + AuditExportReadyNotification (database + mail)
    ↓
GET /activity-logs/exports/{id}/download    audit.export + ownership check
    ↓ (failed rows only, up to 3)
POST /activity-logs/exports/{id}/retry      same row → pending, re-dispatched
```

| Piece | Where |
|---|---|
| Request / dispatch | `AuditExportRequestAction::run()` |
| Retry + cap | `AuditExportRequestAction::retry()`, `AuditExport::maxRetries()` |
| Job | `App\Jobs\GenerateAuditExport` |
| Row + status enum | `App\Models\AuditExport`, `App\Enums\AuditExportStatus` |
| Download | `AuditExportDownloadAction` |
| Ownership | `AuditExport::isOwnedBy()` — both id-in-URL routes share it |
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
- **A `failed` row is re-runnable, three times, on its own row.** `failed` used to
  be a dead end: the only way forward was the Export form, which creates a SECOND
  row for what the operator meant as the same request and leaves the failed one in
  the list forever beside a file that does exist. The retry flips the SAME row back
  to `pending` — which also keeps `diskPath()` honest, since the path is keyed on
  the id and the new CSV overwrites the old one. The number of clicks is
  `system_settings.audit_export_max_manual_retries`, default **3**, read through
  `AuditExport::maxRetries()` — one method, so the key appears once in the
  codebase and the Action, the resource, the Blade and the tests all read the same
  row. It is a setting rather than a constant for the same reason the hourly
  ceiling is: an install whose exports keep dying on something environmental
  should be able to turn the button off (**0** is a valid value and a real answer,
  not an absent one) or widen it for an incident, without a deploy.
  Three is a **ceiling, not an encouragement**: it counts clicks while
  `$tries = 3` already retries inside each one, so one row at the default can cost
  nine whole-table builds, and the dominant causes are environmental — a worker
  that cannot reach a dependency is not fixed by asking twice more. Past the
  budget the row says `Contact admin`, and `audit.export.retries_exhausted` is
  logged so an admin can find the rows that need a human. **The cap is enforced in
  the Action, not the Blade**: the button is hidden in both channels' views, and a
  cap enforced only in a template is a cap with a hole shaped like an HTTP request.
  `expires_at` is deliberately **not** extended by a retry — the window is seven
  days from the request, per `operations/retention.md`, and moving it should not
  happen as a side effect of a button.
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
| Retry a failed row | `POST /activity-logs/exports/{export}/retry` | `POST /api/v1/activity-logs/exports/{export}/retry` → **202 + row** |

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
- **`retry_count`, `max_retries` and `can_retry` travel with every row.**
  `status: failed` alone tells a client nothing about whether retrying is still
  allowed — that is per row — so a client that polls would otherwise discover the
  cap as a 409. `can_retry` is the model's own `canRetry()`, the same rule the
  route enforces, so the payload cannot offer a retry that will be refused.

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
- The retry route checks ownership before it changes anything, and requires the
  row to be `failed` — a `pending` row retried is two workers on one file. The
  `failed` check is enforced by the claim itself, one conditional `UPDATE` that
  carries the same conditions, so two simultaneous retries cannot both win: one
  takes the row, the other gets **409** and dispatches nothing.
- Never store sensitive data in plaintext in audit logs.
- Consider log rotation/archiving for long-term retention.

## ADR References

- ADR-008: Audit Trail vs Technical Observability separation
- ADR-001: API-first architecture — the reason the reader is not web-only