# Phase 7 — Feature Availability & Feature Flags

> Date: 2026-10-01 | Branch: feature/phase-7-feature-availability | Status: IN PROGRESS
> Purpose: execution breakdown and record of what actually shipped.
> Scope: global module availability (Modular Monolith), kill-switch enforcement at
> route + menu, management UI, audit trail.
> Dependency chain: A → B → C → D → E. A cannot skip to C.
> Decisions locked 2026-10-01: **engine = Laravel Pennant** (no custom table),
> **disabled flag → 404**, **permissions = `features.view` / `features.manage`**,
> **no staged changes** (see `design-system.md` § Staged Changes).

---

## Overview & Architecture

Domain-driven global module availability system. A flag governs whether a module
exists for this installation, not whether a given user may use it.

**Enforcement.** A disabled feature returns **HTTP 404 Not Found** on web and API
routes. Not 403, not 400 — the endpoint is meant to be invisible, so a client that
stumbles onto it gets "no such resource" and stops asking, rather than an
escalation invitation. Three sources in-repo already say 404:
`docs/base/features/feature-flags.md:70`, `routes/web.php:43-44`, and
`abort_unless(registration_enabled, 404)` in `AuthController.php:320`/`:342`.

**The matrix.**

| | Flag active | Flag inactive |
|---|---|---|
| **User has permission** | access allowed | **404** — the module does not exist |
| **User lacks permission** | 403 (permission is the answer) | **404** — same for everyone, including superadmin |

The two questions are independent and answered in this order: the flag decides
whether the endpoint exists, the permission decides whether *this* user gets in.
There is no `features.manage` bypass — a kill switch a superadmin can walk
through is not a kill switch.

---

## Existing Foundation (audit 2026-10-01)

### Already in place
- `laravel/pennant: ^1.26` installed + auto-discovered
- `features` table migrated (`2026_09_15_175615_create_features_table.php`) — Pennant's schema: `name`, `scope`, `value`
- `@feature` / `@featureany` Blade directives registered by the package (`PennantServiceProvider.php:48-57`)
- `App\Models\Concerns\Auditable` — `->audit($event, $causer, $properties)`
- `<x-ui.confirm-action>` + `ACTION_CONFIG` (12 keys), now accepting `tag="input"`
- `AppMenuComposer` — filters on `permission`, drops items whose route is missing
- Read-only-when-cannot-manage pattern: `pages/settings/index.blade.php:33` `@can` … `:609` `@endcan`
- `ConfirmActionUsageTest` — hand-built-trigger ban, `data-action-type` must exist in `ACTION_CONFIG`

### Decision 1 — engine: Pennant (LOCKED 2026-10-01)

The original brief specified a custom `features` table (`key`/`name`/`description`/
`group`/`is_enabled`), `App\Models\Feature`, a cache-busting observer, a
`FeatureManager::isEnabled()` service, and a `FeatureSeeder`. **Pennant is used
instead; no custom table.**

The `features` table name is already taken by Pennant with an incompatible
schema, so a custom variant would need a *second* table beside it — two writers
for "is this module on", whichever ran last winning with neither knowing about the
other. Dropped as a consequence, not deferred:

| Brief item | Verdict |
|---|---|
| Migration `create_features_table` | **dropped** — table exists, schema is Pennant's |
| `App\Models\Feature` + Observer / `Cache::rememberForever` busting | **dropped** — Pennant owns persistence and request-level resolution |
| `FeatureManager::isEnabled()` / `Feature::isEnabled($key)` helper | **dropped** — `FeatureCatalog::isActive($slug)` is the one reader, and it is what makes `disabled => true` a kill switch |
| `FeatureSeeder` | **shipped** as `FeatureFlagSeeder`, keyed off config instead of a table |
| `EnsureFeatureIsEnabled` middleware | **kept, rewritten** — own class, 404 not 400 (Decision 2) |
| Register `@feature` | **dropped** — already registered by the package; re-registering silently overrides it |

