# Phase 11 — Monitoring & Observability

> Date: 2026-10-02 | Branch: feature/general-fixes | Status: PLANNED (docs cleared, code not started)
> Purpose: execution breakdown for the technical observability layer, replacing
> the Telescope + Periscope stack with Laravel Pulse, and gating it with a
> permission the way every other module in the admin is gated.
> Dependency chain: flag already shipped (Phase 7, commit `b08b8b4`) → permission
> → gate override → nav entry → tests.
> Decisions locked 2026-10-02: **permission = `pulse.view`** (not
> `pulse.manage`), **flag and permission both stay**, **superadmin keeps access
> via `Gate::before`**, **admin inherits via `PermissionCatalog::all()`**.

---

## What already shipped (audit 2026-10-02)

| Fact | Where | Verified |
|---|---|---|
| `laravel/pulse: ^1.8` in `require` | `composer.json:12` | yes |
| `laravel/telescope`, `periscope` absent from vendor | — | yes, not installed |
| `config/pulse.php` published | `config/pulse.php` | yes |
| `pulse` feature flag declared | `config/pennant.php:76` | yes |
| flag enforced on the route group | `config/pulse.php:137` (`feature:pulse` in `pulse.middleware`) | yes |
| flag-off returns 403 | `tests/Feature/FeatureFlag/PulseFeatureGateTest.php` | yes, 3 tests |
| `pulse_tables` migration | `database/migrations/2026_09_29_043034_create_pulse_tables.php` | yes |

So the flag half of this phase is done. What is missing is the **permission**
half, plus the docs, which still describe a stack that was deleted in commit
`85384b4`.

## The finding that shapes the design

Pulse already defines the `viewPulse` gate. At
`vendor/laravel/pulse/src/PulseServiceProvider.php:100`:

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

---

## Design

### 1. Permission name — `pulse.view`

Not `pulse.manage`. Pulse's dashboard is read-only observability; there is
nothing to mutate, so a second action would be a permission no `can()` call ever
checks — exactly the "row that lies in the permissions UI and grants nothing"
that `PermissionCatalog`'s own note at line 75 warns against.

One permission, one action. `PermissionCatalog::grouped()` groups on the prefix,
so `pulse.view` appears under a **pulse** heading in the role matrix with no
other edit.

Placement: a new `PULSE` const appended to `all()`, following the file's own
rule — "Add each group in the same commit that adds the page it guards." This is
that commit.

```php
/** @var array<int, string> */
private const PULSE = [
    'pulse.view',
];
```

### 2. Gate definition — `AuthServiceProvider`, next to `configureSuperAdmin()`

```php
Gate::define('viewPulse', fn (User $user): bool => $user->can('pulse.view'));
```

Three properties worth stating, because each is a way this can silently break:

**Registering after boot is required, and `AuthServiceProvider` is the right
home.** Pulse uses `callAfterResolving(Gate::class, ...)`, so it defines its gate
at resolve time. `Gate::define()` overwrites by name, so ours wins — but only if
it runs later. Pulse's own provider boots before ours, and `AuthServiceProvider`
already owns the neighbouring authorization concern, so the ordering is correct
without any extra work.

**`Gate::before` is untouched.** Superadmin keeps access without a permission
row, which is the invariant `PermissionSeeder`'s docblock states: superadmin's
access comes from the before-rule, so adding a permission never requires
re-seeding the role and can never be stripped by a role edit.

**Do not call `Pulse::auth()`.** It does not exist in 1.8 — `auth()` was removed
in favour of the gate. Verified against vendor source.

### 3. Seeder matrix — no change

`PermissionSeeder::matrix()` grants `SystemRole::ADMIN => PermissionCatalog::all()`,
so **admin inherits `pulse.view` automatically**. `user` stays empty.
`superadmin` stays absent by design. Zero edits to `PermissionSeeder`.

