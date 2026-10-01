# Phase 7 — Feature Availability & Feature Flags

> Date: 2026-10-01 | Branch: feature/phase-7-feature-availability | Status: PLANNED
> Purpose: hyper-detailed task breakdown for Phase 7, UI-first then logic
> (Group A → B → C → D → E strict sequential).
> Scope: flag catalogue (Pennant), kill-switch enforcement at route + menu,
> management UI, audit trail.
> Dependency chain: A → B → C → D → E. A cannot skip to C.
> Decisions locked 2026-10-01: **engine = Laravel Pennant** (no custom table),
> **disabled flag → 404**, **permissions = `features.view` / `features.manage`**.

---

## Existing Foundation (audit 2026-10-01)

### Already in place
- `laravel/pennant: ^1.26` installed + auto-discovered — **never used by app code**
- `features` table migrated (`2026_09_15_175615_create_features_table.php`) — Pennant's schema: `name`, `scope`, `value` (text), unique `(name, scope)`. **Zero rows.**
- `@feature` / `@featureany` Blade directives registered by the package (`PennantServiceProvider.php:48-57`)
- `Laravel\Pennant\Middleware\EnsureFeaturesAreActive` present — **no alias, never applied**, aborts **400**
- `PENNANT_STORE=database` (`.env.example`), driver swappable via `vendor/laravel/pennant/config/pennant.php:19`
- `App\Support\PermissionCatalog` — 21 permissions; `features.*` deliberately absent (`:68-72`)
- `App\Models\Concerns\Auditable` — `->audit($event, $causer, $properties)`, reads `source` from `request()->is('api/*')`
- `<x-ui.confirm-action>` + `ACTION_CONFIG` (11 keys); component now accepts `tag="input"`
- `AppMenuComposer` — static groups, filters on `permission`, drops items whose route is missing
- `bootstrap/app.php:47-50` — aliases only `password.change.required`, `account.state`. **No `feature:` alias.**
- Read-only-when-cannot-manage pattern: `pages/settings/index.blade.php:33` `@can('settings.manage')` … `:574` `@else` … `:609` `@endcan`
- Disabled-feature 404 precedent: `AuthController.php:320` / `:342` `abort_unless(registration_enabled, 404)`, `routes/web.php:43-44` comment says "a disabled feature should 404, not 403"
- `tests/Feature/ConfirmActionUsageTest.php` — 8 pages, hand-built-trigger ban, `data-action-type` must exist in `ACTION_CONFIG`

### Phase 7 gap — what's missing
| Gap | Notes |
|-----|-------|
| No flag declared anywhere | no `config/pennant.php`, no `Feature::define()` in `app/` |
| No `feature:` middleware alias | `->middleware('feature:{slug}')` fails with "Route middleware [feature] not defined" until `P7-C2` |
| No flag gates any route | `grep feature routes/` → one comment, no middleware |
| No flag hides any menu item | `AppMenuComposer::groups()` has no feature awareness |
| No management page | no route, controller, view, or `/features` path exists |
| `features.*` unseeded | `PermissionCatalog.php:68-72` reserves it for this phase |
| `docs/base/ui/ui-architecture.md:158` | links `./feature-flags.md` — file is at `docs/base/features/feature-flags.md`. Broken link. |

### Decision 1 — engine: Pennant (LOCKED 2026-10-01)

Brief specified a custom `features` table (`key`/`name`/`description`/`group`/`is_enabled`),
`App\Models\Feature`, and a `FeatureManager`. **Pennant is used instead; no custom table.**

The table name is already taken by Pennant with an incompatible schema, so the custom
variant would have needed a *second* table (`feature_flags`) beside it — two writers
for "is this module on". Dropped as a consequence, not deferred:

| Brief item | Verdict |
|---|---|
| P7-B1 migration `create_features_table` | **dropped** — table exists, schema is Pennant's |
| P7-B2 `App\Models\Feature` + `Cache::rememberForever` busting | **dropped** — Pennant owns persistence + request-level resolution |
| P7-B3 `FeatureManager::isEnabled()` | **dropped** — `Laravel\Pennant\Feature::active($key)` is the same call |
| P7-B3 `FeatureSeeder` | **replaced** by `config/pennant.php` + `FeatureFlagSeeder` (`P7-B5`) |
| P7-C1 `EnsureFeatureIsEnabled` | **kept, rewritten** — own class, 404 not 400 (Decision 2) |
| P7-C2 register `@feature` | **dropped** — already registered by the package (`P7-C3` is verification) |

### Decision 2 — 404, not 400 (LOCKED 2026-10-01)

Pennant ships the middleware this phase needs, with one wrong default:

```php
// vendor/laravel/pennant/src/Middleware/EnsureFeaturesAreActive.php:26-28
return static::$respondUsing
    ? call_user_func(static::$respondUsing, $request, $features)
    : abort(400, $error);
```

**What each status tells the caller**

| Status | Meaning in HTTP | What a client does with it | Truthful for a disabled module? |
|---|---|---|---|
| **400** Bad Request | the request itself is malformed | retry the same request, fix the payload, log a bug | **no** — the request was well-formed; the server declined to serve it |
| **403** Forbidden | authenticated, understood, not allowed | retry with different credentials | **no** — nobody is forbidden; the resource does not exist right now |
| **404** Not Found | no such resource | stop asking, fall back, do not retry | **yes** — the module is invisible, and that is the intent |

A kill-switch exists so a module *disappears*. 400 says "you built the request
wrong", which sends an integrator hunting for a bug in their own code that is not
there. 403 says "you are not allowed", which invites an escalation request —
exactly the wrong answer for a flag an operator turned off.

**What this repo already does.** `docs/base/features/feature-flags.md:70` — "404 or
feature disabled response". `routes/web.php:43-44` — "a disabled feature should 404,
not 403". `AuthController.php:320` and `:342` — `abort_unless(registration_enabled, 404)`.
`Api\V1\Auth\RegisterController.php:32` mirrors it. The brief's `P7-C1` says
`NotFoundHttpException`. Three sources in-repo and the brief all say 404; Pennant's
default is the outlier.

**Measured, both statuses, through this app's API exception handler** (`bootstrap/app.php:128-147`):

```
GET /probe404 → abort(404) → 404  {"message": ""}
GET /probe400 → abort(400) → 400  {"message": ""}
```

Both render, both are distinguishable by status. The handler's `4xx` branch sits
above the `NotFoundHttpException` branch, so the `code` is `HTTP_ERROR` either way —
the **status** is the only thing that carries the distinction, which is the whole
reason it has to be right. Pennant is not aliased; `P7-C1` writes the project's own
middleware.

**The cost is 5 duplicated lines.** Aliasing Pennant's and overriding it globally with
`EnsureFeaturesAreActive::whenInactive()` would also change the response on every other
route using it. `ponytail:` the duplication is deliberate — a different *response*, not
duplicate logic. Revisit if Pennant ever supports per-middleware responses.

### Decision 3 — permission names (LOCKED 2026-10-01)

| Source | Says |
|---|---|
| The brief | `features.view`, `features.manage` |
| `docs/base/features/feature-flags.md:74-76` | `features.manage`, `features.view` |
| `PermissionCatalog` grouping (`Str::before($name, '.')`) | `features.manage` self-groups under a `features` heading with no extra entry |
| Existing pattern | `users.*`, `roles.*`, `permissions.*`, `settings.*` — all plural |

**`features.view` + `features.manage`.** The singular `feature.manage` in the
`laravel-feature-flags` skill describes a different repo variant and is the outlier here.

---

## The two facts that shape Group B

### Pennant gotcha — declaring a flag does not activate it