### Decision 2 — 404, not 400 (LOCKED 2026-10-01)

Pennant ships the middleware this phase needs, with one wrong default:

```php
// vendor/laravel/pennant/src/Middleware/EnsureFeaturesAreActive.php:26-28
return static::$respondUsing
    ? call_user_func(static::$respondUsing, $request, $features)
    : abort(400, $error);
```

| Status | Meaning in HTTP | What a client does | Truthful for a disabled module? |
|---|---|---|---|
| **400** | the request itself is malformed | retry, fix the payload, log a bug | **no** — it was well-formed; the server declined to serve it |
| **403** | authenticated, understood, not allowed | retry with different credentials | **no** — nobody is forbidden; the resource does not exist |
| **404** | no such resource | stop asking, fall back, do not retry | **yes** — the module is invisible, and that is the intent |

400 says "you built the request wrong", sending an integrator hunting a bug in
their own code that is not there. 403 says "you are not allowed", inviting an
escalation request — the wrong answer for a flag an operator turned off.

The cost is 5 duplicated lines. Aliasing Pennant's and overriding it globally
would change the response on every other route using it. `ponytail:` the
duplication is deliberate — a different *response*, not duplicate logic. Revisit
if Pennant ever supports per-middleware responses.

### Decision 3 — permission names (LOCKED 2026-10-01)

`features.view` + `features.manage`. Both the brief and
`docs/base/features/feature-flags.md:74-76` agree, the plural form matches every
existing group (`users.*`, `roles.*`, `permissions.*`, `settings.*`), and
`PermissionCatalog` self-groups them under a `features` heading with no extra
entry.

### Decision 4 — no staged changes (LOCKED 2026-10-01)

A toggle must not change visible state before the change is stored. The failure
modes are all invisible: an optimistic toggle the server refuses leaves a switch
claiming a state the database does not hold, and a reload renders the stored
value, contradicting what the user just watched. Full rationale in
`docs/base/ui/design-system.md` § Staged Changes. Feature flags use the
confirmation modal plus § Bulk Actions instead.

---

## The two facts that shape Group B

### Pennant gotcha — declaring a flag does not activate it

With the `database` driver, `Feature::active($slug)` resolves against a row in
`features`. **No row → false, fail-closed.** A flag added to `config/pennant.php`
and wired to a route produces a route that 404s for everyone — superadmin
included — until someone activates it:

```
php artisan tinker --execute="Laravel\Pennant\Feature::activate('users');"
```

Every flag needs **both** a config entry **and** an activation. `FeatureFlagSeeder`
makes that non-forgettable; `FeatureFlagCatalogTest` proves it.

### Collision — `registration` is already gated by a SystemSetting

`registration_enabled` is a `system_settings` row (`SystemSettingSeeder.php:71`)
read in four places and toggled from `pages/settings/index.blade.php:511-514`. The
brief seeds a `registration` flag; that would be two writers for one question.
**Dropped** — the feature is already enforced at every entry point. Same reasoning
retires the brief's `telescope` key: `laravel/telescope` was removed in `85384b4`;
observability is now `laravel/pulse`.

---

## Shipped — Groups A, B, D1–D5 ✅

> Commits `b72a5f6` (B) → `e538c49` (D) → `9545bce` (A) → `1127d40` (docs).
> Verified this pass: **30 tests / 174 assertions green** across the three feature
> suites.

### Group A — UI ✅ DONE

| ID | Task | Status |
|----|------|--------|
| P7-A1 | `resources/views/pages/features/index.blade.php` — content-header + breadcrumb, 4 metric cards (Features / Enabled / Disabled / Modules), one card per group with `card-header` = group name, `table-responsive`, session flash alerts | ✅ DONE |
| P7-A2 | `resources/views/components/ui/feature-toggle.blade.php` — manager → `form-check form-switch` + `<x-ui.confirm-action tag="input">`; non-manager → `<x-ui.badge>`. `role="switch"`, `aria-label`, intended new state in the toggle URL's `enabled` param | ✅ DONE |
| P7-A3 | `<x-ui.confirm-action>` gains a `tag` prop so a trigger can be `<input type="checkbox">`. The 8 existing button triggers render unchanged | ✅ DONE |
| P7-A4 | `tests/Feature/FeatureFlagUiRenderTest.php` — render gate for both branches | ✅ DONE |