That means admin gains Pulse access the moment the permission is seeded, which is
worth deciding deliberately rather than by accident:

| Option | What it costs |
|---|---|
| **(a) admin gets it** | free, consistent with "admin holds the whole catalogue" — **chosen** |
| (b) superadmin only, until a devops role is hand-picked | needs an explicit exclusion in `matrix()`, the first time that method holds a subset |

Chose (a). Revisit if Pulse should be restricted to a smaller group than admin.

### 4. Two independent gates — keep both

`config/pulse.php` already carries `feature:pulse` in the `pulse` middleware
group. After this change `/pulse` needs **flag on AND permission held**. Both
failures are 403, so a caller cannot tell which one fired — consistent with the
Decision-2 reasoning already recorded in `phase-7-feature-flags.md`. Do not
collapse them into one check.

The flag is the deploy-time kill switch; the permission is per-role. They answer
different questions.

### 5. Sidebar entry — added

`Route::has('pulse')` is true (vendor registers it), and
`AppMenuComposer`'s active matching uses `fnmatch($item['active'], $name)` with
route name `pulse`, so `'active' => 'pulse'` works.

```php
[
    'label' => 'Pulse',
    'icon' => 'fas fa-heart-pulse',
    'route' => 'pulse',
    'active' => 'pulse',
    'permission' => 'pulse.view',
    'feature' => 'pulse',
],
```

This is the first menu entry pointing at a vendor URL. Two sub-decisions:

**URL.** `route('pulse')` resolves the current domain + path, so the composer
builds a working `href`. But `PULSE_DOMAIN` / `PULSE_PATH` env can move it off
`/pulse`; a subdomain would break the menu link while the flag keeps working.
Accepted — neither env key is set in `.env.example`, and if a subdomain is ever
needed the item moves to a literal URL then.

**Active highlight.** `'pulse'` is a loose glob against every route name, and is
safe only because no other route name contains that substring. Verified against
`route:list`.

A dashboard you cannot navigate to is the same "switch reads Inactive over a
live page" problem Phase 7 fixed for the flag, so the entry goes in. It is
separable from the permission — the permission works with or without the nav
entry.

### 6. Tests — one file, four cases

`tests/Feature/PulsePermissionGateTest.php`:

| # | Case | Proves |
|---|---|---|
| 1 | non-superadmin role holds `pulse.view`, flag on → `/pulse` 200 | the permission opens the gate |
| 2 | same viewer without the permission → 403 | it is not decoration |
| 3 | superadmin, **no permission rows at all** → 200 | `Gate::before` survives the override |
| 4 | flag off + permission held → 403 | the two gates stay independent |

Case 3 is the one that catches a mistake here. Define the gate and accidentally
lose superadmin access, and cases 1, 2 and 4 all still pass.

---

## Open decisions (not resolved here)

**Does superadmin respect the feature flag?** Today: no, by design — Phase 7
established no flag bypass, and it is tested. The consequence is an operator who
sees "Pulse Dashboard — Inactive" on `/features` can still reach `/pulse` as
superadmin. Two switches for one page, answering different questions, but the
combination reads as confusing. Decide separately; do not bundle it with this
work.

**Keep `environment('local')` as a second condition?** e.g.
`local || can('pulse.view')`. Would keep local development frictionless if the
definition were ever removed. Skipped — the permission already covers local
(whoever holds it), and two conditions is one more thing to reason about.

---

## Docs debt — CLEARED (2026-10-02)

The stack swap landed in `85384b4` with no docs pass: **192 references to
Telescope/Periscope across 27 files**, all describing packages that were no
longer installed. Swept in this same change.

**Result: 27 files → 15, and every remaining mention is deliberate.**

### Structural rewrites