With the `database` driver, `Feature::active($slug)` resolves against a row in `features`.
**No row → false, fail-closed.** A flag added to `config/pennant.php` and wired to a route
produces a route that 404s for everyone — superadmin included — until someone runs:

```
php artisan tinker --execute="Laravel\Pennant\Feature::activate('users');"
```

Every flag must get **both** a config entry **and** an activation. `P7-B5` makes that
non-forgettable; `P7-E1` proves it (a declared-but-unactivated flag 404s, as a test).

### Collision — `registration` is already gated by a SystemSetting

`registration_enabled` is a `system_settings` row (`SystemSettingSeeder.php:71`) read in
four places (`AuthController.php:52`, `:320`, `:342`, `Api\V1\Auth\RegisterController.php:32`)
and toggled from `pages/settings/index.blade.php:511-514`. The brief seeds a `registration`
flag; that would be two writers for one question, and whichever was written last wins
with neither knowing about the other.

**Dropped from the flag list.** The feature is already enforced at every entry point;
gating it twice is worse than gating it once. Same reasoning retires the brief's
`telescope` key — `laravel/telescope` was removed in `85384b4`; observability is now
`laravel/pulse`.

---

## Group A — UI Only ✅ DONE (uncommitted)

> Goal: every Blade surface for feature management exists and matches the design system
> **before** any route, action, or gate is written.
> Depends: Phase 5 (design system, `confirm-action`, shared components).
> Blocks: Group B (the view contract is fixed here), transitively C/D/E.
> Constraint: **no controller, no action, no route, no migration, no query in Blade.**

### UI reference set (read before writing any markup)

| Reference | Path | What to copy |
|---|---|---|
| Read-only index | `resources/views/pages/permissions/index.blade.php` | metric strip, table, badges, empty state |
| Read/write split | `pages/settings/index.blade.php:33` … `:609` | `@can(manage)` … `@else` read-only … `@endcan` |
| Card + header | `pages/permissions/index.blade.php:58-62` | `card-header bg-transparent border-bottom py-3` |
| Confirmation | `resources/views/components/ui/confirm-action.blade.php` | the component, never hand-built `data-*` |
| Design system | `docs/base/ui/design-system.md` | skeletons §1–5, §Pagination, §Confirmation Modal, forbidden classes |
| UI architecture | `docs/base/ui/ui-architecture.md` | partial naming, view-does-not-query rule |

### Hard UI rules
- **No queries in Blade.** Reads `$featureGroups`, `$totalFeatures`, `$enabledCount`, `$disabledCount` only.
- Forbidden classes: `bg-white`, `bg-light`, `card-body` without `p-4`, standalone Back button.
- The switch is a **confirmation trigger**, not an auto-submitting input — `design-system.md`
  §Confirmation Modal Convention names "feature flag changes" explicitly. `<x-ui.confirm-action tag="input">`
  turns a `<button>` trigger into `<input type="checkbox">`.
- A viewer who cannot manage sees **badges, not disabled switches** — same reasoning as
  `pages/settings`: a control that looks editable and silently discards input is worse than an absent one.
- Accessibility: `role="switch"` + a label naming the flag and the action; the `Active`/`Inactive`
  badge carries state so colour is not the only indicator.
- i18n: direct static text (`ai-execution-guide.md` rule 2).

