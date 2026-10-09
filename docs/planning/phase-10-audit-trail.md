# Phase 10 — Audit Trail

> Date: 2026-10-09 | Branch: feature/phase-10-audit-trail` | Status: PLANNED (docs only, no code touched)
> Purpose: execution breakdown for the audit **read** side + async export. The
> audit **write** side already shipped and is verified — see §1.
> Groups: **A = every view, the sidebar entry and the nav.** B onward is logic,
> validation, access control, write-path integrity and export.
> No task in this document has been started. Nothing is committed.

---

## Headline finding — the write side is done, the read side does not exist

The roadmap describes Phase 10 as "Audit package integration, audit abstraction
layer, async export". The first two are **already shipped and reconciled**:

| Fact | Evidence | Verified |
|---|---|---|
| `spatie/laravel-activitylog: ^4.8` installed | `composer.json:15` | yes |
| One audit entry point exists | `app/Models/Concerns/Auditable.php` | yes |
| 41 `->audit()` call sites across actions, services and jobs | `grep -rn -- '->audit(' app/` | yes |
| 31 distinct event names | `grep -rhno "audit('[a-z_.]*'" app/` | yes |
| Controllers hold **no** audit helper — `Controller::audit()` and `bulkAudit()` were deleted | `app/Http/Controllers/Controller.php:10-14` | yes |
| Zero controllers write an audit row | `grep -rn "audit(" app/Http/Controllers/` → comments only | yes |
| Trait captures `source` / `ip` / `user_agent` on every row | `Auditable.php:72-81` | yes |
| Audit written inside the mutation transaction, action-first | `docs/base/features/audit-trail.md:276-286` | yes |

So the real Phase 10 is: **a read-only viewer, its access control, and an async
export.** AUDIT-001/002/003 in the tracker are done in substance and should be
reconciled rather than re-implemented.

### Two stale claims found while auditing this

Both are in planning docs, not code. Fixing them is part of Group F.

1. `docs/planning/progress.md:19` and `task-tracker.md:89` say **"AUD-006
   (Profile) still migrates — the last caller of `Controller::audit()`."**
   `Controller::audit()` no longer exists, and both `ProfileController`s carry
   only explanatory comments (`Web/V1/ProfileController.php:110-120`,
   `Api/V1/ProfileController.php:61`). The rows are owned by `UserUpdateAction`
   (`app/Actions/V1/User/UserUpdateAction.php:113,120`) and
   `UserRequestEmailChangeAction` (`:35`). **AUD-006 is done.** Leaving it on
   the progress page sends the next session looking for a migration that has
   nothing to migrate.
2. `docs/planning/task-tracker.md` still carries AUDIT-001/002/003 as
   `PLANNED`. See §9.

---

## 1. What exists — audited, not assumed

### Write path (shipped)

| Concern | Where |
|---|---|
| Single model-level writer | `app/Models/Concerns/Auditable.php` — `audit()` `:42`, `auditContext()` `:72`, `auditBulk()` `:118` |
| System/job writer | `app/Jobs/Concerns/AuditsSystemActivity.php` — nullable causer, `source => system` |
| Service writer (one case) | `app/Services/InactivityLock.php:86` — has two callers, so the service owns the row |
| Bulk path | `UserBulkActionHandler` / `RoleBulkActionHandler` loop the per-item action; `auditBulk()` is the measured escape hatch |

### Auditable models

`User`, `Role`, `FeatureFlag`, `SystemSetting` — all carry the trait.

### Read path — does not exist

| Missing | Note |
|---|---|
| `App\Models\Activity` | no model; the viewer has nothing to query through |
| Index/show actions | no `ActivityIndexAction`, no `ActivityShowAction` |
| Query validation | no `ActivityQueryRequest` |
| Controllers | no `Web\V1\ActivityLogController`, no `Api\V1\…` |
| Routes | `grep -n "activity-logs" routes/*.php` → **no hit** |
| Views | `find resources/views -ipath '*audit*'` → **empty** |
| API resource | `app/Http/Resources/Api/V1/` holds Role / HealthCheck / User / Permission only |
| Permissions | `PermissionCatalog.php:90` says so in a comment: *"no `audit.*` yet"* |
| Export | no job, no `audit_exports` table, no disk write, no notification, no download route, no expiry sweep |

### Infrastructure that is already waiting for it

| Piece | State |
|---|---|
| Feature flag `activity_logs` | declared `config/pennant.php:73-80`, group `Audit`, **marked `pending`** |
| Sidebar item | `AppMenuComposer.php:180-189` — route `activity-logs.index`, `feature: activity_logs`, **no `permission` key** |
| Private disk | `config/filesystems.php:33-39` — `local` root is `storage_path('app/private')`. Correct target, already configured |
| Correlation ID | `GenerateRequestCorrelationId` binds `app('request_id')` on every request |
| In-app inbox | `/notifications/inbox` on Laravel's `database` channel — the export-ready notice has somewhere to land |
| Queue | `QUEUE-001` DONE, `database` driver, `after_commit => false` (so jobs must declare `$afterCommit = true`) |
| Retention sweep precedent | `routes/console.php` — the notification prune is the pattern to copy for export expiry |

---

## 2. Findings that shape the design

### F1 — `request_id` is in the docs, and missing from every row

`docs/base/features/audit-trail.md:46` and DEP-003 both require the audit
metadata to carry a **Request/Correlation ID**. The middleware generates one and
binds it (`GenerateRequestCorrelationId.php:50`). `Auditable::auditContext()`
returns `source`, `ip`, `user_agent` — **no `request_id`**.

So today an operator holding a 403 body with `meta.request_id` in it cannot tie
that request to any audit row. The one piece of metadata that would make the log
and the error trace agree is the piece not captured. Fixed in Group D, not
Group A, because it changes what every existing row shape means.

### F2 — the table has no index on either primary filter

`docs/base/features/audit-trail.md:27-28` requires filter by actor, action and
date range. Current indexes:

| Index | Source |
|---|---|
| `log_name` | `create_activity_log_table.php:19` |
| `(subject_type, subject_id)` | implicit — `nullableMorphs('subject','subject')` |
| `(causer_type, causer_id)` | implicit — `nullableMorphs('causer','causer')` |
| **`event`** | **missing** |
| **`created_at`** | **missing** |

The viewer sorts on `created_at desc` on every page load and filters on `event`.
Both are unindexed. Group C.

### F3 — DEP-003 names an abstraction that is not the one that shipped

DEP-003 says *"All audit writes go through a dedicated `Audit` service class"*,
and §Reversal refers to `Audit::record(...)`. What shipped is a **trait**,
`App\Models\Concerns\Auditable`, called as `$user->audit($event, $causer, $props)`.

The trait won and it won for a defensible reason (recorded at
`Auditable.php:11-29`: each caller assembling its own context is what produced
the `source`/`channel` split-brain). But DEP-003 is the dependency record other
sessions read before touching this area, and it names a class that does not
exist. Group D corrects the record; Group F updates `changelog.md`.

### F4 — `log_name` is a dead filter dimension

Every row carries `log_name = 'default'` — hardcoded in `auditBulk()` (`:134`)
and the package default elsewhere (`vendor/…/config/activitylog.php:20`, since
`config/activitylog.php` is **not published**). It is one value for the whole
table. The viewer must not offer it as a filter, and the index on it (F2) buys
nothing.

### F5 — `subject_type` stores FQCNs, because no morph map exists

`grep -rn "morphMap" app/` → no hit. Rows therefore store
`App\Models\User`, not `user`. Three consequences for a viewer:

- the UI would have to render an internal class name, or ship a name map;
- renaming a model silently orphans every historical row that pointed at it;
- the export would leak application namespace into a file handed to an operator.

Group D resolves this with `Relation::enforceMorphMap()` + a backfill migration.
**It is the one task in this phase with a data-migration risk**, so it is
isolated in its own commit with its own test.

### F6 — retention says two different things about export files

`docs/base/operations/retention.md`:

- `:12` — "Audit export files | 7 days after generation"
- `:22` — "Temporary exports | 1 day (`storage/tmp`)"

Two rows, two lifetimes, for what a reader reasonably assumes is one artifact.
Group E needs one number. Recommendation and the reasoning are in §6 (D-3).

### F7 — unpublished `config/activitylog.php` hides a 365-day deletion default

Because the config is not published, `delete_records_older_than_days => 365`
from the package default applies. `activitylog:clean` is **not** scheduled
(`grep -rn "activitylog" routes/console.php` → no hit), so nothing deletes today.
But `retention.md:11` promises *"Audit logs — Indefinite (until manual review)"*,
and the moment anyone schedules that command the promise silently breaks. Group D
publishes the config with the key set to `null` so the doc and the code agree.

---

## 3. Access control — decided here, applied in Groups A and B

Two gates, matching the Phase 7 / Phase 11 pattern already in the codebase:
**flag AND permission**, both failing as 403 so a caller cannot tell which fired.

| Gate | Value | Why |
|---|---|---|
| Feature flag | `activity_logs` | already declared; it is the deploy-time kill switch for a module that reads operational security data |
| Read permission | `audit.view` | named in DEP-003:71 and `ui-authorization.md:32-38` |
| Export permission | `audit.export` | named in DEP-003:71 |

`audit.export` stays separate from `audit.view` for the same reason
`notifications.send_test` is separate from `notifications.manage`
(`PermissionCatalog.php:70-74`): an export puts the whole table — including
every actor's IP and user agent — into a file that leaves the application.
Reading rows is not the same act as extracting them.

`PermissionSeeder::matrix()` grants `SystemRole::ADMIN => PermissionCatalog::all()`,
so admin inherits both with no seeder edit, and superadmin reaches them through
`Gate::before` with no permission row — the same invariant as `pulse.view`.

---

## 4. Group A — Views, UI & Navigation

Everything a person sees. Blade, components, the sidebar entry, and the render
gate that keeps the views honest.

> **Ordering constraint.** A1's sidebar item names `audit.view`, which is
> created in B1. Land B1 with A1 or the sidebar render-gate test fails on a
> missing catalogue entry — which is the correct failure, and still a failure.
> A and B are the first shippable unit; nothing in A is reachable until B lands.

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-A1 | **Sidebar entry** — add `'permission' => 'audit.view'` to the existing `activity-logs` item in `AppMenuComposer::groups()`; keep `'feature' => 'activity_logs'` | B1 | tiny | The entry already exists at `:180-189` and is currently dropped by `Route::has` (`visible()` `:114`). It becomes live the moment B2 registers `activity-logs.index`. No new item, no new icon |
| P10-A2 | **Index page** — `resources/views/pages/activity-logs/index.blade.php`: filter bar (search, event, causer, subject type, date range, source), result table, pagination footer | B2, B3, C3 | medium | Follow `pages/users/index.blade.php` exactly: `card border-0 shadow-sm mb-4` → `card-body p-4` → `table-responsive` → `table table-hover align-middle mb-0`, then the pagination block from `design-system.md:264-298` **inside** `card-body`, never `card-footer`. `->paginate(10)->withQueryString()`. Row numbering `(currentPage() - 1) * perPage() + $loop->iteration` |
| P10-A3 | **Detail page** — `resources/views/pages/activity-logs/show.blade.php`: metadata block, causer/subject identity, decoded `properties` as key/value, **no edit affordance of any kind** | B2, B4, D3 | medium | Read-only is a hard requirement (`audit-trail.md:19`). The page must contain no form, no `x-ui.confirm-action`, and no delete button. Properties render as a definition list with values escaped, not `{{ $row->properties }}` dumped raw |
| P10-A4 | **Filter controls** — one partial for the index filter bar; every control submits via GET and echoes current values | A2 | small | No component library exists for this. Follow the users index. Not a Blade component — a `@include`d partial, so it is not a UI variant to maintain |
| P10-A5 | **Export UI** — "Export CSV" button (visible only under `@can('audit.export')`) on the index, plus a download panel on the detail page | B5, E2 | small | `@can` in Blade is UX only; the server gate in B5 is the boundary (`ui-authorization.md:3-11`). Button must not appear for a `audit.view`-only holder |
| P10-A6 | **Empty / pending states** — no-results state and the "export in progress" placeholder | A2, E2 | small | `<x-ui.empty-state>` already exists (`resources/views/components/ui/empty-state.blade.php`) and is used at `pages/features/index.blade.php:104`. Reuse it — do not hand-roll a second one |
| P10-A7 | **Render gate** — `tests/Feature/Audit/AuditLogUiRenderTest.php` | A1–A6 | medium | Copy the shape of `SettingsUiRenderTest`. Must pin: renders with **0 queries from Blade** (delta across a warm second render), forbidden classes absent (`bg-white`, `bg-light`, bare `card-body`), every error/feedback div `d-block` + `aria-describedby`, **no write affordance anywhere on the detail page**, and the sidebar entry visible only with `audit.view` and only when the flag is on |

### A-specific traps this codebase has already paid for

- **`input-group` breaks Bootstrap's sibling `invalid-feedback` selector.** The
  activity filter bar is a GET form with no validated inputs, so it should not
  use `input-group` at all. If a date-range field ever gets server-side
  validation, it takes the `id` + `aria-describedby` + `d-block` treatment from
  `phase-8-settings-management.md` Group A.
- **Tooltip / info icons are `fs-7`, never `fs-6`.** `fs-6` is body size
  (`adminlte.css:8484`) and is what made the settings page render icons as large
  as their labels.
- **No query in Blade.** `ui-architecture.md` rule 1. Every value on both pages —
  the event list for the filter dropdown, the causer list, the subject-type list
  — arrives from the controller. A dropdown of event names hardcoded in Blade
  goes stale the first time an action adds an event, and it fails silently.
- **`Route::has()` first.** `AppMenuComposer::visible()` drops an item whose
  route is missing. Until B2 lands, A1 is inert by design — that is correct, and
  it is why the flag can be flipped on before the routes exist.

---

## 5. Groups B onward

### Group B — Access Control & Routing

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-B1 | **Permission catalogue** — add `private const AUDIT = ['audit.view', 'audit.export']` to `PermissionCatalog`, appended to `all()` | — | tiny | Replaces the stale comment at `PermissionCatalog.php:90-92`. `grouped()` groups on the prefix, so both land under an **audit** heading in the role matrix with no other edit. Follow the file's own rule: added in the same commit as the page it guards |
| P10-B2 | **Web routes** — `GET /activity-logs` → `activity-logs.index`, `GET /activity-logs/{activity}` → `activity-logs.show`, under `feature:activity_logs` + `can('audit.view')` in `routes/web.php` inside the authenticated group | B1 | small | Same wiring as `settings.index` (`routes/web.php:80`). `{activity}` is route-model bound through the new model in C1 |
| P10-B3 | **Feature flag de-pending** — remove the `'pending'` key from `activity_logs` in `config/pennant.php:73-80` | B2 | tiny | Until the routes exist the page at `/features` says the switch "records intent only", which is true today and false after B2. Leaving it is the "a toggle reports success and changes nothing" problem Phase 7 fixed |
| P10-B4 | **Export + download routes** — `POST /activity-logs/export` (`can:audit.export)` + `GET /activity-logs/exports/{export}/download` (`can:audit.export)` + `throttle:audit-export` | B1, E1 | small | See §6 D-2 for the throttle. Registered with the export routes so the permission and the rate limit are declared once, in one place |
| P10-B5 | **Gating tests** — flag off → 403; no permission → 403; permission + flag on → 200; **superadmin with no permission rows → 200** | B1–B4 | medium | Mirror the flag half of `tests/Feature/FeatureFlag/PulseFeatureGateTest.php` (3 cases) and the permission half from `phase-11-monitoring-observability.md` §6 — `PulsePermissionGateTest` is itself still **PLANNED**, so there is no file to copy from yet. The superadmin case is the one that catches a mistake the other three cannot: lose `Gate::before` and 1, 2 and 4 all still pass |

**Superadmin and the flag.** Phase 7 established no flag bypass and tested it.
That stays: a flag-off module 403s for everyone including superadmin. Phase 11
noted the same tension and deliberately deferred it — **do not bundle it here.**

### Group C — Read Logic, Validation & Query

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-C1 | **`App\Models\Activity`** — extends `Spatie\Activitylog\Models\Activity`; adds `causer()` / `subject()` friendly accessors and a `labelFor()` resolver turning a morph alias into a human string | B1 | medium | Required by F5. `Spatie\Activitylog\Models\Activity` already has the `causer`/`subject` morphTo relations; do not redeclare them. The label map is the **only** place a class name becomes a word |
| P10-C2 | **Index migration** — composite `(event, created_at)` plus a plain `created_at` index on `activity_log`; reversible `down()` | — | small | From F2. Reversible by rule. On MySQL 8 the composite serves `WHERE event = ? ORDER BY created_at DESC`; the standalone serves the unfiltered default sort. Name the index explicitly — an unnamed one is a mystery on the next slow query |
| P10-C3 | **`ActivityQueryRequest`** — `search`, `event`, `causer_id`, `subject_type`, `source`, `date_from`, `date_to`, `sort`, `direction`, `per_page` (5–100) | B1 | small | `app/Http/Requests/V1/Audit/ActivityQueryRequest.php`. Extends `BaseFormRequest` — the four-key error envelope is owned there (`BaseFormRequest.php:1-21`) and a Request that does not extend it returns the wrong shape. `authorize()` returns `true`; **authorization is the route's job** (`AGENTS.md` — never a controller constructor, and the `can:` gate is already on the route). `sort` is an `in:` allowlist, never passed to `orderBy` raw |
| P10-C4 | **`ActivityIndexAction`** — search, filter, sort, paginate; same shape as `UserIndexAction` | C3 | medium | `app/Actions/V1/Audit/ActivityIndexAction.php`. Follows `{Domain}{Operation}Action` naming — enforced by `ActionNamingConventionTest`. Search matches `event` and `description`; **not** `properties` (JSON, unindexed, and a `LIKE` over it is a table scan). Eager-load `causer`/`subject` — a `belongsTo`-per-row morph is the classic N+1 and the table is large by definition |
| P10-C5 | **`ActivityShowAction`** — fetch one row, 404 on missing | C1 | tiny | No eager loading here — one row. Keep it an Action so the controller is a formatter |
| P10-C6 | **Web controllers** — `Web\V1\ActivityLogController@index` / `@show`; thin: validate, delegate, shape the view data (event list, causer list, subject-type list for A2's dropdowns) | C3–C5 | medium | Per `AGENTS.md`, the controller supplies what the view needs and must not query for it inline. **No audit call in either method** — a read writes nothing (`audit-trail.md:144`) |
| P10-C7 | **Filter dropdown data** — the event / causer / subject-type / source option lists for A4 | C6 | small | Derive `event` from the table (`distinct`), not from a hand-typed array in Blade. A hand-typed list is F4 all over again — a new event is invisible in the filter until someone remembers to add it |
| P10-C8 | **N+1 proof** — `ActivityBenchmarkTest` asserting a constant query count at 1 and at 500 rows on index and show | C4, C6 | medium | The phase-9 precedent (`tests/Feature/Notification/NotificationBenchmarkTest.php`) is the model. SQLite `:memory:` — query counts are the portable part, timings are not |

### Group D — Audit Trail Write-Path Integrity

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-D1 | **`request_id` in `auditContext()`** — add `'request_id' => app('request_id')` with a null-safe fallback when no request is bound (jobs) | — | small | From F1. One line in the one place that already derives context — not per call site. A queued job has no request, so `app()->bound('request_id')` must be checked, not `app('request_id')` read blindly |
| P10-D2 | **Regression test for D1** — an HTTP mutation row carries the same `request_id` as the response header; a job row carries a null `request_id` and `source => system`, without erroring | D1 | medium | The job half is the one that catches an unguarded container read. Pin both |
| P10-D3 | **Publish `config/activitylog.php`** with `delete_records_older_than_days => null` | — | tiny | From F7. Publish the file, set the key, and add a comment saying *why* — retention for audit rows is a legal decision, not a package default |
| P10-D4 | **Correct DEP-003** — replace the `Audit` service class / `Audit::record(...)` language with the trait that actually shipped, carrying the reason it won | — | small | From F3. A DEP that names a class nobody can find is worse than no DEP |
| P10-D5 | **Morph map + backfill** — `Relation::enforceMorphMap()` in a provider; migration rewriting existing `subject_type` / `causer_type` FQCNs to aliases; rollback reverses it | C1 | medium | From F5. **Isolated commit, isolated test.** `enforceMorphMap()` throws on an unmapped morph, so the backfill must land in the same deploy as the map. Write the migration to be idempotent (alias already present → no-op) so a re-run is safe |
| P10-D6 | **Sensitive-property guard** — `tests/Feature/Audit/AuditPropertyScrubTest.php`: no row's `properties` contains a password, token, secret or `remember_token` value | — | small | DEP-003:64 requires the abstraction to scrub. It does not today — it is a *convention* every call site follows by hand. Today it holds (`auth.password_changed` at `AuthChangePasswordAction.php:78` passes no properties at all), and nothing would notice if it stopped. A test is cheaper and more durable than a scrubber nobody asked for |
| P10-D7 | **Read-only enforcement test** — no route, controller or mass-assignment path can create, update or delete an `activity_log` row | B2 | medium | `audit-trail.md:19` and DEP-003:73. Pins it structurally: no write route, `Activity` not `$fillable` beyond read fields, no `Model::destroy` path. This is the requirement most likely to be eroded by a future "let admins annotate a row" request |

**Deliberately not in Group D:** a `properties` scrubber. D6 makes the absence
safe and observable. Adding scrubbing machinery for a problem no current call
site has is the speculative layer `ai-execution-guide.md` rule 19 warns against.
Revisit when a caller that logs sensitive state actually appears.

### Group E — Async Export

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-E1 | **`audit_exports` migration + model** — `id`, `user_id`, `filters` (json), `status` (`pending`/`ready`/`failed`), `path`, `row_count`, `expires_at`, timestamps, `completed_at` | B4 | medium | A job alone cannot serve a download link — the row is the hand-off between queue and HTTP. `user_id` is what makes the ownership check in B4/E4 possible. Status is an enum on the model, not a bare string |
| P10-E2 | **`AuditExportRequest`** — validates the same filter keys as `ActivityQueryRequest`, plus `format` (csv only) | C3 | small | Two Requests sharing rules is duplication. Extract the rule array to a shared place both read, rather than copy-pasting 9 lines that drift |
| P10-E3 | **`GenerateAuditExport` job** — `ShouldQueue`, `$afterCommit = true`, `$tries`/`$timeout` set, `chunkById` over the *filtered* set, streamed CSV via native `fputcsv` to the `local` disk at `audit-exports/{user_id}/{uuid}.csv` | E1, C4 | medium | **`after_commit => false` in `config/queue.php`** — the job must declare `$afterCommit = true` itself, exactly as Phase 9's notifications do after finding three actions dispatching inside a transaction. Native `fputcsv`, no Excel package (DEP-005). Never load the whole result set: `chunkById` |
| P10-E4 | **Request + download controllers** — `POST` creates the row and dispatches; `GET .../download` streams, then 410s once `expires_at` passes | B4, E1 | medium | **Ownership check is mandatory**: `$export->user_id === $request->user()->id`, or a superadmin bypass. Without it, anyone holding `audit.export` can enumerate ids and pull another operator's extract. 410 Gone (not 404) once expired — the file existed |
| P10-E5 | **`AuditExportReadyNotification`** — `database` + `mail`, `$afterCommit = true`, links to the download | E3 | small | Reuse Phase 9's channel machinery. It goes to the requesting user directly, **not** through `NotificationAudience` — that class maps *administrative events* to roles, and an export is nobody else's business. Do not add an entry to `ADMINISTRATIVE_EVENTS` |
| P10-E6 | **Expiry sweep** — `Schedule::call` deleting `audit_exports` past `expires_at` **and** unlinking the file | E1, E3 | small | Copy the notification-prune block at `routes/console.php:49-61`: `->daily()->name(...)->withoutOverlapping()`. Deleting the row without the file leaks disk; the file without the row leaks nothing but leaves litter. Both, one pass |
| P10-E7 | **Rate limit** — `RateLimiter::for('audit-export')` in `AppServiceProvider` | B4 | tiny | See §6 D-2. A permission answers *who*; only a limiter answers *how often* — the `send-test-mail` precedent at `AppServiceProvider.php:170-188` |
| P10-E8 | **Export tests** — dispatch creates one row and returns 202-ish; job writes a file whose header matches the filter; another user cannot download it; expired → 410; sweep deletes row **and** file; a job failure marks the row `failed` and leaves no file | E1–E7 | large | The failure case is the one that gets skipped and the one that matters: a job that throws with the row stuck at `pending` is a permanent lie in the UI |

### Group F — Verification, Benchmarks & Docs

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P10-F1 | **Full suite green** — `php artisan test` + `vendor/bin/pint --test` | A–E | medium | Baseline today: ~1135 tests / ~4308 assertions (`AGENTS.md`). Record the new number in `progress.md` |
| P10-F2 | **Pentest pass** — `tests/Feature/Audit/AuditPentestTest.php`: authorization at 4 privilege levels on both surfaces, IDOR on the download route, filter injection (`event` / `sort` allowlist), mass assignment on an activity row, path traversal in the export filename, cross-user export access, unbounded export row count | A–E | large | Model it on `tests/Feature/Security/NotificationPentestTest.php` (11 probes / 108 assertions) — note the directory is `tests/Feature/Security/`, not `tests/Feature/Notification/`. The download route and the `sort` allowlist are the two novel attack surfaces here |
| P10-F3 | **Docs** — `docs/base/features/audit-trail.md` gains a "Reader" section; `implementation-roadmap.md:144-148` Phase 10 status; `docs/planning/README.md` gains this file | F1 | small | The base doc currently documents only the write side. Per `AGENTS.md`, docs must match code — a shipped viewer described nowhere is the same drift Phase 11 had to sweep 27 files for |
| P10-F4 | **Tracker reconciliation** — AUDIT-001/002/003 → DONE with evidence; AUDIT-004/005 → DONE with the `P10-*` IDs that satisfied them; **AUD-006 → DONE** with the finding from §1; `progress.md:19` Phase 10 row rewritten | F1 | small | See §9 |
| P10-F5 | **`changelog.md`** — new entries: the trait-vs-service divergence (D4), the morph map (D5), the two new indexes (C2), `request_id` in audit rows (D1) | F3 | tiny | Planning/architecture changed, so rule 12 applies |
| P10-F6 | **QA tracker entries** — manual flows for the viewer and the export on both channels | F3 | small | Per rule 19: curl tests miss client-side state. The export round-trip is exactly the flow that needs a human once |

---

## 6. Decisions taken, and the two that need Jaya

**D-1 — Sidebar entry stays where it is.** It already exists at
`AppMenuComposer.php:180-189` with the right icon, route name, active glob and
flag key. Phase 10 adds a `permission` key and a route; it does not add a menu
item. Re-adding it would produce a duplicate once the route ships.

**D-2 — `audit.export` rate limit: 5 per hour, keyed on user + IP.** An export
streams the whole filtered table into a file. Ten an hour by one account is a
denial-of-service lever against the database and against disk; five is a real
operator's working pace. Key on user **and** IP (mirroring `send-test-mail`,
`AppServiceProvider.php:184`) so spraying across accounts does not widen it.
Follows `send-test-mail` at `:179-188` rather than the per-minute user-state
limit — the cost per call here is orders of magnitude higher.

**D-3 — Export files live 7 days. `retention.md:22` needs a correction.**
`retention.md:12` says 7 days for audit export files; `:22` says 1 day for
temporary exports in `storage/tmp`. Recommendation: **7 days**, because `:12` is
the row that names this artifact specifically and `:22` is the generic row for
anything under `storage/tmp` — which a private-disk export is not. Then `:22`
either goes or is reworded to say it covers a different class of artifact.
*Needs a yes/no, because writing the wrong number into a sweep is the kind of
thing that is only discovered when an operator asks for last month's export.*

**D-4 — Export format is CSV only, generated natively.** No `maatwebsite/excel`,
no league CSV. `fputcsv` streams, adds no dependency, and every spreadsheet
opens it. Revisit XLSX when someone actually asks; a dependency chosen for a
format nobody requested is the thing `dependency-governance.md` exists to stop.

**D-5 — The viewer reads the package's `Activity` model, it does not fork it.**
`App\Models\Activity extends Spatie\Activitylog\Models\Activity`. Forking would
mean owning the schema; extending means the D5 morph map and any future package
migration apply automatically.

**D-6 — No `audit.view` + `audit.export` split into per-resource permissions.**
One reader sees the whole trail; there is no per-resource audit scoping to model.
If a per-resource grant is ever needed, it is a different permission set and a
new ADR — not three more rows here.

---

## 7. Execution order

```
B1 permission ─┬─► B2 web routes ─┬─► A1 sidebar ──► A2 index ──► A4 filters ──► A6 empty states
               │                  ├─► B3 de-pending flag
               │                  └─► B5 gating tests
               │
               └─► C1 Activity model ──► C2 index migration
                          │                     │
                          └─► D5 morph map + backfill   (own commit, own test)
                                    │
C3 query request ──► C4 index action ──► C6 controllers ──► C7 dropdown data ──► C8 benchmark
C5 show action  ──────┘
                          │
                          └─► A3 detail page ──► A7 render gate

A5 export UI ──► B4 export routes ──► E1 migration/model ──► E2 request ──► E3 job
                                                                    │
                                          E7 throttle ◄────────────┤
                                          E5 notification ◄───────┤
                                          E4 download controller ◄─┤
                                          E6 expiry sweep ◄────────┘
                                                                   │
                                          A6 pending state ◄──────┤
                                          E8 export tests ◄───────┘

D1 request_id ──► D2 regression test          (independent, any point)
D3 publish activitylog config                 (independent)
D4 correct DEP-003                            (independent)
D6 sensitive-property guard                   (independent)
D7 read-only enforcement test                 (needs B2)

F1 suite ──► F2 pentest ──► F3 docs ──► F4 tracker ──► F5 changelog ──► F6 QA
```

Two of these are self-contained and can land first, in either order: **D1+D2**
(`request_id` is a missing requirement today, independent of any viewer) and
**D3** (publishing the config with the retention key fixed). Neither needs the
viewer to exist, and both close a gap between the code and `docs/base/`.

**D5 (morph map + backfill) is the one commit that can lose data if it is wrong.**
It gets its own branch review and its own test, and it must not ride along with
a feature commit.

---

## 8. Deliberate simplifications

- **No full-text search over `properties`.** The base doc asks for full-text
  search; `properties` is JSON, unindexed, and a `LIKE` across it is a table scan
  on the largest table in the system. Search covers `event` and `description`,
  which is what an operator actually types. Revisit with a generated column or
  a search index when a real query justifies it.
- **No `before`/`after` diff columns.** `audit-trail.md:40-42` lists them. Today
  properties carry what the *action* decided to record (`UserUpdateAction:113`
  passes `changedFields($before, $user)`), which is more precise than a generic
  model diff and is the established convention. Adding package-level
  change-tracking would mean every row grows and every caller stops thinking
  about what is worth recording.
- **No `log_name` filter** (F4) — one value for the whole table.
- **No per-resource audit permissions** (D-6).
- **No scheduled `activitylog:clean`** — the retention row says indefinite, and
  D3 makes the package default match rather than adding a sweeper.
- **No observer, no model hook, no event-listener audit.** ADR-008 rejects
  observers; the action is the writer and stays the writer.
- **Export is CSV only** (D-4), and the export does not filter by anything the
  index cannot already filter by — same `ActivityQueryRequest` rules, so an
  export is always "the list you are looking at, as a file".

---

## 9. Tracker reconciliation

| ID | Current | Corrected | Evidence / satisfied by |
|----|---------|-----------|--------------------------|
| AUDIT-001 Integrate audit package | PLANNED | **DONE** | `spatie/laravel-activitylog ^4.8` installed; 3 migrations on `activity_log`; 41 call sites writing through one trait |
| AUDIT-002 Create audit abstraction layer | PLANNED | **DONE — as a trait, not a service** | `App\Models\Concerns\Auditable`. DEP-003 says "Audit service class"; D4 corrects the record |
| AUDIT-003 Implement audit recording in Actions | PLANNED | **DONE** | Every `->audit()` call site is in an action, `InactivityLock` (2 callers) or a job. Zero controllers write a row. `Controller::audit()`/`bulkAudit()` deleted |
| AUD-006 (Profile) — referenced only in `progress.md:19` and `task-tracker.md:89` | "still migrates" | **DONE** | `Controller::audit()` no longer exists. `UserUpdateAction:113,120` and `UserRequestEmailChangeAction:35` own the rows. The line must be removed, not re-marked |
| AUDIT-004 Implement audit view/detail API | PLANNED | PLANNED → satisfied by | A1–A3, A6, A7, B2, B3, B5, C1, C3–C8, D5, D7 |
| AUDIT-005 Implement async audit export | PLANNED | PLANNED → satisfied by | A5, B4, E1–E8, and D-3 (retention number) |

New IDs introduced by this document: `P10-A1..A7`, `P10-B1..B5`, `P10-C1..C8`,
`P10-D1..D7`, `P10-E1..E8`, `P10-F1..F6`. They are local to this phase and map
onto `AUDIT-004` / `AUDIT-005` at F4; they are not added to `task-tracker.md`
until F4, so the tracker keeps one ID per task rather than two.

---

## 10. Security notes

- The audit table holds **every actor's IP and user agent**. `audit.view` is
  not a generic read permission — it is access to behavioural data about staff.
  It belongs to `admin`/`superadmin` and should not be handed to a non-technical
  role the way `pulse.view` can be (Phase 11's warning, `phase-11-…md:288-290`).
- The download route is the one **IDOR surface** in this phase. E4's ownership
  check is not optional and F2 must probe it with a second user.
- `sort` reaches `orderBy`. `ActivityQueryRequest` must allowlist it. Without
  that, a column name goes to the query builder as an identifier.
- The export filename contains `{user_id}` and a UUID — no user-supplied path
  segment. F2 probes traversal anyway.
- `properties` is attacker-influenced data (a user-chosen username reaches
  `user.profile_updated`). A3 must escape it, never render it raw.
- Export files land on the `local` disk, root `storage/app/private` — already the
  private target. Never `Storage::disk('public')`.

---

*Breakdown complete. Nothing implemented, nothing committed. Start at B1, or at
D1+D2 if the missing `request_id` wants fixing before the viewer exists.*