| File | Change |
|---|---|
| [DEP-004](../base/architecture/decision-records/DEP-004-laravel-pulse-observability.md) | **new** — Laravel Pulse, carrying the reversal reasoning. Holds the number the withdrawn Telescope record had released |
| `decision-records/DEP-004-…-telescope-…md` | **deleted.** Its 84-line body was config keys, a provider name and a route for packages no longer installed; keeping even a stub left a file titled "Telescope" documenting an absence |
| `architecture-decisions.md` | DEP list back to `001–007`; ADR-008/013 bodies corrected |
| `decisions.md` | ADR-008 body corrected; **ADR-020 marked superseded** (its subject no longer exists) |
| `base/features/monitoring.md` | rewritten for Pulse; Periscope section deleted, not replaced |
| `base/infrastructure/observability.md` | rewritten; Periscope section deleted |
| `base/dependencies/overview.md` | two sections (Telescope, Periscope) collapsed into one Pulse section; table row, TOC, section lists |
| `planning/implementation-roadmap.md` | Phase 11 scope amended (below); Phase 1/2 package lists |
| `planning/phase-11-…md` | this file |

### Mechanical corrections (17 files)

`dependency-matrix.md`, `dependency-governance.md`, `dependency-rules.md`,
`logging.md`, `audit-trail.md`, `application-components.md`, DEP-003,
`troubleshooting.md`, `retention.md`, `ai-execution-guide.md`,
`dependency-map.md`, `feature-matrix.md`, `data-protection.md`,
`feature-tracker.md`, `progress.md`, `task-tracker.md`.

### Remaining mentions, and why each stays

| File | Why |
|---|---|
| `DEP-004` (Pulse) | its Context section explains why Telescope was dropped — that reasoning has nowhere else to live |
| `changelog.md` | dated history of what was written when; not rewritten |
| `decisions.md` (ADR-020 body) | superseded record, kept as narrative |
| `phase-6-rbac.md` | "Telescope-free manual count" as a performance method — historical |
| `phase-7-feature-flags.md`, `task-tracker.md`, `progress.md`, `feature-tracker.md`, `implementation-roadmap.md`, `monitoring.md`, `observability.md`, `overview.md` | cite the commit that removed them, or the absent package |

Rule applied: **a dated narrative is not a stale fact; stale instructions are,
and a stub about an absent package is neither.** `changelog.md` keeps its history
— it records when things changed, which is what a changelog is for. The Telescope
DEP did not qualify on either count: 84 lines of config keys, a provider name and
a route for packages absent from `vendor/`, which reads as setup instructions. A
33-line stub was not enough, because a file titled "Telescope" exists only to say
"we no longer use Telescope" — and DEP-004 already says that. Deleted, the number
reused for the Pulse decision, and the reasoning that justified the swap moved
into that record's Context section where it is actually needed.

### Prod gating, restated for Pulse

| Decision | Rationale |
|---|---|
| `pulse` stays a feature flag | deploy-time kill switch; one switch per page, permission handles roles |
| `laravel/pulse` in `require`, not `require-dev` | Pulse is production observability here, not a dev-only debugger |
| No `environment('local')` in the gate | the permission replaces it; local devs hold the permission like anyone else |
| No `Pulse::auth()` call | removed in 1.8; the gate is the supported hook |

---

## Task Tracker Reconciliation

| ID | Task | Group | Status |
|---|---|---|---|
| MON-P11-01 | Add `PULSE` const to `PermissionCatalog`, append to `all()` | permission | PLANNED |
| MON-P11-02 | Define `viewPulse` in `AuthServiceProvider` | gate | PLANNED |
| MON-P11-03 | Sidebar entry in `AppMenuComposer::groups()` | nav | PLANNED |
| MON-P11-04 | `PulsePermissionGateTest` — 4 cases | test | PLANNED |
| MON-P11-05 | Docs: Telescope DEP deleted, `DEP-004` reused for the Pulse decision | docs | **DONE** |
| MON-P11-06 | Docs: `monitoring.md`, `observability.md` rewritten | docs | **DONE** |
| MON-P11-07 | Docs: roadmap Phase 11 scope amended | docs | **DONE** |
| MON-P11-08 | Docs: mechanical sweep of remaining 17 files | docs | **DONE** |

