# Phase 11 — Monitoring & Observability

> Date: 2026-10-02 (breakdown) · 2026-10-15 (restructured into Groups A–F; roadmap details folded in) | Branch: feature/phase-11-monitoring | Status: **IN PROGRESS** — Groups A–F below; the Pulse stack and the `pulse` flag already shipped, the permission half and both dashboards' remaining tasks have not.
> Purpose: execution breakdown for the technical observability layer — Laravel Pulse behind a permission, the system health dashboard, and the queue conventions every dashboard depends on. Replaces the Telescope + Periscope stack with Laravel Pulse and gates it the way every other module in the admin is gated.
> Groups: **A = every view, the sidebar entry and the nav.** B onward is logic, validation, access control, audit trail.
> Decisions locked 2026-10-02: **permission = `pulse.view`** (not `pulse.manage`), **flag and permission both stay**, **superadmin keeps access via `Gate::before`**, **admin inherits via `PermissionCatalog::all()`**.
> Dependency chain: flag already shipped (Phase 7, `b08b8b4`) → permission → gate override → nav entry → dashboards → tests.

---

## 1. What exists — audited, not assumed

| Fact | Where | Verified |
|---|---|---|
| `laravel/pulse: ^1.8` in `require` | `composer.json` | yes |
| `laravel/telescope`, `periscope` absent from vendor | — | yes, not installed |
| `config/pulse.php` published | `config/pulse.php` | yes |
| `pulse` feature flag declared, group `Monitoring` | `config/pennant.php:83-86` | yes |
| flag enforced on the route group | `config/pulse.php` (`feature:pulse` in `pulse.middleware`) | yes |
| flag-off returns 403 | `tests/Feature/FeatureFlag/PulseFeatureGateTest.php` (3 tests) | yes |
| `pulse_tables` migration | `database/migrations/2026_09_29_043034_create_pulse_tables.php` | yes |
| `pulse.view` in `PermissionCatalog` | — | **no — absent** |
| `viewPulse` gate override | — | **no — Pulse's `environment('local')` definition is the only one** |
| Pulse sidebar entry | `app/View/Composers/AppMenuComposer.php` | **no — absent** |
| `PulsePermissionGateTest` | — | **no — PLANNED (B4)** |
| Health check API endpoint, ungated | `routes/api.php:31` → `Api\V1\HealthCheck\HealthCheckController` | yes |
| `HealthCheckService` — db / cache / queue / storage checks | `app/Services/HealthCheck/HealthCheckService.php` | yes |
| System health **dashboard** (web page) | — | **no — PLANNED (A2/C1)** |
| Slow-query detection in application code | — | **no — deliberately deferred, see C2** |
| `failed_jobs` table + `queue:failed` / `queue:retry` / `queue:flush` | Laravel default | yes |
| `laravel/horizon` — evaluated out (Redis-only) | see §13 | yes |
| `storviaio/vantage` — not installed, recommendation stands: not yet | see §13 | yes |

The flag half of this phase shipped in `b08b8b4`. What is missing is the
**permission** half, the two dashboards' remaining tasks, and the queue
conventions upstream of them.

## 2. Findings that shape the design

### F1 — Pulse already defines the `viewPulse` gate, and the override must win the boot order

At `vendor/laravel/pulse/src/PulseServiceProvider.php:100`:

```php
$this->callAfterResolving(Gate::class, function (Gate $gate, Application $app) {
    $gate->define('viewPulse', fn ($user = null) => $app->environment('local'));
});
```

This is not "add a missing gate". It is **override an existing vendor gate**, and
the override only wins if it is registered *after* Pulse's. An earlier probe
showing superadmin reaching `/pulse` was answered by `Gate::before`, not by this
definition — so the default `environment('local')` is not what was letting him
through.

Measured, with the override in place:

```
before override, superadmin = true     (Gate::before)
after  override, superadmin = true     (Gate::before still answers first)
after  override, devops     = true      (non-superadmin, permission only)
after  override, plain      = false
```

### F2 — docs described a deleted stack (CLEARED 2026-10-02)

