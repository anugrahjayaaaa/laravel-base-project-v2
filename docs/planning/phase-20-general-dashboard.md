# Phase 20 — General App Dashboard & Platform Health Metrics

> Date: 2026-11-06 | Status: **PLANNED** (breakdown written 2026-11-06, no task started, no
> code written) | Branch when scheduled: `feature/phase-20-general-dashboard`
> Purpose: the platform-wide dashboard for staff — who registered, how healthy verification
> and profiles are, who is online, and what the security feed saw — deliberately split off
> the membership analytics, which stay in **Phase 18 Group F**. Owner decision 2026-11-06:
> the general dashboard is its own phase. Owner decision 2026-11-08 (ADR-035): the Phase 19
> and Phase 20 numbers are exchanged — POS & payment runs first as Phase 19, this dashboard
> is Phase 20.
> Dependency chain: **A → B → C → D** (strictly sequential).
> Hard predecessor: Phase 17 (foundation closed). Phase 18 is *not* a predecessor, but the
> two phases touch the same file: `/dashboard` today branches in `DashboardController` to a
> member's own dashboard (delivered 2026-11-06, `P18-E3`). This phase must land inside that
> branch, not beside it — see **D-1**, the load-bearing conflict.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/`. A change to a design decision goes through an ADR in
> `docs/planning/decisions.md`.

---

## 1. Scope

Twelve tasks in four groups.

| Group | Subject | Nature |
|---|---|---|
| A | Dashboard page skeleton, filter partial, render gates | **Build** (UI only) |
| B | Aggregator Action, health/session read, controller | **Build** |
| C | Permission, feature flag, audit decision | **Enforce + verify** |
| D | Feature tests, four gates, tracker reconciliation | **Verify + close** |

**Not in scope:**

- **Member metrics.** Growth vs expirations, renewal rate, tenure, at-risk members, the
  expiry broadcast: **Phase 18 Group F**, on `membership.dashboard`. One screen per audience.
- **Money.** Revenue, tax, profitability, gateway state: **Phase 19's** financial dashboard,
  which is the phase that creates the ledger it reads.
- **A new observability stack.** `laravel/pulse` and the `/health` screen exist and work.
- **A profile data-collection migration.** See D-2 — this is the owner's call, not this
  phase's side effect.
- i18n (`AGENTS.md`: no `lang/` source exists; every string is literal English).

### What the repository already provides

| Need | Existing owner | What this phase adds |
|---|---|---|
| Registration trend | `users.created_at`, `User::scopeActive/Inactive/Locked/PendingVerification` | one grouped date-bucket aggregate per timeframe |
| Email verification health | `users.email_verified_at` (nullable) | a count pair and a percentage |
| Who is online | `SESSION_DRIVER=database` → the `sessions` table, plus `users.last_activity_at`; `AuthController::showSessions` already lists a user's own devices | a count on a recency window, read the same way |
| System health | `HealthController` + `pages/health/index.blade.php` (Phase 11) | a summary strip that links there — **not** a second probe set (D-4) |
| Security feed | `Activity` / spatie activitylog, `FailedLoginAttempt`, `ActivityLogController` | a recent-events feed reading the same tables (D-5) |
| Flag + gate mechanics | `config/pennant.php`, `FeatureFlagSeeder`, `EnsureFeatureIsEnabled`, `PermissionCatalog`, `AppMenuComposer` | one flag, one permission, one menu entry |

---

## 2. Deviations from the brief (measured against the shipped code)

| # | The brief said | The repository does | Consequence |
|---|---|---|---|
| **D-1** | P20-C1: gate the general dashboard on `dashboard.view` | `/dashboard` is the post-login landing route and carries **no `can:`** — and the `member` and `user` system roles hold **no permissions** (`PermissionSeeder` grants them none). `DashboardController` already branches there: a loyalty profile gets its own dashboard (`P18-E3`, shipped 2026-11-06) | `dashboard.view` cannot be put on `/dashboard`: it would 403 every low-privilege account off the page login sends them to, which is exactly the "half-adopted" failure `AGENTS.md` warns about. The gate lands on the **general dashboard's own route** (`/dashboard/overview`, `general.dashboard`), and `/dashboard` keeps choosing content by caller: member profile → member dashboard, `dashboard.view` → the Phase 20 screen, otherwise the existing status card. Recorded as **ADR-034** — a landing-page routing decision, not a task |
| **D-2** | P20-A1: "Profile Completeness Score (%)" and a checklist of Avatar, DOB, Address | `users` has 20 columns; **no `avatar`, no `date_of_birth`, no `address`** (measured, `php artisan db:table users`). The same gap blocks Phase 18 D-9 | A completeness score today can only measure what is stored: name, username, email verified, password changed. Either A1 ships that honest subset (**recommended**, zero migration) or the owner opens the profile-fields migration first — which is PII collection with a retention question attached, and is **O-1**. No row here invents a column |
| **D-3** | P20-B1: `GetGeneralDashboardStatsAction` | `docs/base/architecture/naming-conventions.md` § Action Naming: `{Domain}{Operation}Action`, domain prefix first, enforced by `ActionNamingConventionTest` | Ships as **`Dashboard/GeneralDashboardStatsAction`** with `Dashboard` added to the naming doc's domains table **in the same commit** (the Phase 18 D-7 precedent). Verb-first is red on that gate on day one |
| **D-4** | P20-B2: "System Health & Active Sessions Monitor Service" | Phase 11 already shipped `HealthController` + `pages/health/index.blade.php` + `config/pulse.php` (`feature:pulse` middleware), and `AuthController::showSessions` lists tokens | B2 is a **read of what exists**, feeding two cards and one link into the Phase 11 screen. A second health-probe service would be a third answer to the same question |
| **D-5** | P20-A1: "Audit Trail & Security Event Feed (failed login alerts / locked account warnings)" | `Activity` + spatie activitylog are the trail (`docs/base/features/audit-trail.md`, Phase 10), `FailedLoginAttempt` is the security table, `ActivityLogController` is the read UI | A1 renders a **bounded recent feed from those tables** and links to `/activity-logs`. No new event table, no duplicated log list |
| **D-6** | P20-C3: audit event `dashboard.viewed` | `AGENTS.md`: audits are written **inside the transaction by the Action layer** for state changes. Nothing in the app audits a read — and a dashboard GET is the highest-frequency request in the product | Logging `dashboard.viewed` per page view is a DB write on every landing, an unbounded growth table, and a break with the read-side convention Phase 10 set. **C3 therefore ships no read audit** unless the owner specifically wants one and accepts the volume; the decision is recorded rather than assumed (O-2) |
| **D-7** | P20-A1: `resources/views/pages/dashboard/index.blade.php` | `pages/dashboard.blade.php` exists and is rendered by `DashboardController`; house layout is `pages/<module>/…` | A1 adds the `pages/dashboard/` directory **and deletes `pages/dashboard.blade.php` in the same commit** — two files answering one route is how a stale view survives. `BladeComponentRenderTest` and any `view()` assertion naming the old path moves with it |
| **D-8** | charts (`col-lg-8` line chart) | No chart library is installed (`package.json`: bootstrap, tailwind, vite, `@laravel/multiplex` optional). AdminLTE ships no chart bundle here, and CSP blocks inline JS | The trend renders as the repo's existing shape: a server-side table + CSS bar column (see `features/index.blade.php` cards). A charting dependency is npm + ADR + a CSP-safe external file — **not** a dashboard task. Recorded as O-3 |

---

## 3. Tasks

### Group A — Dashboard UI & page skeleton

#### P20-A1 — General dashboard view (`pages/dashboard/index.blade.php`)
- **Task**: top metric strip (`card border-0 shadow-sm h-100`, the house shape): total
  registered users, email verification health %, profile completeness %, active sessions in
  the window. `col-lg-8` registration growth for the selected timeframe; `col-lg-4` account
  status breakdown (verified vs unverified, active vs locked vs suspended — the three states
  `User::getStatus()` already answers). Operational widgets: the profile checklist limited to
  fields that exist (D-2) and a bounded security feed (D-5).
- **Acceptance**: every number arrives from B1 as a primitive; empty states render, no Blade
  arithmetic, no `route()` in the view — URLs are handed over as variables (the D-4-from-18
  contract); the page degrades to the current status card for a caller without
  `dashboard.view` rather than 403ing (D-1).

#### P20-A2 — Timeframe & filter partial (`resources/views/partials/dashboard-filters.blade.php`)
- **Task**: one GET form — 7d / 30d / 90d / 1y, custom range — rendered from the same
  option list the Action validates against, so the picker cannot offer a value the read
  refuses. Existing house shape for filters: `pages/activity-logs/_filters.blade.php`.
- **Acceptance**: no `input-group` wrapping a validated field (`AGENTS.md` § Traps — the
  sibling `invalid-feedback` selector breaks); the invalid range answers a validation error,
  not a silent fallback.

#### P20-A3 — Render tests (`tests/Feature/Dashboard/GeneralDashboardUiRenderTest.php`)
- **Task**: `view(...)->render()` over fixture arrays in the `MemberUiRenderTest` shape:
  zero-population, full-population, and a missing-URL case.
- **Acceptance**: renders with no DB and no route registered; no inline script; no
  Blade-side `__()` call (i18n does not exist).

### Group B — Aggregation, health read, controller

#### P20-B1 — `Dashboard\GeneralDashboardStatsAction`
- **Task**: one Action returning the whole view model: user counts by state, verification
  percentage, completeness percentage from the columns that exist, registration trend
  bucketed by the timeframe (single grouped aggregate, not one query per bucket), active
  session count on the window, at-a-glance health + security feed rows (D-4, D-5). Cached
  with `Cache::remember` on **primitives only**, key including the timeframe.
- **Acceptance**: query count asserted against a fixture population (no N+1); a cache hit
  returns usable numbers, not `__PHP_Incomplete_Class`; percentages are computed on the
  read side with the denominator stated.

#### P20-B2 — Health & session read
- **Task**: reuse — the `HealthController` probe results and a `sessions` table count. No
  new probe, no new driver, no second store.
- **Acceptance**: the cards agree with `/health` and `/sessions` for the same moment; the
  session count excludes the `auth:web,sanctum` guard's own bookkeeping differences rather
  than inventing a definition.

#### P20-B3 — `Web/V1\GeneralDashboardController`
- **Task**: thin — resolve the request, call B1, return the view. `DashboardController`
  gains the caller-branch described in D-1; it does not learn any arithmetic.
- **Acceptance**: no business logic in the controller (`AGENTS.md`), `Dashboard` domain row
  added to the naming doc in the same commit (D-3).

### Group C — RBAC, flag, audit

#### P20-C1 — Permission `dashboard.view` + route gating
- **Task**: `dashboard.view` in `PermissionCatalog` + `PermissionSeeder`'s role matrix (the
  one-dot rule from Phase 18 D-6 applies: `dashboard.view` is legal, `dashboard.overview.view`
  would not be), `->can('dashboard.view')` on the new route, one `AppMenuComposer` entry.
- **Acceptance**: a zero-permission caller 403s the new route and still lands on a working
  `/dashboard`; a caller with it sees the screen and exactly one active sidebar item
  (`GateDUiGatingTest` shape).

#### P20-C2 — Feature flag `general_dashboard`
- **Task**: declared in `config/pennant.php` **and** seeded by `FeatureFlagSeeder` — a
  declared flag is not an active flag, the `database` store is fail-closed (`AGENTS.md`).
  `feature:general_dashboard` on the route group; the menu entry drops with it.
- **Acceptance**: flag off → **403, not 404** (Phase 12/18 D-1 precedent), including for a
  superadmin, with no bypass.

#### P20-C3 — Audit decision
- **Task**: record the outcome of D-6 in the Event Map (`docs/base/features/audit-trail.md`)
  — either "reads are not audited, by convention" or the owner's explicit choice to audit the
  dashboard view.
- **Acceptance**: whichever way it goes, it is a written decision in `decisions.md`, not a
  silent one in a controller.

### Group D — Tests and closure

#### P20-D1 — `tests/Feature/Dashboard/GeneralDashboardTest.php`
- **Task**: HTTP over the real gates: permission matrix, flag off, each timeframe returning
  its own window, the trend against a seeded population, the empty-database page, and a
  `user`/`member` account landing on a working `/dashboard` (D-1's regression test).
- **Acceptance**: sabotage-verified where a wrong join or a wrong denominator would still
  render green; `tests/Feature/Dashboard` + the Phase 18 member rows stay green.

#### P20-D2 — Gates and reconciliation
- **Task**: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test
tests/Feature/Dashboard tests/Feature/Member tests/Arch`, `route:list`, `schedule:list`,
  `npm run build`; flip the rows in `task-tracker.md` on evidence; update `progress.md`,
  `implementation-roadmap.md`, `qa-tracker.md`, `docs/base/features/`.