MON-P11-05 through 08 are already done and independent of the code tasks — the
docs describe the target state, and the code now matches them.

---

## Execution Order

1. MON-P11-01 + 02 (permission and gate — inseparable, one commit)
2. MON-P11-04 (tests — fails loudly if 01/02 are wrong)
3. MON-P11-03 (nav)

---

## Phase 11 also owns the notification inbox's API surface (added 2026-10-13)

A route audit comparing the two channel tables found the inversion worth
writing down: **the API could read a user's notification settings and could not
read a single notification.** Which channels are switched on, without any way to
see what they produced.

Closed in `Api\V1\Notification\NotificationInboxController`, ungated on both
channels and for the same reason the web inbox is ungated — a notification belongs
to the person it was sent to, "whose inbox is this" is answered by the token, and
there is no permission name to check. `NotificationInboxAction` is shared, so
marking read invalidates the cached count AND the cached recent list exactly as
the web path does; clearing only the count was the worst version of that bug and
is still the thing to watch for here.

`App\Support\SafeUrl` was extracted in the same change. The same-origin check on
`notification.data['url']` had been a private method on `NotificationInboxAction`
guarding the web view, and the API channel put the same question in a second
place — and a second copy of a security check is the copy that gets forgotten when
one of them is edited. Both call `SafeUrl::actionFor()` now, and
`NotificationPentestTest` probes it over a token with `javascript:`,
`//evil.example`, an absolute URL and a slashless relative path.

One number to know before writing a polling client: the inbox API's ceiling is
the protected group's `throttle:user-state-actions` at **15/minute**, not the web
route's 120. A 30-second poll spends 2 of those.

Written up in `phase-12-api-v1.md` §2.

---

## Phase 11 owns BOTH dashboards — Pulse and Vantage (added 2026-10-12)

Phase 11 shipped Pulse. The queue-monitoring decision that followed lands here,
not in Phase 10 — `phase-10-audit-trail.md` §11 P10-E13 raised it and filed it
cross-phase, and P10-E14 (the queue conventions) with it. Both are summarised
below so the full argument is in one place; the evidence trail is in §11 there.

### What exists today

| | Status |
|---|---|
| `laravel/pulse ^1.8` | installed. `Recorders\Queues` and `Recorders\SlowJobs` enabled (`config/pulse.php`), `livewire:pulse.queues` + `pulse.slow-jobs` on the default dashboard. Driver-agnostic — it records from Laravel's queue events, so `database` and `redis` behave identically |
| `feature:pulse` + `pulse.view` | shipped (MON-P11-01..04) |
| `failed_jobs` table | migrated. `queue:failed` / `queue:retry` / `queue:flush` |
| `laravel/horizon` | **not installed, and evaluated out.** Redis-only — no configuration runs it over the `jobs` table. `.env` runs `redis` while `.env.example` defaults to `database`, and a base project template has to survive that fork |
| `storviaio/vantage` | **not installed. This is Phase 11's item, and the recommendation stands: not yet** |

### The gap Vantage would close

Two, and they are real:

1. **Live backlog depth.** Pulse's queues card is a throughput-and-failures graph
   over time, not a "N jobs waiting right now" gauge. On the database driver that
   number currently costs a hand-written `select count(*) from jobs`.
2. **The retry chain.** With `GenerateAuditExport::$tries = 3` (§11 P10-E12),
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

### Pennant does not fit a vendor route