**View-data contract** (handed to Group B, never queried by the view):

| View | Variables |
|---|---|
| `pages.features.index` | `$featureGroups`, `$totalFeatures`, `$enabledCount`, `$disabledCount` |

`slug`/`label`/`description`/`group` from `config/pennant.php`; `enabled` from
`FeatureCatalog::isActive($slug)`. Both resolved **once** in the action class —
resolving per row inside the Blade loop would be one store read per flag per
render.

### Group B — Catalogue & Activation ✅ DONE

| ID | Task | Status |
|----|------|--------|
| P7-B1 | `config/pennant.php` — 8 flags across 4 groups (Users / Settings / Security / Audit / Monitoring): `users`, `roles`, `permissions`, `settings`, `translations`, `sessions`, `activity_logs`, `pulse`. Published `stores` block kept byte-identical — it carries the `PENNANT_STORE` env wiring deploy reads | ✅ DONE |
| P7-B2 | `AppServiceProvider::boot()` — declaration loop over `config('pennant.features')`, plus `Feature::resolveScopeUsing(fn () => 'global')` | ✅ DONE |
| P7-B3 | `App\Support\FeatureCatalog` — `slugs()`, `all()`, `grouped()`, `find()`, `has()`, `isDisabledInConfig()`, `isActive()`. Same role `PermissionCatalog` plays for permissions: one source, so seeder / menu / view / tests cannot disagree | ✅ DONE |
| P7-B4 | `database/seeders/FeatureFlagSeeder.php` — activates every catalogue slug when absent; deactivates **only** when config says `disabled => true`. Idempotent; never blanket-activates, so a flag an operator turned off stays off across a reseed | ✅ DONE |
| P7-B5 | Registered in `DatabaseSeeder` | ✅ DONE |
| P7-B6 | `tests/Feature/FeatureFlagCatalogTest.php` | ✅ DONE |

**Load-bearing detail.** `FeatureCatalog::isActive()` is the single reader, and it
returns `false` when config says `disabled => true` *before* consulting the store.
That is what makes a config entry a working kill switch rather than a default —
otherwise an operator flipping the flag and re-seeding would have it re-enabled.

**Scope is forced global.** Without `resolveScopeUsing`, Pennant scopes to the
authenticated user and the management page becomes a lie: it shows one user's
flags as though they were the installation's.

### Group D (partial) — Controller, Routes, Audit ✅ DONE

| ID | Task | Status |
|----|------|--------|
| P7-D1 | Seed `features.view` + `features.manage` into `PermissionCatalog`; the `:68-72` comment reserving them is gone | ✅ DONE |
| P7-D2 | `App\Actions\V1\Feature\FeatureIndexAction` — reads `FeatureCatalog::grouped()`, resolves each `enabled` once, returns the four counters | ✅ DONE |
| P7-D3 | `App\Actions\V1\Feature\FeatureToggleAction` — activate/deactivate, flush cache, audit `feature.toggled` with `['feature' => …, 'from' => …, 'to' => …]`. **`from` is read before the write** — read after, it is always the new value | ✅ DONE |
| P7-D4 | `App\Http\Controllers\Web\V1\FeatureController` — thin: `index` (`can('features.view')`) and `toggle` (`can('features.manage')`). No Form Request class; one validated boolean on a query string, and P6-C13 deleted a one-rule request class for exactly this | ✅ DONE |
| P7-D5 | `routes/web.php` — `GET /features` (`can('features.view')`), `POST /features/{feature}/toggle` (`can('features.manage')`), both inside the authenticated group so `auth` runs before `can` | ✅ DONE |
| P7-D6 | `AppMenuComposer` — Feature Flags item added, gated on `features.view` | ✅ DONE |