The stack swap landed in `85384b4` with no docs pass: **192 references to
Telescope/Periscope across 27 files**. Swept in the same change — 27 files → 15,
and every remaining mention is deliberate (a dated changelog entry, a superseded
ADR body, or the DEP-004 Context section explaining why Telescope was dropped).
A dated narrative is not a stale fact; stale instructions are. **No re-sweep is
outstanding.**

### F3 — the health endpoint is live and ungated, and that is correct

`GET /api/v1/health` answers with `{status, timestamp, checks}` where each check
is `ok`/`fail` — no versions, no config, no DSNs. It stays **unauthenticated**
because its caller is an uptime monitor that cannot log in; the response shape
is the ceiling on what it may ever expose. The *web* dashboard over the same
service is a different matter: it renders who-can-see-what inside the admin, so
it takes the full permission + flag treatment (B3).

### F4 — the API could read settings but not notifications (CLOSED 2026-10-13)

A route audit found the inversion: the API could read a user's notification
*settings* and could not read a single *notification*. Closed in
`Api\V1\Notification\NotificationInboxController` (ungated on both channels for
the reason the web inbox is ungated — a notification belongs to the person it
was sent to, answered by the token). `App\Support\SafeUrl` was extracted so the
same-origin check on `notification.data['url']` has one home instead of two.
Detail in §12.

## 3. Access control — decided here, applied in Groups A and B

| Decision | Rationale |
|---|---|
| `pulse.view`, not `pulse.manage` | the dashboard is read-only; a second action is a row nothing checks — the exact "row that lies in the permissions UI" `PermissionCatalog`'s own note warns against |
| flag **AND** permission, keep both | deploy-time kill switch vs per-role authorization answer different questions. Both failures 403, so a caller cannot tell which fired — the same deliberate trade Phase 7 made |
| `Gate::define('viewPulse', ...)` in `AuthServiceProvider` | Pulse uses `callAfterResolving`, so it defines its gate at resolve time; `Gate::define()` overwrites by name and our provider boots after Pulse's. `AuthServiceProvider` already owns the neighbouring authorization concern |
| `Gate::before` untouched | superadmin's access comes from the before-rule, so adding the permission never requires re-seeding and can never be stripped by a role edit |
| no `Pulse::auth()` call | removed in 1.8; the gate is the supported hook. Verified against vendor source |
| no `environment('local')` in the gate | the permission replaces it; local devs hold the permission like anyone else. One predicate, one reason to fail |
| admin inherits the permission | `PermissionSeeder::matrix()` grants `SystemRole::ADMIN => PermissionCatalog::all()` — zero seeder edits. Deliberate (option a): consistent with "admin holds the whole catalogue"; revisit if Pulse should be narrower than admin |

## 4. Group A — Views, UI & Navigation

Everything a person sees: the two dashboard entries in the sidebar and the
system health page.

> **Ordering constraint.** A1 names `pulse.view` and A3 names `health.view`,
> both created in Group B. Land B1 with A or the sidebar render-gate test fails
> on a missing catalogue entry — the correct failure, and still a failure.
> A and B are the first shippable unit; nothing in A is reachable until B lands.

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P11-A1 | **Pulse sidebar entry** — add to `AppMenuComposer::groups()`: label `Pulse`, icon `fas fa-heart-pulse`, route `pulse`, active `pulse`, `permission => pulse.view`, `feature => pulse` | B1 | tiny | First menu entry pointing at a vendor URL. `Route::has('pulse')` is true (vendor registers it). Active matching is `fnmatch`, safe because no other route name contains `pulse` — verified against `route:list`. A dashboard you cannot navigate to is the "switch reads Inactive over a live page" problem Phase 7 fixed |
| P11-A2 | **System health page** — `resources/views/pages/health/index.blade.php`: one card per check (database, cache, queue, storage), each rendering ok/fail from the shared service, plus a page-level badge | C1 | medium | Follow `pages/users/index.blade.php` skeleton. **No query in Blade** (`ui-architecture.md` rule 1) — the controller passes the checks array. No write affordance of any kind: this page reads a status, it does not run anything |
| P11-A3 | **Health sidebar entry** — label `System Health`, icon `fas fa-stethoscope`, route `health.index`, active `health*`, `permission => health.view`, `feature => health` | B3 | tiny | Same shape as A1. `health*` glob is safe: no other route name starts with `health` |
| P11-A4 | **Render gate** — `tests/Feature/Health/HealthUiRenderTest.php` | A1–A3, B4 | medium | Copy `SettingsUiRenderTest`: renders with **0 queries from Blade** (delta across a warm second render), forbidden classes absent (`bg-white`, `bg-light`, bare `card-body`), every feedback div `d-block` + `aria-describedby`, sidebar entries visible only with the permission AND only when the flag is on |