The obvious move — `config(['vantage.enabled' => false])` inside
`AppServiceProvider::boot()` — was evaluated and rejected. It half-works, which
is the dangerous part: the event listeners re-read the key inside `handle()` so
tracking does stop, but `loadRoutesFrom()`, the four commands and the package's
own `Gate::define('viewVantage')` have already run, because **package providers
boot before app providers**. `/vantage/*` would keep serving and the only control
left standing would be our own gate. Worse, `Feature::inactive()` in `boot()` is
a Pennant store query on every request — the exact thing
`AppServiceProvider::bindTokenExpirations()`'s comment forbids, with the
database store fail-closed behind `FeatureFlagSeeder`.

An app provider CAN overwrite `Gate::define('viewVantage')`, deterministically —
`Application::registerConfiguredProviders()` splices the package manifest at
index 1, so the package boots first and ours second, always. That is a property
of Laravel 13 and it still needs a test that removes the definition and watches
the gate turn green, the way the `User::can()` `canMemo` trap needed one.

### Decision (Jaya, 2026-10-12): Pulse only, layered

| Layer | Mechanism |
|---|---|
| Kill tracking entirely | `VANTAGE_ENABLED` env — zero cost, and what the switch is for |
| Who may read a dashboard | `Gate::define('viewVantage')` → a `queues.view` permission, plus the test |
| Route-level kill switch | only by declaring the routes ourselves (`VANTAGE_ROUTES=false`) — accepted cost, deferred |

Revisit Vantage when backlog depth or UI retry becomes something an operator
needs **routinely** rather than during an incident. If it does, six decisions go
in first: `queues.view` + `queues.retry` in `PermissionCatalog`, the gate and its
test, the `store_payload` decision, the route strategy, `vantage:prune` on the
scheduler, and ADR + `dependency-map.md` + `changelog.md`.

`queues.view` and `queues.retry` are worth adding to `PermissionCatalog` even
before any package arrives — Pulse needs the same permission, and an unused
permission costs one line in a constant list. `queues.retry` is separate from
`queues.view` for the reason `notifications.send_test` is separate from
`notifications.manage`: permission answers *who*, not *how often*.

### MON-P11-05 — queue conventions (P10-E14, cross-referenced)

All three are upstream of every dashboard above, cost nothing, and are what makes
Horizon, Vantage or Pulse useful on day one. Retrofitted onto forty jobs, queue
naming is expensive; a wrong dashboard choice is not.

1. **Named queues behind constants.** Every job goes to `default` today.
2. **Every job declares `$tries`, `$timeout`, `$backoff`, `$maxExceptions`.**
   Vantage's retry chain is only meaningful because these are declared.
3. **Every job has `failed()` and logs with `request_id`.** `queue.md:79` already
   mandates it; today it is a per-job habit, not a trait.

---

## Security Notes

- Pulse's dashboard exposes queue depth, failed jobs, exception traces, cache
  stats and slow-request detail. It is technical information and must stay
  behind both gates; `pulse.view` must not be handed to a non-technical role.
- Pulse ignores its own routes in the ignore list (`config/pulse.php:226,243`)
  so monitoring the monitor is not a feedback loop.
- Application code must never depend on Pulse. It is a passive observer.
- Recording is bounded by Pulse's own retention; the `pulse_*` tables are
  ignored by the sensitive-data scrubber at `config/pulse.php:216`.
- A SECOND dashboard has the same exposure and none of the same gating. Vantage's
  own gate admits every authenticated user and its `store_payload` default keeps
  full job payloads — which in this project carry export filters, model ids and
  mail arguments. If it is ever installed, `queues.view` and `store_payload=false`
  are both decisions, and neither may be left at its default.

## Deliberate Simplifications

- **One permission, not two.** `pulse.manage` would be a row nothing checks.
- **No `DB::listen()` logger yet.** The MySQL slow log covers it at the DB layer
  and needs no application code. Add the PHP-side threshold logger only when a
  real case appears that the slow log cannot see.
- **No `PULSE_DOMAIN`.** A subdomain would decouple the nav href from the flag
  for no present benefit.
- **No environment condition in the gate.** One predicate, one reason to fail.