---

## Remaining — Group C and the rest of D, E

### Group C — Enforcement Middleware ⬜ NOT STARTED

> Goal: a disabled flag makes the endpoint **invisible** (404) and the menu item
> **absent**, for every user including superadmin. Nothing in this group exists yet.

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-C1 | `App\Http\Middleware\EnsureFeatureIsEnabled` — `handle($request, $next, string ...$features)`; any `FeatureCatalog::isActive()` false → `abort(404)`. **No `features.manage` bypass.** Fail-closed by construction: an unknown slug is `false`. Own class, not Pennant's, per Decision 2 | B | ⬜ TODO |
| P7-C2 | Register the alias in `bootstrap/app.php`: `'feature' => EnsureFeatureIsEnabled::class`. Until this line exists, `->middleware('feature:{slug}')` fails with "Route middleware [feature] not defined" | C1 | ⬜ TODO |
| P7-C3 | `@feature` / `@endfeature` — **already registered** by the package. This task is verification, not code. **Do not re-register** — a second `Blade::if('feature')` silently overrides the package's | B6 | ⬜ TODO |
| P7-C4 | Do **not** extend `Gate::before()`. Flags are a kill switch, permissions are authorization; a disabled module must 404, and `Gate::before` returning `true` for superadmin would keep a killed module reachable | C1 | ⬜ TODO |
| P7-C5 | `tests/Feature/FeatureFlagMiddlewareTest.php` — flag off → 404 on web **and** API for anonymous / `user` / `admin` / **superadmin**; flag on → passes; unknown slug → 404; `features.manage` holder → still 404 | C2 | ⬜ TODO |

**Gate C:** off → 404 for superadmin on web and API · on → passes · unknown slug
→ 404 · `features.manage` holder still 404 · `@feature` block absent when disabled.

**Why this group is not optional.** Right now `FeatureCatalog::isActive()` has one
caller: the management page's own read. A flag turned off at `/features` changes
what the page displays and nothing else — `/users` still answers 200, the sidebar
still links it. The flag system is a UI until C1 and D6 land.

### Group D (remaining) — Route Gating ⬜ NOT STARTED

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-D7 | `routes/web.php` — apply `->middleware('feature:{slug}')` to the routes that exist: `users.*`, `roles.*`, `permissions.*`, `settings.*`, `translations.*`, `sessions`, `activity-logs.*`, `pulse`. **Group by flag**, not one call per route — `Route::middleware('feature:users')->group()` around the block. A flag on 3 of 9 routes is a partial gate: the routes missed still work | C2 | ⬜ TODO |
| P7-D8 | `routes/api.php` — **the same matrix**. An API-only gap is the same hole with a different URL; this is the `RbacPentestTest` lesson from Phase 6 applied to a new dimension | D7 | ⬜ TODO |
| P7-D9 | `AppMenuComposer` — add a `feature` key per item and filter on `FeatureCatalog::isActive()` alongside the existing `permission` filter. A menu that disagrees with the routes shows links that 404, or hides links that work | D7 | ⬜ TODO |
| P7-D10 | `tests/Feature/FeatureFlagMenuTest.php` — a flag off removes its sidebar item for `admin` **and** superadmin (who passes every `can()`); flag on keeps it | D9 | ⬜ TODO |

**Gate D:** a zero-permission user still gets 403 exactly where they did before
(no regression on the Phase 6 matrix) · a `features.manage` holder toggles `users`
off and `/users` immediately 404s for them too · the `activity_logs` sidebar item
disappears with its flag · `php artisan route:list` shows `feature:{slug}` in the
Middleware column for every gated route.

### Group F — Bulk Feature Actions ⬜ NOT STARTED