- **Acceptance**: counts quoted from the run's own output; nothing stamped DONE without an
  assertion someone opened and read; docs match code.

---

## 4. Execution order and per-group gates

`A → B → C → D`, one group at a time, `IN_PROGRESS → REVIEW → DONE`, never skipping REVIEW.

| Gate | Must be true before the next group starts |
|---|---|
| A | fixture render green, no DB/route in Blade, flag declared **and** seeded, Pint clean |
| B | query counts measured, no N+1, cache returns primitives, domains table updated in the same commit |
| C | every refusal measured at its status code (403 for permission, 403 for flag), the low-privilege landing path proven, Event Map updated |
| D | four gates exit 0, trackers reconciled, deferred evidence carried into `progress.md` § *Known Issues* |

---

## 5. Open owner decisions

| # | Question | Options | Blocking? |
|---|---|---|---|
| O-1 | **D-2** — profile completeness needs fields that do not exist | (a) score only what is stored (recommended, no migration); (b) open a profile migration (avatar, DOB, address) as its own phase, with PII/retention attached | Blocks A1's checklist card only — the rest ships |
| O-2 | **D-6** — audit the dashboard read? | (a) no read audit, keep the Phase 10 convention (recommended); (b) audit `dashboard.viewed`, accepting one write per landing | Not blocking if (a); A1/D1 proceed either way |
| O-3 | **D-8** — charts | (a) server-rendered bars/tables (recommended, zero dependency, CSP-safe); (b) add a chart library via npm + ADR + published assets | Not blocking — A1 ships (a) today |