| ID | Task | Depends | Status |
|----|------|--------|--------|
| P7-A1 | `resources/views/pages/features/index.blade.php` — content-header (`Feature Flags` / `Manage global module and feature availability` / breadcrumb `Dashboard > Feature Flags`); 4 `card border-0 shadow-sm` metric cards (Features / Enabled / Disabled / Modules); one card per module group, `card-header` = group name, `card-body p-4`, `table-responsive`, columns Feature / Key `<code>` / Description / Status / Toggle; session flash alerts | — | ✅ DONE |
| P7-A2 | `resources/views/components/ui/feature-toggle.blade.php` — manager → `form-check form-switch` + `<x-ui.confirm-action tag="input">`; non-manager → `<x-ui.badge variant="success\|neutral">`. Carries `role="switch"`, `aria-label`, and the intended new state in the toggle URL's `enabled` query param so the shared modal driver submits it without learning about flags | A1 | ✅ DONE |
| P7-A3 | `<x-ui.confirm-action>` gains a `tag` prop so a trigger can be `<input type="checkbox">` rather than `<button>`. The 8 existing button triggers must render byte-identically | A2 | ✅ DONE |
| P7-A4 | Render gate — render the page twice from a hand-built array (manager + viewer): no exception, 0 queries, no forbidden class, switch present for manager and absent for viewer | A1–A3 | ✅ DONE |

**Deliverables (shipped, uncommitted):**
- `resources/views/pages/features/index.blade.php` (new)
- `resources/views/components/ui/feature-toggle.blade.php` (new)
- `resources/views/components/ui/confirm-action.blade.php` — `tag` prop + `@if ($tag === 'input')` branch
- Check: `/Users/anugrahjayasakti/.hermes/cache/scratch/phase7-groupA-render.php` (renders both branches,
  asserts all six driver attributes on every switch)

**View-data contract (handed to Group B, never queried by the view):**

| View | Variables |
|---|---|
| `pages.features.index` | `$featureGroups` (`['Users' => [['slug','label','description','enabled'], …]]`), `$totalFeatures`, `$enabledCount`, `$disabledCount` |

`slug`/`label`/`description`/`group` from `config/pennant.php`; `enabled` from
`Feature::active($slug)`. Both resolved **once** in the action class — resolving per row
inside the Blade loop would be one store read per flag per render.

**Verification at close of Group A:**

| Check | Result |
|---|---|
| render check (manager + viewer) | ok — 3 switch triggers, all driver attributes, `role="switch"`, `aria-label` |
| `php vendor/bin/phpunit tests/Feature/ConfirmActionUsageTest.php` | OK (8 tests, 98 assertions) |
| full suite `php vendor/bin/phpunit` | OK (762 tests, 2570 assertions) |
| `vendor/bin/pint --test resources/views` | passed |
| queries inside the views | 0 |

**Open in Group A.** `P7-A4` asserts the six attributes but not that the new state survives
the round trip — no controller exists to receive it. Closes at `P7-E2`. `P7-A4`'s regex
only matches `<button>`, so the `<input>` switch escapes `ConfirmActionUsageTest`; fixed at
`P7-E3`.

**Gate A: PASSED.** Proceed to Group B.

---

## Group B — Flag Catalogue & Activation

> Goal: every module has a declared flag with a real activation, so later groups gate
> against data instead of hardcoded strings.
> Depends: Group A (view contract fixed). Blocks: C, D, E.
> Engine: Pennant (Decision 1). Permissions: `features.*` (Decision 3).

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P7-B1 | `config/pennant.php` — publish it, then add a `features` map: `slug => ['label' => …, 'group' => …, 'description' => …]`. **Keep the published `stores` block byte-identical** — it carries the `PENNANT_STORE` env wiring the deploy reads | A | small | ⬜ TODO |
| P7-B2 | Flag list, built from what exists rather than the brief's examples: `users`, `roles`, `permissions`, `settings`, `activity_logs`, `translations`, `sessions`, `pulse`. **`registration` dropped** (already gated by `registration_enabled`) and **`telescope` dropped** (package removed in `85384b4`) | B1 | small | ⬜ TODO |
| P7-B3 | `AppServiceProvider::boot()` — the declaration loop, and the one place that makes `disabled => true` a working kill-switch: `Feature::define($slug, fn () => ! ($meta['disabled'] ?? false))` over `config('pennant.features')`. Plus `Feature::resolveScopeUsing(fn () => 'global')` — **without this the flag set is per-user and the management page is a lie** | B1 | small | ⬜ TODO |
| P7-B4 | `App\Support\FeatureCatalog` — `all()`, `grouped()` (group label → flag rows), `labels()`. Static array read from `config('pennant.features')`. Same role `PermissionCatalog` plays for permissions: one source, so seeder / menu / view / tests cannot disagree | B1 | small | ⬜ TODO |
| P7-B5 | `database/seeders/FeatureFlagSeeder.php` — for every catalogue slug, `Feature::activate($slug)` when absent; `Feature::deactivate()` **only** when config says `disabled => true`. Idempotent. **Never blanket-activate**: a flag an operator turned off must stay off across a reseed. This seeder is the whole answer to the "declared ≠ active" trap | B3, B4 | medium | ⬜ TODO |
| P7-B6 | Register in `DatabaseSeeder`; document run order | B5 | tiny | ⬜ TODO |
| P7-B7 | `tests/Feature/FeatureFlagCatalogTest.php` — every catalogue slug is declared and active after seeding; `disabled => true` resolves false; scope is `global`; the seeder run twice changes nothing an operator switched off; an undeclared slug resolves false (fail-closed) | B3–B6 | medium | ⬜ TODO |