> Added 2026-10-01. Follows `design-system.md` § Bulk Actions, which documents the
> shipped `#bulkBar` contract from the users and roles pages.

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-F1 | `enable_feature` / `disable_feature` keys in `ACTION_CONFIG` (`resources/js/helpers/action-config.js`) — variants per § Action Color Convention: `enable => success`, `disable => warning`. Needed because `data-bulk-keys` resolves copy through `ACTION_CONFIG` and neither key exists yet (12 keys today, none flag-related) | — | ⬜ TODO |
| P7-F2 | Add `enable_feature` / `disable_feature` to the UI-consistency test that asserts every `data-action-type` exists in `ACTION_CONFIG` | F1 | ⬜ TODO |
| P7-F3 | `App\Actions\V1\Feature\FeatureBulkToggleAction` — one POST, all selected slugs, one audit entry `feature.bulk_toggled` with the full slug list and per-slug `from`/`to`. **Read all `from` values before writing any** | D3 | ⬜ TODO |
| P7-F4 | `POST /features/bulk-action` (`features.bulk-action`, `can('features.manage')`) + `App\Http\Requests\V1\Feature\BulkFeatureRequest` using `AuthorizesBulkAction`, which already maps an action to its own permission from Phase 6 | F3 | ⬜ TODO |
| P7-F5 | `#bulkBar` on `pages/features/index.blade.php`: `data-bulk-states='{"active":["disable_feature"],"inactive":["enable_feature"]}'`, `data-bulk-mixed="disable_feature"`, `data-bulk-keys` from F1, `data-bulk-field="features[]"` | F1, F4 | ⬜ TODO |
| P7-F6 | `tests/Feature/FeatureFlagBulkTest.php` — bulk enable and disable; mixed selection offers only actions safe for all rows; a slug outside the catalogue is refused; `features.manage` holder only | F5 | ⬜ TODO |

**Gate F:** the bar appears on first tick and clears after submit · a mixed
selection cannot apply `disable_feature` to an already-active flag's twin ·
`data-bulk-keys` names keys that exist in `ACTION_CONFIG` · one audit row per
request with correct `from`/`to` · 403 for a user without `features.manage`.

**`data-bulk-mixed` is the rule to get right here.** With 3 active and 2 inactive
flags selected, only `disable_feature` is safe for all five. Offering
`enable_feature` because the first row was inactive would flip flags the user did
not mean to touch — and for a kill switch, an accidental enable is the more
expensive direction.

### Group E — Tests, Verification, Docs ⬜ PARTIAL

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-E1 | `tests/Feature/FeatureFlagTest.php` — toggle via UI; disabled → 404 web **and** API for a **superadmin**; menu item gone; re-enable restores. Plus the two cases that are not in the brief and are the ones that break: an undeclared slug 404s (fail-closed), and a declared-but-never-activated flag 404s (the Pennant trap) | C, D | ⬜ TODO |
| P7-E2 | Round trip — POST the toggle URL the Group A switch rendered; assert the new state reached the store, the cache flushed, the audit row exists with correct `from`/`to`, and a follow-up GET reflects it | D3 | ⬜ TODO |
| P7-E3 | `ConfirmActionUsageTest` — add `features.index`, and **extend the trigger regex to `<input\b`**. It currently matches `<button\b` only (`:153`), so the new switch is never checked at all — which is how `confirm-action`'s `tag` prop escapes verification entirely | A3 | ⬜ TODO |
| P7-E4 | Regression — `php artisan test` green. Every pre-existing test touching a newly flag-gated route needs the flag **active** in its `setUp()`, not a deleted assertion. This is the expected churn point and is not a reason to skip the gate | D7 | ⬜ TODO |
| P7-E5 | Performance — assert the index resolves N flags in a flat number of store reads, pinned as a **delta at two flag counts**. A ceiling like "under 20" passes for an N+1 that happens to fit under a number someone picked | D2 | ⬜ TODO |
| P7-E6 | Docs — `docs/base/features/feature-flags.md` (the phase happened; resolve the 400-vs-404 question; document the activation requirement), `task-tracker.md`, `progress.md`, `feature-tracker.md` row 24, and fix the broken link at `docs/base/ui/ui-architecture.md:158` → file is at `docs/base/features/feature-flags.md` | A–F | ⬜ TODO |
| P7-E7 | Full verification — `php artisan test`, `npm run build`, `vendor/bin/pint --test`, `php artisan view:cache` | A–F | ⬜ TODO |