### A-specific traps this codebase has already paid for

- **Tooltip / info icons are `fs-7`, never `fs-6`** — `fs-6` is body size and is
  what made the settings page render icons as large as their labels.
- **`Route::has()` first.** `AppMenuComposer::visible()` drops an item whose
  route is missing — an inert entry before the route lands is correct by design.
- **No `<style>` blocks in Blade.** Theme-adaptive CSS lives in
  `public/vendor/theme.css`; a Bootstrap 5.3 native class (`bg-body`,
  `text-body-secondary`) is the alternative that adapts for free.

## 5. Groups B onward

### Group B — Access Control & Routing

The gate ships WITH what it guards: the permission and the flag land in the same
change as the routes and pages that check them.

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P11-B1 | **`pulse.view` in `PermissionCatalog`** — new `private const PULSE = ['pulse.view']`, appended to `all()` | — | tiny | `grouped()` groups on the prefix, so it appears under a **pulse** heading in the role matrix with no other edit. Follow the file's own rule: added in the same commit as the page it guards |
| P11-B2 | **`viewPulse` gate override** — `Gate::define('viewPulse', fn (User $user): bool => $user->can('pulse.view'))` in `AuthServiceProvider`, next to `configureSuperAdmin()` | B1 | tiny | The F1 boot-order argument in full: overwrite-by-name + later provider boot = ours wins. Do **not** call `Pulse::auth()` (gone in 1.8). Do **not** touch `Gate::before` |
| P11-B3 | **`health.view` + `health` flag + route** — `private const HEALTH = ['health.view']` in `PermissionCatalog`; `'health'` entry in `config/pennant.php` (group `Monitoring`); `GET /health` web route named `health.index` under `middleware(['can:health.view', 'feature:health'])` inside the authenticated group; the Pennant `database` store is fail-closed, so the `FeatureFlagSeeder` must write the row or the route 403s forever | — | small | Three places, or it is not gated: pennant config, route middleware, sidebar key (A3). The seeder row is the trap — a declared flag is not an active flag. The API `/api/v1/health` route is **untouched and ungated** (F3) |
| P11-B4 | **Gating tests** — `tests/Feature/PulsePermissionGateTest.php`: (1) role holding `pulse.view` + flag on → `/pulse` 200; (2) without the permission → 403; (3) superadmin, **no permission rows at all** → 200; (4) flag off + permission held → 403. Mirror the same four for `/health` | B1–B3 | medium | Case 3 is the one that catches a mistake here: define the gate and accidentally lose `Gate::before`, and 1, 2 and 4 all still pass. Case 4 pins the two gates as independent. This file is also what P10-B5 in `phase-10-audit-trail.md` says to copy — landing it unblocks that reference |

**Superadmin and the flag.** Phase 7 established no flag bypass and tested it: a
flag-off module 403s for everyone including superadmin. Phase 11 noted the same
tension (an operator sees "Pulse Dashboard — Inactive" yet superadmin still
reaches `/pulse`) and **deliberately deferred it** — decide separately, never
bundle it with this work.