**Deliverables (planned):** `config/pennant.php` (features map), `App\Support\FeatureCatalog`,
the `AppServiceProvider::boot()` loop, `FeatureFlagSeeder`, `FeatureFlagCatalogTest`.

**Gate B:** `php artisan db:seed` twice is idempotent · `Feature::active($slug)` is `true`
for every catalogue slug on a fresh DB · a flag with `disabled => true` is `false`
**without** touching the DB · an undeclared slug is `false`.

### Decisions this group forces
| Question | Answer |
|---|---|
| Where does a flag's label live? | `config/pennant.php`, not the DB — a label is code, like a permission name. A row nothing reads is a trap (`PermissionCatalog.php:11-22`) |
| Does the DB need a `group` column? | **No** — grouping is presentation, read from config at render time. A column would be a second thing to keep in sync with config |
| Per-user or global? | **Global** (`resolveScopeUsing`). Per-user flags are a rollout tool, not a kill switch, and would make the management page misleading |
| Does the management page write to `features` directly? | No — it calls `Feature::activate()`/`deactivate()`, keeping the store driver swappable. Raw row writes would bind the UI to `PENNANT_STORE=database` and break the `array` store in tests |

---

## Group C — Enforcement Middleware & Blade Integration

> Goal: a disabled flag makes the endpoint **invisible** (404) and the menu item
> **absent**, for every user including superadmin.
> Depends: Group B (flags exist and resolve). Blocks: D, E.

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P7-C1 | `App\Http\Middleware\EnsureFeatureIsEnabled` — `handle($request, $next, string ...$features)`; any `Feature::active()` false → `abort(404)`. **No `features.manage` bypass** (see below). Fail-closed by construction: an unknown slug is `false`. Own class, not Pennant's `EnsureFeaturesAreActive`, per Decision 2 | B | tiny | ⬜ TODO |
| P7-C2 | Register the alias in `bootstrap/app.php`: `'feature' => EnsureFeatureIsEnabled::class`. Pennant ships a middleware this repo does **not** alias — `->middleware('feature:{slug}')` fails with "Route middleware [feature] not defined" until this line exists | C1 | tiny | ⬜ TODO |
| P7-C3 | `@feature` / `@endfeature` — **already registered** by `PennantServiceProvider:48-57`. Task is verification, not code: `php artisan view:cache` + one test that a disabled block does not render. **Do not re-register** — a second `Blade::if('feature')` silently overrides the package's | B7 | tiny | ⬜ TODO |
| P7-C4 | Gate interaction — **do not** extend `Gate::before()`. Flags are a kill switch, permissions are authorization; a disabled module must 404, not 403, and `Gate::before` returning `true` for superadmin would keep a killed module reachable. Enforcement for superadmin lives in the middleware, which has no bypass | C1 | tiny | ⬜ TODO |
| P7-C5 | `tests/Feature/FeatureFlagMiddlewareTest.php` — flag off → 404 on web **and** API for anonymous / `user` / `admin` / **superadmin**; flag on → passes through; unknown slug → 404; `features.manage` holder → still 404 | C2 | medium | ⬜ TODO |