---

## Performance Notes

| Risk | Mitigation |
|---|---|
| Store read per flag per request | `FeatureCatalog::isActive()` goes through one reader and the index resolves once in `FeatureIndexAction`, never in a Blade loop (`P7-D2`, `P7-E5`) |
| Sidebar cost | the composer runs once per request; after D9 it calls `isActive()` per item, in-memory after the first read |
| Middleware ordering | `feature:` must run **after** `auth`. Inside the authenticated group that is automatic. On a public route an unauthenticated 404 reveals the flag exists — acceptable, the flag's existence is not a secret, but noted |
| Bulk toggles | one request, one audit row, all `from` values read before any write (`P7-F3`) |

## Security Notes

| Vector | Control |
|---|---|
| Flag bypass via superadmin | none exists — `EnsureFeatureIsEnabled` will have no `can()` escape hatch (`P7-C1`, `P7-C4`) |
| Flag bypass via API | the same middleware on `routes/api.php` (`P7-D8`) |
| UI hiding treated as enforcement | `ui-authorization.md` is explicit; the middleware is the boundary and `P7-E1` proves it |
| Undeclared flag | fail-closed — `isActive()` is `false` for a slug with no row |
| Silent state change | every toggle audits `feature.toggled` with `from`/`to`, old value read before the write (`P7-D3`) |
| Bulk action escalation | `AuthorizesBulkAction` maps the action to `features.manage` and an unmapped action fails closed (`P7-F4`) |
| Accidental re-enable by seeder | `FeatureFlagSeeder` never blanket-activates (`P7-B4`) |
| Second writer for `registration` | resolved before Group B, not discovered in production |

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
```
ponytail: no staged/optimistic toggles. The switch is a confirmation-modal
trigger that submits, so the rendered state is always the stored state. Revisit
only with the unsaved/dirty guidelines design-system.md § Staged Changes demands.
```

---

## Execution Order

```
Group A (UI — views only)          — DONE  (9545bce)
Group B (Catalogue + activation)   — DONE  (b72a5f6)
Group C (Middleware + Blade)       — C1 → C2 → C3 → C4 → C5      ⬜ NEXT
                                     ↓ Gate C: off => 404 for everyone, superadmin included
Group D (Route/menu gating)        — D7 → D8 → D9 → D10          ⬜
                                     ↓ Gate D: matrix holds on web + API + sidebar
Group F (Bulk feature actions)     — F1 → F2 → F3 → F4 → F5 → F6 ⬜
                                     ↓ Gate F: bulk safe for mixed selections, audited
Group E (Tests + docs)             — E1 → E2 → E3 → E4 → E5 → E6 → E7 ⬜
                                     ↓ Gate E: full regression green
                                     Phase 7 COMPLETE
```

Sequential — C cannot be skipped, and it is what turns a flag into a kill switch.

## Task Tracker Reconciliation

- `FLAG-001` (Pennant foundation) stays **DONE** — Phase 1 shipped the package; this phase is what finally uses it.
- `FEAT-001` / `FEAT-002` stay **PLANNED** until Group C/E closes; they are the phase-level rollups for exactly this work.
- `docs/planning/feature-tracker.md` row 24 (`Feature flags (DB-backed)`) reads "done" today, which is wrong — the table existed and nothing used it. Should read "catalogue + management UI shipped; route enforcement pending" until C and D close.