### Group C — Read Logic & Dashboards

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P11-C1 | **`Web\V1\HealthController@index`** — thin: inject `HealthCheckService`, call `check()`, pass `$checks` + `isHealthy()` to the view | B3 | small | The service already exists and already logs failures with context (`health_check.*.failed`). **No FormRequest** — a GET with no input has nothing to validate; do not scaffold an empty one (conventions §4e). No audit call: a read writes nothing |
| P11-C2 | **Slow-query threshold logger — DEFERRED by decision, recorded so it is not "fixed" later** | — | — | The MySQL slow log covers this at the DB layer and needs no application code (`SET GLOBAL slow_query_log`, `long_query_time`, `log_output='TABLE'` — see §7). Add a PHP-side `DB::listen()` threshold logger **only when a real case appears that the slow log cannot see**. Pulse is deliberately absent from the slow-query ladder: it records on every request while enabled, adding write load precisely when the server is already slow |

### Group D — Audit Trail

**N/A by design, stated so the empty group is not read as forgotten.** Both
dashboards are read-only: no mutation means no `Auditable::audit()` row. The one
audit event this phase touches — `feature.toggled` when the operator flips
`pulse`/`health` at `/features` — is already written by the features module
(Phase 7) and needs no new call site. Do not add an observer to "log page
views"; that is a different product.

### Group E — Queue Conventions (P10-E14, cross-referenced)

All three are upstream of every dashboard, cost nothing now, and are what makes
Pulse (or Horizon or Vantage, if ever) useful on day one. Retrofitted onto forty
jobs, queue naming is expensive; a wrong dashboard choice is not.

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P11-E1 | **Named queues behind constants** — every job currently goes to `default` | — | small | One constant per queue name; jobs declare `$queue`. The dashboard's per-queue view is only as good as this naming |
| P11-E2 | **Every job declares `$tries`, `$timeout`, `$backoff`, `$maxExceptions`** | — | small | A retry chain is only meaningful because these are declared. `GenerateAuditExport` already does (`$tries = 3`) — copy that shape |
| P11-E3 | **Every job has `failed()` and logs with `request_id`** | — | small | `docs/base/infrastructure/queue.md` already mandates it; today it is a per-job habit, not a trait. Extract the trait if the duplication is real, not before |

### Group F — Verification, Benchmarks & Docs

| ID | Task | Depends on | Est. | Notes |
|----|------|-----------|------|-------|
| P11-F1 | **Full suite green** — `php artisan test` + `vendor/bin/pint --test` | A–E | medium | Full run, never a filtered slice. Record the new number in `progress.md` |
| P11-F2 | **Docs: Telescope DEP deleted, `DEP-004` reused for the Pulse decision** | — | tiny | **DONE** — the 84-line Telescope body was config keys for absent packages; deleted, number reused for Pulse, reversal reasoning moved into DEP-004's Context |
| P11-F3 | **Docs: `monitoring.md`, `observability.md` rewritten** | — | tiny | **DONE** |
| P11-F4 | **Docs: roadmap Phase 11 scope amended** | — | tiny | **DONE 2026-10-15** — the gating decision, the slow-query ladder and the VM runbook moved out of `implementation-roadmap.md` into §3/§7 here, so the detail has one home |
| P11-F5 | **Docs: mechanical sweep of remaining 17 files** | — | tiny | **DONE** — `dependency-matrix.md`, `dependency-governance.md`, `dependency-rules.md`, `logging.md`, `audit-trail.md`, `application-components.md`, DEP-003, `troubleshooting.md`, `retention.md`, `ai-execution-guide.md`, `dependency-map.md`, `feature-matrix.md`, `data-protection.md`, `feature-tracker.md`, `progress.md`, `task-tracker.md` |
| P11-F6 | **Docs: this file registered in `docs/planning/README.md`; `changelog.md` entry for the restructure** | F4 | tiny | The README row already exists; the changelog entry records the roadmap→breakdown move (rule 12: planning changed) |

## 6. Decisions taken — and the one that needed Jaya

**D-1 — Pulse only, layered (Jaya, 2026-10-12).** Horizon is Redis-only and this
project's `.env` runs `redis` while `.env.example` defaults to `database` — a
base template must survive that fork, so Horizon is evaluated out. Vantage was
checked in *its source*, not its README, and carries four problems: its
`viewVantage` gate admits **any authenticated user**, `store_payload` defaults to
`true` (full job payloads carrying export filters and model ids), its config has
**no middleware key** and its provider calls `loadRoutesFrom()` bare — there is
no hook for `feature:vantage` — and it is a 264-star, single-maintainer package
that already changed owners. Layered answer:

| Layer | Mechanism |
|---|---|
| Kill tracking entirely | `VANTAGE_ENABLED` env — zero cost, and what the switch is for |
| Who may read a dashboard | `Gate::define('viewVantage')` → a `queues.view` permission, plus the test |
| Route-level kill switch | only by declaring the routes ourselves (`VANTAGE_ROUTES=false`) — accepted cost, deferred |

Revisit when backlog depth or UI retry is needed **routinely** rather than during
an incident. If it is, six decisions go in first: `queues.view` + `queues.retry`
in `PermissionCatalog`, the gate and its test, the `store_payload` decision, the
route strategy, `vantage:prune` on the scheduler, and ADR +
`dependency-map.md` + `changelog.md`. (`queues.view`/`queues.retry` are worth
adding to the catalogue even before any package arrives — an unused permission
costs one line in a constant list, and `.retry` is separate from `.view` for the
reason `notifications.send_test` is separate from `notifications.manage`:
permission answers *who*, not *how often*.)

**D-2 — Pennant does not fit a vendor route, and faking it is worse.** The
obvious `config(['vantage.enabled' => false])` in `AppServiceProvider::boot()`
half-works, which is the dangerous part: the event listeners re-read the key
inside `handle()` so tracking stops, but `loadRoutesFrom()`, the commands and
the package's own gate have already run — **package providers boot before app
providers**. `/vantage/*` would keep serving, and the only control left standing
would be our own gate. An app provider CAN overwrite `Gate::define()`
deterministically (the manifest is spliced at index 1 — package first, ours
second, always), but that still needs a test that removes the definition and
watches the gate turn green.

**D-3 — Open question, deferred: does superadmin respect the feature flag?**
Today: no, by design. Two switches for one page, answering different questions,
but the combination reads as confusing. Decide separately (see Group B).

## 7. Operational runbooks (moved from `implementation-roadmap.md`)

### Slow-query diagnosis ladder — run in order

1. `GET /up` — not 200 means infra, not queries
2. `top` / `free -m` — CPU, RAM, swap before blaming the database
3. MySQL slow log — catches queries that never reach PHP (lock wait, disk I/O):
   ```sql
   SET GLOBAL slow_query_log = ON;
   SET GLOBAL long_query_time = 0.3;
   SET GLOBAL log_output = 'TABLE';
   ```
   ```sql
   SELECT start_time, LEFT(sql_text, 200), time_to_lock, rows_examined, rows_sent
   FROM mysql.slow_log ORDER BY start_time DESC LIMIT 20;
   ```
   `rows_examined >> rows_sent` means a missing index — most slow queries, and
   found without any application tooling.
4. `DB::listen()` threshold logger — **deferred by decision**, see C2. The
   complement to step 3: covers queries that do reach PHP and measures DB time
   only
5. `EXPLAIN` / `EXPLAIN ANALYZE` each query surfaced by steps 3–4

Pulse is deliberately **absent** from that ladder. The reasoning that originally
applied to Telescope still holds: Pulse records on every request while enabled,
adding write load precisely when the server is already slow. The slow-query
requirement — step 3 — is served by the database's own slow log.

### Toggling Pulse on the VM

```bash
# toggle the flag in the UI at /features (never gated), then re-cache config
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.3-fpm     # without this, opcache keeps the old config

# inspect — requires the pulse.view permission (superadmin passes via Gate::before)
# open /pulse

# retention is Pulse's own config; unlike Telescope there is no manual prune step
```

Note: `.env` is excluded from the rsync sync, so a toggle made there persists
across deploys. The flag itself lives in Pennant's store, not `.env` — which is
why flipping it in the UI survives a deploy.

## 8. Execution order

1. **B1 + B2** (permission and gate — inseparable, one commit)
2. **B3** (health permission + flag + route + the seeder row — the fail-closed trap)
3. **B4** (gating tests — fail loudly if B1–B3 are wrong; also unblocks the P10-B5 copy-reference)
4. **A1 + A3 + C1** (nav entries + the health controller; A stays inert until B lands — correct)
5. **A2** (health page)
6. **E1–E3** (queue conventions — independent, can interleave)
7. **A4, F1, F6** (render gate, full suite, changelog)