**Deliverables (planned):** `EnsureFeatureIsEnabled`, the `bootstrap/app.php` alias,
`FeatureFlagMiddlewareTest`.

**Manager bypass rule — decided, not open.** There is **no** silent bypass. A flag off
404s the route and hides the menu for everyone, managers included; a manager re-enables
from `/features` first. A bypass is the tempting middle ground and it is wrong: a
kill-switch superadmin can walk through is not a kill-switch, and "the feature is off but
the CEO can still see it" is a state nobody asked for. If a manager must inspect a
disabled module, that is a `ponytail:` note revisited against a named requirement.

**Gate C:** off → 404 for superadmin on web and API · on → passes · unknown slug → 404 ·
`features.manage` holder still 404 · `@feature` block absent when disabled.

---

## Group D — Routes, Menu, Management Controller, Audit

> Goal: the flag becomes the access boundary on every path, and every change to it is audited.
> Depends: B (flags), C (middleware). Blocks: E.

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P7-D1 | Seed `features.view` + `features.manage` into `PermissionCatalog` as a `FEATURES` const; delete the `:68-72` comment reserving them. `admin` gets both automatically (takes `PermissionCatalog::all()`); `superadmin` needs no row (`Gate::before`) | B | tiny | ⬜ TODO |
| P7-D2 | `App\Actions\V1\Feature\FeatureIndexAction` — reads `FeatureCatalog::grouped()`, resolves each `enabled` **once** via `Feature::active($slug)`, returns the four counters | B4 | small | ⬜ TODO |
| P7-D3 | `App\Actions\V1\Feature\FeatureToggleAction` — `activate()`/`deactivate()`, then `Feature::flushCache()`, then audit `feature.toggled` with `['feature' => $slug, 'from' => $old, 'to' => $new]`. **`$old` must be read before the write** — read after, it is always the new value | D2 | small | ⬜ TODO |
| P7-D4 | `App\Http\Controllers\Web\V1\FeatureController` — thin. `index` → `can('features.view')` → action → `view('pages.features.index')`. `toggle` → `can('features.manage')` → action → redirect with `status` flash. **No Form Request class**: one validated boolean on a query string, and `P6-C13` deleted a one-rule request class for exactly this | D2, D3 | small | ⬜ TODO |
| P7-D5 | `routes/web.php` — `GET /features` (`can('features.view'`), `POST /features/{feature}/toggle` (`can('features.manage'`). Both inside the authenticated group, so `auth` runs before `can` and the answer is 403 not 401 | D4 | small | ⬜ TODO |
| P7-D6 | Apply `->middleware('feature:{slug}')` to the routes that exist: `users.*`, `roles.*`, `permissions.*`, `settings.*`, `activity-logs.*`, `translations.*`, `sessions`, `pulse`. **Group by flag**, not one call per route — `Route::middleware('feature:users')->group()` around the block. A flag on 3 of 9 routes is a partial gate: the routes missed still work | C2 | medium | ⬜ TODO |
| P7-D7 | `routes/api.php` — **the same matrix** (`:46`, `:78-112`). An API-only gap is the same hole with a different URL; this is the `RbacPentestTest` lesson from Phase 6 applied to a new dimension | D6 | medium | ⬜ TODO |
| P7-D8 | `AppMenuComposer` — add a `feature` key per item, filter on `Feature::active()` alongside the existing `permission` filter. A menu that disagrees with the routes shows links that 404, or hides links that work | D6 | small | ⬜ TODO |
| P7-D9 | Sidebar partial — **no change.** The composer already filters; a second `@can` in Blade is a duplicate lookup for the same answer. Same rule as `P6-D4` | D8 | — | ⬜ TODO |
| P7-D10 | `tests/Feature/FeatureFlagMenuTest.php` — a flag off removes its sidebar item for `admin` **and** superadmin (who passes every `can()`); flag on keeps it; the item is gone when its route is gated, not greyed | D8 | small | ⬜ TODO |

**Deliverables (planned):** `FeatureCatalog` permission const, `FeatureIndexAction`,
`FeatureToggleAction`, `FeatureController`, 2 web routes + matrix on both route files,
`AppMenuComposer` feature filter, `FeatureFlagMenuTest`.

**Gate D:** a zero-permission user still gets 403 exactly where they did before (no
regression on the Phase 6 matrix) · a `features.manage` holder toggles `users` off and
`/users` immediately 404s for them too · the `activity_logs` sidebar item disappears with
its flag · `php artisan route:list` shows `feature:{slug}` in the Middleware column for
every gated route.

---

## Group E — Tests, Verification, Docs

> Goal: prove the whole matrix, including the parts that are easy to get wrong.
> Depends: A–D. Final group.

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P7-E1 | `tests/Feature/FeatureFlagTest.php` — the brief's four cases (toggle via UI and API; disabled → 404 web **and** API for a **superadmin**; menu item gone; re-enable restores both) **plus the two it does not name and that are the ones that actually break**: an undeclared slug 404s (fail-closed), and a slug declared in config but never activated 404s (the Pennant trap) | C, D | medium | ⬜ TODO |
| P7-E2 | Round trip — POST the toggle URL the Group A switch rendered; assert the new state reached the store, the cache flushed, the audit row exists with correct `from`/`to`, and a follow-up GET reflects it. Closes the open item in Group A | D3 | small | ⬜ TODO |
| P7-E3 | `ConfirmActionUsageTest` — add `features.index`, and **extend the trigger regex to `<input\b`**. Without it the new switch is never checked at all, which is how `confirm-action`'s `tag` prop escapes verification | A3 | small | ⬜ TODO |
| P7-E4 | Regression — `php artisan test` green. Every pre-existing test touching a newly flag-gated route needs the flag **active** in its `setUp()`, not a deleted assertion. This is the expected churn point and is not a reason to skip the gate | D6 | medium | ⬜ TODO |
| P7-E5 | Performance — the flag table must not grow with row count: assert the index resolves N flags in a flat number of store reads, pinned as a **delta at two flag counts**. A ceiling like "under 20" passes for an N+1 that happens to fit under a number someone picked | D2 | small | ⬜ TODO |
| P7-E6 | Docs — `docs/base/features/feature-flags.md` (the phase happened; resolve the 400-vs-404 question; document the activation requirement), `docs/planning/task-tracker.md` (`FLAG-001` note + `P7-A1`..`P7-E7`), `docs/planning/progress.md` (phase 7 row), `docs/planning/feature-tracker.md` (row 24), and fix the broken link at `docs/base/ui/ui-architecture.md:158` | A–E | medium | ⬜ TODO |
| P7-E7 | Full verification — `php artisan test`, `npm run build`, `vendor/bin/pint --test`, `php artisan view:cache` | A–E | small | ⬜ TODO |

**Deliverables (planned):** `FeatureFlagTest`, round-trip assertions, updated
`ConfirmActionUsageTest`, perf assertions, 4 doc updates, green regression.

**Gate E:** every 404 above holds · `FeatureFlagTest` green · full suite green · docs
describe what the code does, including the activation requirement.

---

## Performance Notes