## 9. Tracker reconciliation

| ID | Task | Group | Status |
|---|---|---|---|
| P11-B1 | `PULSE` const → `pulse.view` in `PermissionCatalog` | B | PLANNED (was MON-P11-01) |
| P11-B2 | `viewPulse` override in `AuthServiceProvider` | B | PLANNED (was MON-P11-02) |
| P11-A1 | Pulse sidebar entry in `AppMenuComposer::groups()` | A | PLANNED (was MON-P11-03) |
| P11-B4 | `PulsePermissionGateTest` + health gate tests — 8 cases | B | PLANNED (was MON-P11-04) |
| P11-F2 | Docs: Telescope DEP deleted, `DEP-004` reused | F | **DONE** (was MON-P11-05) |
| P11-F3 | Docs: `monitoring.md`, `observability.md` rewritten | F | **DONE** (was MON-P11-06) |
| P11-F4 | Docs: roadmap scope moved into this file | F | **DONE 2026-10-15** (was MON-P11-07) |
| P11-F5 | Docs: mechanical sweep of 17 files | F | **DONE** (was MON-P11-08) |
| P11-E1..E3 | Queue conventions | E | PLANNED — **renumbered from the "MON-P11-05" that collided with the docs task of the same number**; this is the ID `phase-10-audit-trail.md` § P10-E13/E14 points at |
| P11-B3 | `health.view` + `health` flag + route + seeder row | B | PLANNED (new) |
| P11-C1 | `HealthController@index` | C | PLANNED (new) |
| P11-C2 | Slow-query logger | C | **DEFERRED by decision** (new, recorded) |
| P11-A2, A3, A4 | Health page, sidebar entry, render gate | A | PLANNED (new) |
| P11-D1 | Audit trail | D | **N/A by design** — read-only dashboards |
| P11-F1, F6 | Full suite, changelog entry | F | PLANNED (new) |

F2–F5 were already done and independent of the code tasks — the docs describe
the target state, and Groups A–C are what make the code match them.

## 10. Security notes

- Pulse's dashboard exposes queue depth, failed jobs, exception traces, cache
  stats and slow-request detail. Technical information: it stays behind both
  gates, and `pulse.view` must not be handed to a non-technical role.
- The health dashboard exposes the same class of information at lower
  granularity (ok/fail per component). It takes the same two gates for
  consistency, not because its leak is worse.
- `GET /api/v1/health` stays unauthenticated **and stays at `{status,
  timestamp, checks}`** — the moment it gains versions or config it becomes a
  reconnaissance endpoint, and its caller (an uptime probe) needs none of that.
- Pulse ignores its own routes in the ignore list (`config/pulse.php`) so
  monitoring the monitor is not a feedback loop.
- Application code must never depend on Pulse. It is a passive observer.
- Recording is bounded by Pulse's own retention; the `pulse_*` tables are
  ignored by the sensitive-data scrubber in `config/pulse.php`.
- A second dashboard (Vantage) would have the same exposure and none of the same
  gating — its own gate admits every authenticated user and `store_payload`
  keeps full job payloads. If it is ever installed, `queues.view` and
  `store_payload=false` are both decisions, and neither may be left at its
  default (§13).

## 11. Deliberate simplifications

- **One permission per dashboard, not two.** `pulse.manage` / `health.manage`
  would be rows nothing checks.
- **No `DB::listen()` threshold logger** (C2). The MySQL slow log covers it at
  the DB layer and needs no application code. Add the PHP-side logger only when
  a real case appears that the slow log cannot see.
- **No `PULSE_DOMAIN`.** A subdomain would decouple the nav href from the flag
  for no present benefit. Neither env key is set in `.env.example`; if a
  subdomain is ever needed the menu item moves to a literal URL then.
- **No environment condition in the gate.** One predicate, one reason to fail.
- **No observer on the health checks.** Reads do not write audit rows (Group D).