| Risk | Mitigation |
|---|---|
| Store read per flag per request | `Feature::active()` uses a per-request cache Pennant already maintains; resolve once in the action class, never in a Blade loop (`P7-D2`, `P7-E5`) |
| `features.manage` making everyone a manager | `admin` takes the catalogue wholesale; `superadmin` via `Gate::before`. A role deliberately granted `features.manage` becomes a manager — that is what the permission means, and granting it is a conscious act |
| Middleware ordering | `feature:` must run **after** `auth`. Inside the authenticated group that is automatic. On a public route an unauthenticated 404 reveals the flag exists — acceptable, the flag's existence is not a secret, but noted |
| Sidebar cost | the composer runs once per request; `Feature::active()` is in-memory after the first read. `P7-E5` measures rather than assumes |

## Security Notes

| Vector | Control |
|---|---|
| Flag bypass via superadmin | none exists — `EnsureFeatureIsEnabled` has no `can()` escape hatch (`P7-C1`, `P7-C4`) |
| Flag bypass via API | the same middleware on `routes/api.php` (`P7-D7`) |
| UI hiding treated as enforcement | `ui-authorization.md` is explicit; the middleware is the boundary and `P7-E1` proves it |
| Undeclared flag | fail-closed — `Feature::active()` is `false` for a slug with no row |
| Silent state change | every toggle audits `feature.toggled` with `from`/`to` (`P7-D3`), old value read before the write |
| Second writer for `registration` | resolved before Group B, not discovered in production |
| Re-opening the mass-assign surface | `users.*` is already `can:`-gated from Phase 6; the flag adds a kill switch on top, never a substitute |

## Deliberate Simplifications

```
ponytail: flags are declared in config/pennant.php, not stored with labels and
descriptions in the DB. A flag's identity and its human copy are code, like a
permission name — a DB row nothing reads is a trap. Revisit only if a tenant or
plugin needs runtime-defined flags.
```
```
ponytail: no features.manage bypass. A flag off 404s for everyone, managers
included; a manager re-enables from /features first. Revisit if a named
requirement needs a manager to inspect a killed module.
```
```
ponytail: the management page has no create/edit/delete. Flags are declared in
config like permissions in PermissionCatalog — the page reads and toggles, it
does not author. Revisit with the catalogue above.
```
```
ponytail: one middleware class rather than Pennant's EnsureFeaturesAreActive.
Two classes, one line of duplicated logic, and the difference is 400 vs 404 — a
response this repo already uses for the same case. Revisit if Pennant ever lets
one middleware answer per feature.
```

---

## Execution Order

```
Group A (UI — views only)          — A1 → A2 → A3 → A4
                                            ↓ Gate A PASSED (code written, uncommitted)
Group B (Catalogue + activation)   — B1 → B2 → B3 → B4 → B5 → B6 → B7
                                            ↓ Gate B: every flag active after seed; disabled=>true off without the DB
Group C (Middleware + Blade)       — C1 → C2 → C3 → C4 → C5
                                            ↓ Gate C: off => 404 for everyone, superadmin included
Group D (Routes/menu/controller)   — D1 → D2 → D3 → D4 → D5 → D6 → D7 → D8 → D9 → D10
                                            ↓ Gate D: matrix holds on web + API + sidebar
Group E (Tests + docs)             — E1 → E2 → E3 → E4 → E5 → E6 → E7
                                            ↓ Gate E: full regression green
                                     Phase 7 COMPLETE
```

Each group = one commit boundary. Sequential — A cannot skip to C.

## Task Tracker Reconciliation

- `FLAG-001` (Pennant foundation) stays **DONE** — Phase 1 shipped the package; this phase is what finally uses it.
- Add to `docs/planning/task-tracker.md`: `P7-A1`..`P7-A4`, `P7-B1`..`P7-B7`, `P7-C1`..`P7-C5`, `P7-D1`..`P7-D10`, `P7-E1`..`P7-E7` — all start `PLANNED`, move to `DONE` as completed.
- `docs/planning/feature-tracker.md` row 24 (`Feature flags (DB-backed)`) reads "done" today, which is wrong — the table exists and nothing uses it. Should read "Pennant installed, no flags declared" until Group B lands.