## 12. Phase 11 also owns the notification inbox's API surface (added 2026-10-13)

A route audit comparing the two channel tables found the inversion worth
writing down: **the API could read a user's notification settings and could not
read a single notification.** Which channels are switched on, without any way to
see what they produced.

Closed in `Api\V1\Notification\NotificationInboxController`, ungated on both
channels and for the same reason the web inbox is ungated — a notification
belongs to the person it was sent to, "whose inbox is this" is answered by the
token, and there is no permission name to check. `NotificationInboxAction` is
shared, so marking read invalidates the cached count AND the cached recent list
exactly as the web path does; clearing only the count was the worst version of
that bug and is still the thing to watch for here.

`App\Support\SafeUrl` was extracted in the same change. The same-origin check on
`notification.data['url']` had been a private method on `NotificationInboxAction`
guarding the web view, and the API channel put the same question in a second
place — and a second copy of a security check is the copy that gets forgotten
when one of them is edited. Both call `SafeUrl::actionFor()` now, and
`NotificationPentestTest` probes it over a token with `javascript:`,
`//evil.example`, an absolute URL and a slashless relative path.

One number to know before writing a polling client: the inbox API's ceiling is
the protected group's `throttle:user-state-actions` at **15/minute**, not the web
route's 120. A 30-second poll spends 2 of those.

Written up in `phase-12-api-v1.md` §2.

## 13. Phase 11 owns BOTH dashboards — Pulse and Vantage (added 2026-10-12)

Phase 11 shipped Pulse. The queue-monitoring decision that followed lands here,
not in Phase 10 — `phase-10-audit-trail.md` §11 P10-E13 raised it and filed it
cross-phase, and P10-E14 (the queue conventions) with it. Both are summarised in
Group E and §13 above so the full argument is in one place; the evidence trail
is in §11 there.

### What exists today

| | Status |
|---|---|
| `laravel/pulse ^1.8` | installed. `Recorders\Queues` and `Recorders\SlowJobs` enabled (`config/pulse.php`), `livewire:pulse.queues` + `pulse.slow-jobs` on the default dashboard. Driver-agnostic — it records from Laravel's queue events, so `database` and `redis` behave identically |
| `feature:pulse` + `pulse.view` | flag shipped (P11-B1..B4 pending) |
| `failed_jobs` table | migrated. `queue:failed` / `queue:retry` / `queue:flush` |
| `laravel/horizon` | **not installed, and evaluated out.** Redis-only — no configuration runs it over the `jobs` table. `.env` runs `redis` while `.env.example` defaults to `database`, and a base project template has to survive that fork |
| `storviaio/vantage` | **not installed. This is Phase 11's item, and the recommendation stands: not yet** |

### The gap Vantage would close

Two, and they are real:

1. **Live backlog depth.** Pulse's queues card is a throughput-and-failures graph
   over time, not a "N jobs waiting right now" gauge. On the database driver that
   number currently costs a hand-written `select count(*) from jobs`.
2. **The retry chain.** With `GenerateAuditExport::$tries = 3`,
   `failed_jobs` keeps ONE row per job and overwrites it per attempt, so the
   stack trace that survives is the one that did **not** explain the first two.
   Vantage stores every attempt separately.

### What Vantage costs — checked in its source, not the README

- `Gate::define('viewVantage')` defaults to **any authenticated user**, and
  `store_payload` defaults to `true`, so full job payloads would be readable by
  anyone who can log in. Both need an explicit decision, not a default — job
  payloads in this project carry export filters, model ids and mail arguments.
- `config/vantage.php` has **no `middleware` key** and `VantageServiceProvider`
  calls `loadRoutesFrom()` bare. There is no hook for `feature:vantage`.
- 264 stars / 1 watcher / ~11 months, and the repo has already changed owner
  (`houdaslassi/vantage` → `storviaio/vantage`). For a template, that is a
  bus-factor question rather than a feature question.
- A fourth source of truth beside Pulse, `failed_jobs` and the log; plus a
  migration in the base schema and a `vantage:prune` command to own.

The layered decision is D-1 in §6; the Pennant-does-not-fit analysis is D-2.
