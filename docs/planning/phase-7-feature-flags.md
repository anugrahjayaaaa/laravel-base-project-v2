# Phase 7 — Feature Availability & Feature Flags

> Date: 2026-10-01 | Branch: feature/phase-7-feature-availability | Status: IN PROGRESS
> Purpose: execution breakdown and record of what actually shipped.
> Scope: global module availability (Modular Monolith), kill-switch enforcement at
> route + menu, management UI, audit trail.
> Dependency chain: A → B → C → D → E. A cannot skip to C.
> Decisions locked 2026-10-01: **engine = Laravel Pennant** (no custom table),
> **disabled flag → 403**, **permissions = `features.view` / `features.manage`**,
> **no staged changes** (see `design-system.md` § Staged Changes).

---

## Overview & Architecture

Domain-driven global module availability system. A flag governs whether a module
exists for this installation, not whether a given user may use it.

**Enforcement.** A disabled feature returns **HTTP 403 Forbidden** on web and API
routes. Not 400 — that blames the caller's request, which was well-formed. Not 404
— that claims the route does not exist, which is false and produces a support
trail of "this page 404s intermittently". 403 states the one true thing: the
server understood and will not serve it, and it is what `can:` and
`CheckAccountState` already return, so one status means "you may not have this"
across the whole admin. Settled 2026-10-01, changing an earlier 404.

**The cost, stated plainly.** 403 does not distinguish *module killed* from *no
permission* from *account disabled*. A client needing that distinction must ask
`/features` (never gated) rather than infer it from the status. Deliberate: an
operator-facing admin gains little from obscurity, and a flag's existence was
never secret.

**The matrix.**

| | Flag active | Flag inactive |
|---|---|---|
| **User has permission** | access allowed | **403** — refused, for everyone including superadmin |
| **User lacks permission** | 403 (permission is the answer) | **403** — same status, reached earlier |

The two questions are independent and answered in this order: the flag decides
whether the endpoint is served, the permission decides whether *this* user gets in.
There is no `features.manage` bypass — a kill switch a superadmin can walk
through is not a kill switch.

> Inconsistency to note: `AuthController` still 404s `registration`
> (`abort_unless(registration_enabled, 404)` at `:320`/`:342`), a *setting*-gated
> feature rather than a flag-gated one. Left as is — changing it is out of this
> phase's scope, but the two gates no longer answer alike.

---

## Existing Foundation (audit 2026-10-01)

### Already in place
- `laravel/pennant: ^1.26` installed + auto-discovered
- `features` table migrated (`2026_09_15_175615_create_features_table.php`) — Pennant's schema: `name`, `scope`, `value`
- `@feature` / `@featureany` Blade directives registered by the package (`PennantServiceProvider.php:46` and `:54`) — **available but unused.** No view uses either. Nothing is wrong with that: the middleware is the enforcement boundary and the sidebar is the only visibility surface a switched-off module needs, so a conditional block would be a second answer to a question the composer already gives. Recorded so a future reader does not assume the directive is load-bearing.
- `App\Models\Concerns\Auditable` — `->audit($event, $causer, $properties)`
- `<x-ui.confirm-action>` + `ACTION_CONFIG` (12 keys), now accepting `tag="input"`
- `AppMenuComposer` — filters on `permission` **and** `feature`, drops items whose route is missing (P7-D9)
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
| `EnsureFeatureIsEnabled` middleware | **kept, rewritten** — own class, 403 not 400 (Decision 2) |
| Register `@feature` | **dropped** — already registered by the package; re-registering silently overrides it |

### Decision 2 — 403, not 400 (LOCKED 2026-10-01; 404 → 403 revised 2026-10-01)

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
| **404** | no such resource | stop asking, fall back, do not retry | **no** — the route does exist; the server is refusing it |
| **403** | authenticated, understood, not allowed | fall back, show a refusal | **yes** — and it is what the rest of the admin already returns |

400 says "you built the request wrong", sending an integrator hunting a bug in
their own code that is not there. 404 says "this never existed", producing a
support trail of "the page 404s intermittently" for a route that is right there.
403 says the one true thing: understood, and not serving.

**Revised 2026-10-01 from 404.** The earlier lock argued for invisibility. Two
things changed the answer. First, 403 is what `can:` and `CheckAccountState`
already return, so a single status carries one meaning across the admin and an
integrator needs no lookup table of which refusal is which. Second, the obscurity
404 bought was never worth much here — this is an operator-facing console and a
flag's existence was never a secret. The price paid is that *module killed*, *no
permission* and *account disabled* now share a status; a client that must tell
them apart asks `/features`, which stays ungated.

The cost is 5 duplicated lines. Aliasing Pennant's and overriding it globally
would change the response on every other route using it. `ponytail:` the
duplication is deliberate — a different *response*, not duplicate logic. Revisit
if Pennant ever supports per-middleware responses.

The second reason to own the class is independent of status and was found later:
**Pennant cannot read `disabled => true`** (measured, `P7-C1`).

```php
config(['pennant.features.users.disabled' => true]);
FeatureCatalog::isActive('users')    => false   ← the answer we want
Feature::active('users')            => true    ← Pennant's view
Feature::someAreInactive(['users'])  => false   ← what its middleware sees
```

So aliasing Pennant's class would leave the config kill switch inert on every
gated route — a second reason, and the stronger one.

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
and wired to a route produces a route that refuses for everyone — superadmin
included — until someone activates it:

Activate from `/features` or seed with `FeatureFlagSeeder` — NOT via tinker,
which writes the store row without forgetting the resolved snapshot and leaves
the page stale for its TTL.

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
| P7-A4 | `tests/Feature/FeatureFlag/FeatureFlagUiRenderTest.php` — render gate for both branches | ✅ DONE |
| P7-A5 | Audit pass — `align-middle` on all five `<th>` (house convention, was on the `<table>` only), and `ConfirmActionUsageTest` extended to see the `<input>` switch. See § Group A audit below | ✅ DONE |

**Group A audit (2026-10-01).** Audited against the code, not the plan. Group A
held; two real gaps, both now closed:

| Gap | Fix |
|---|---|
| `<th>` had `align-middle` on the `<table>` instead of per-header, unlike users (7), roles (2) and permissions (2) | Added to all five headers. Broke `the_toggle_column_is_centred`, which pinned the exact header string — the assertion was right, so the string was updated, not the fix reverted |
| The `<input>` switch was **invisible to `ConfirmActionUsageTest`** — the trigger regex matched `<button\b` only, so `confirm-action`'s `tag` prop escaped verification entirely | Regex now `<(?:button\|input)\b`; `features.index` added to the data provider; the `data-action-type` assertion now skips own-copy triggers (which must carry `data-title`) instead of demanding the null default the component deliberately refuses to set. 8 tests → 9 |

While fixing the second, the same bug class showed up in the hand-built-trigger
ban: its glob covered `views/pages/` only, so a hand-built trigger inside
`views/components/` would have escaped too. Widened to both.

Both fixes sabotage-verified — reverting the regex turns the suite red on *"the
features page must still offer its per-flag switches"*, and reverting the glob
turns it red on the hand-built ban.

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
| P7-B1 | `config/pennant.php` — 8 flags across 5 groups (Users / Settings / Security / Audit / Monitoring): `users`, `roles`, `permissions`, `settings`, `translations`, `sessions`, `activity_logs`, `pulse`. Published `stores` block kept byte-identical — it carries the `PENNANT_STORE` env wiring deploy reads | ✅ DONE |
| P7-B2 | `AppServiceProvider::boot()` — declaration loop over `config('pennant.features')`, plus `Feature::resolveScopeUsing(fn () => 'global')` | ✅ DONE |
| P7-B3 | `App\Support\FeatureCatalog` — `slugs()`, `all()`, `grouped()`, `find()`, `has()`, `isDisabledInConfig()`, `isActive()`. Same role `PermissionCatalog` plays for permissions: one source, so seeder / menu / view / tests cannot disagree | ✅ DONE |
| P7-B4 | `database/seeders/FeatureFlagSeeder.php` — activates every catalogue slug when absent; deactivates **only** when config says `disabled => true`. Idempotent; never blanket-activates, so a flag an operator turned off stays off across a reseed | ✅ DONE |
| P7-B5 | Registered in `DatabaseSeeder` | ✅ DONE |
| P7-B6 | `tests/Feature/FeatureFlag/FeatureFlagCatalogTest.php` | ✅ DONE |
| P7-B7 | Audit pass — 8 tests verified green, seeder non-destructiveness proven live, `stores` block diffed against vendor. See § Group B audit below | ✅ DONE |

**Group B audit (2026-10-01).** Audited against the code and the database, not
the plan. Group B holds; nothing in progress, nothing blocking.

| Check | Result |
|---|---|
| `FeatureCatalog::isActive()` is the only reader | Confirmed by grep — the sole two callers are `FeatureIndexAction` and `FeatureToggleAction`. No second implementation can drift |
| Every flag declares `label`/`group`/`description` | 8/8 each. No consumer relies on a default |
| Published `stores` block vs vendor | `diff` reports IDENTICAL, so the `PENNANT_STORE` env wiring the deploy reads is intact |
| Seeder is idempotent | Ran live: operator deactivated `pulse`, reseeded, `pulse` stayed `false` and the row count held at 8 — an operator's decision survives a reseed, which is the whole point |
| `Feature::stored()` returns a flat name list | Ran live: 8 names, correct type. It is a `@method` annotation on the facade (`Feature.php:26`) forwarding to the driver's `CanListStoredFeatures`, not a real method — safe, but a Pennant major could drop it without a signature error |
| `resolveScopeUsing('global')` present | Yes. Load-bearing: without it Pennant scopes per user and the page would show one person's answer as the installation's |

**Two gaps, neither a defect.** `disabled => true` is implemented and tested but
**unused** — all 8 flags are plain. Correct today: a config-disabled flag needs
its route gated first (Group C), or it refuses for everyone with no way back but a
deploy. And no test pins the slug count; `assertSame(count(slugs()), $rows)`
compares the store against config, so adding a flag passes and accidentally
deleting one still fails. A hardcoded 8 would break on every legitimate addition.

**`FeatureFlagSeeder` uses `activate()`, not `activateForEveryone()`** — measured,
not assumed. The database driver implements "all scopes" as
`setForAllScopes` → `where(name)->update()`, which on a flag with no row matches
nothing and writes nothing, silently. That is every flag this seeder exists to
create. `activate()` resolves the scope and inserts.

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

### Group C — Enforcement Middleware ✅ DONE

> Goal: a disabled flag makes the endpoint **invisible** (403) and the menu item
> **absent**, for every user including superadmin.

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-C1 | `App\Http\Middleware\EnsureFeatureIsEnabled` — `handle($request, $next, string ...$features)`; any `FeatureCatalog::isActive()` false → `abort(403)`. **No `features.manage` bypass.** Fail-closed by construction: an unknown slug is `false` | B | ✅ DONE |
| P7-C2 | Registered as `'feature'` in `bootstrap/app.php` | C1 | ✅ DONE |
| P7-C3 | `@feature` / `@endfeature` — **already registered** by the package (`PennantServiceProvider.php:46`, `:54`). Verified, not re-registered | B6 | ✅ DONE |
| P7-C4 | `Gate::before()` deliberately NOT extended — no superadmin bypass | C1 | ✅ DONE |
| P7-C5 | `tests/Feature/FeatureFlag/FeatureFlagMiddlewareTest.php` — 9 tests | C2 | ✅ DONE |

**Why Pennant's own middleware cannot be aliased.** Two reasons, both measured
rather than read off the docs:

```php
// Pennant resolves through Feature::active(), which asks the store.
config(['pennant.features.users.disabled' => true]);
FeatureCatalog::isActive('users')    => false   ← the correct answer
Feature::active('users')            => true    ← Pennant's view
Feature::someAreInactive(['users'])  => false   ← what its middleware sees
```

A `disabled => true` flag with a stored `true` row reads as ACTIVE to Pennant, so
aliasing its class would leave the config kill switch inert on every gated route.
It also aborts **400** (Decision 2). One class, ~10 lines of body, both reasons
settled — the duplication is a different *answer*, not duplicate logic.

**`Gate::before()` is untouched.** It returns `true` for superadmin and `null`
otherwise. A flag off refuses everyone, managers included; a manager re-enables
from `/features` first, which is why that page is deliberately left ungated.

**Multi-flag syntax — the alias is written ONCE.** Laravel splits a middleware's
parameters on the *first* colon and then on commas (`Pipeline.php:241`):

| Written | Resolves to | Result |
|---|---|---|
| `feature:users,roles` | `['users', 'roles']` | correct — ANDed |
| `feature:users,feature:roles` | `['users', 'feature:roles']` | 403, always — `'feature:roles'` is an undeclared slug |

The second form fails closed, so it looks like a working kill switch rather than
a typo. `several_flags_must_all_be_active` pins the correct spelling.

**Sabotage-verified.** Each guard fails loudly when removed:

| Sabotage | Result |
|---|---|
| Add a `features.manage` bypass | 7 assertions red, naming the role |
| `abort(403)` → `abort(400)` | 7 assertions red |
| Read `Feature::active()` instead of `isActive()` | red: *"the kill switch is decorative"* |

**Gate C: PASSED.** Off → 403 for admin **and** superadmin · on → passes ·
undeclared slug → 403 · `features.manage` holder → still 403 · `disabled => true`
beats a stored active row · several flags ANDed · `/features` never gated.

**Group C supplied the boundary, Group D attached it.** The middleware shipped
first and gated nothing until `P7-D7`/`D8` put it on the routes — the phase was
deliberately built in that order so the boundary could be proved on its own
throwaway routes before 59 real ones depended on it.

### Group D — Route Gating & Menu ✅ DONE

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-D7 | `routes/web.php` — one `feature:{slug}` group per flag, not a call per route | C2 | ✅ DONE — 33 routes |
| P7-D8 | `routes/api.php` — the same matrix | D7 | ✅ DONE — 29 routes |
| P7-D9 | `AppMenuComposer` — a `feature` key per item, checked **before** `permission` | D7 | ✅ DONE — 7 items |
| P7-D10 | `tests/Feature/FeatureFlag/FeatureFlagMenuTest.php` | D9 | ✅ DONE — 9 tests |

**Gate D: PASSED.** 62 of 62 module routes resolve a `feature:` middleware,
verified by walking `gatherMiddleware()` at runtime rather than by reading the
diff. `route:list` does **not** show it — its Middleware column omits the group,
so a reader checking the gate there sees nothing.

**The first pass was not clean, and that is the finding.** It left
`users.bulk-action`, the four state toggles and the email-change flow outside the
group — precisely the partial gate the grouping exists to prevent. A diff review
missed it; the runtime walk caught it.

**Two deliberate exclusions:**

- `logout` sits **outside** `feature:sessions`. Logging out must keep working when
  the module is off, or a bad flag strands an admin who cannot end a session.
- roles and permissions are **two** groups, not one `feature:roles,permissions`.
  ANDing them would switch off the permission catalogue whenever roles are off.
  The catalogue is defined in code (P6-C7), so roles being off does not
  invalidate it — which is exactly why it is its own flag.

**Not gateable yet:** `translations` and `activity_logs` have no routes
(`Route::has()` is false for both). Those modules ship in Phase 8, so **two of the
eight flags control nothing** until then. The menu items carry a `feature` key
anyway, so the entries keep working — and keep hiding — when the modules land,
and the `/features` rows are marked `pending` so an operator is not told
"Inactive" for a switch that changes nothing.

`pulse` looked like the same case and was not: it is a *vendor* route, and the
audit measured `GET /pulse` returning **200 with the flag off** — a live
dashboard behind a switch that read Inactive. It is now gated through
`pulse.middleware`, which is the vendor's own extension point for exactly this,
so the matrix reaches 62/62 without this project declaring the route.

**P7-E4 churn happened, and was one root cause.** 274 tests failed with 403 —
none about feature flags, all because a test visiting `/users` had no seeded flag
and declaring does not activate. Fixed once in `tests/TestCase.php`: every test
starts with all flags ACTIVE, which is the state a real install is in after
`FeatureFlagSeeder`. One file rather than 74.
`FeatureFlagCatalogTest` opts out via `shouldSeedFeatureFlags()` because
`every_catalogued_flag_resolves_off_until_it_is_seeded` exists to assert an
UNSEEDED database; that assertion was not weakened to go green.

### Group F — Bulk Feature Actions ✅ DONE

> Added 2026-10-01. Follows `design-system.md` § Bulk Actions, which documents the
> shipped `#bulkBar` contract from the users and roles pages.

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-F1 | `enable_feature` / `disable_feature` keys in `ACTION_CONFIG` (`resources/js/helpers/action-config.js`) — variants per § Action Color Convention: `enable => success`, `disable => warning`. Needed because `data-bulk-keys` resolves copy through `ACTION_CONFIG` and neither key exists yet (12 keys today, none flag-related) | — | ✅ DONE |
| P7-F2 | `tests/Feature/FeatureFlag/BulkActionCopyTest.php` — every action the bulk bar offers must resolve to both a dropdown label (`actionOptions`) and modal copy (`ACTION_CONFIG`). Both miss silently, so a typo renders an empty dropdown with nothing for a test run to catch | F1 | ✅ DONE |
| P7-F3 | `App\Actions\V1\Feature\FeatureBulkToggleAction` — one POST, all selected slugs, one audit entry `feature.bulk_toggled` with the full slug list and per-slug `from`/`to`. **Read all `from` values before writing any** | D3 | ✅ DONE |
| P7-F4 | `POST /features/bulk-action` (`features.bulk-action`, `can('features.manage')`) + `App\Http\Requests\V1\Feature\BulkFeatureRequest` — one `features.manage` gate for both directions. **Not** `AuthorizesBulkAction`: the trait maps `action -> <prefix>.<suffix>`, so it would demand a `features.delete_feature` permission that does not exist | F3 | ✅ DONE |
| P7-F5 | `#bulkBar` on `pages/features/index.blade.php`: `data-bulk-states='{"active":["disable_feature"],"inactive":["enable_feature"]}'`, `data-bulk-mixed="disable_feature"`, `data-bulk-keys` from F1, `data-bulk-field="features[]"` | F1, F4 | ✅ DONE |
| P7-F6 | `tests/Feature/FeatureFlag/FeatureFlagBulkTest.php` — bulk enable and disable; mixed selection offers only actions safe for all rows; a slug outside the catalogue is refused; `features.manage` holder only | F5 | ✅ DONE |
| P7-F7 | `tests/Feature/CrossCutting/AssetBundleFreshnessTest.php` — the built bundle must contain the feature actions, and the driver's select-all selector must match every page's markup. Added after the dropdown shipped empty: no PHP test can see JavaScript, so 800+ tests passed while the browser ran a stale bundle | F1, F5 | ✅ DONE |
| P7-F8 | `tests/Feature/FeatureFlag/FeatureSelectAllTest.php` — runs the real bundle over both page shapes in separate vm contexts. Proves select-all works on users/roles (id) and features (class, one table per module), and that a card header ticks only its own card | F5, F7 | ✅ DONE |
| P7-F9 | `tests/Feature/FeatureFlag/FeatureBulkDropdownTest.php` — the dropdown offers only actions safe for the selection: active→disable, inactive→enable, mixed→only the one safe action. Forces an active/inactive spread, since the seeder activates every flag | F1, F5 | ✅ DONE |

**Gate F:** the bar appears on first tick and clears after submit · a mixed
selection cannot apply `disable_feature` to an already-active flag's twin ·
`data-bulk-keys` names keys that exist in `ACTION_CONFIG` · one audit row per
request with correct `from`/`to` · 403 for a user without `features.manage`.

**`data-bulk-mixed` is the rule to get right here.** With 3 active and 2 inactive
flags selected, only `disable_feature` is safe for all five. Offering
`enable_feature` because the first row was inactive would flip flags the user did
not mean to touch — and for a kill switch, an accidental enable is the more
expensive direction.

### Group E — Tests, Verification, Docs ✅ DONE (all seven closed; three under a different shape than planned)

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P7-E1 | 403 web + API for a **superadmin**; menu item gone; an undeclared slug 403s (fail-closed); a declared-but-never-activated flag 403s (the Pennant trap). **No `FeatureFlagTest.php` was created** — the coverage exists, split by concern: `FeatureFlagMiddlewareTest` (every role incl. superadmin, `features.manage` holder, undeclared slug, config kill switch, multi-flag AND), `FeatureFlagRouteTest` (API logout-all gate, sidebar), `FeatureFlagMenuTest`, `FeatureFlagCatalogTest` (the never-seeded trap) | C, D | ✅ DONE |
| P7-E2 | Round trip — POST the toggle URL the Group A switch rendered; assert the new state reached the store, the cache flushed, the audit row exists with correct `from`/`to`, and a follow-up GET reflects it. Store + audit + flush in `FeatureFlagRouteTest`; the follow-up GET in `FeatureFlagPerformanceTest::a_toggled_flag_is_reflected_by_the_next_page_view`, which re-resolves and checks the row and the counter together | D3 | ✅ DONE |
| P7-E3 | `ConfirmActionUsageTest` — add `features.index`, and **extend the trigger regex to `<input\b`**. It currently matches `<button\b` only (`:153`), so the new switch is never checked at all — which is how `confirm-action`'s `tag` prop escapes verification entirely | A3 | ✅ DONE (in the Group A audit) |
| P7-E4 | Regression — `php artisan test` green. Every pre-existing test touching a newly flag-gated route needs the flag **active** in its `setUp()`, not a deleted assertion. Closed by one change in `tests/TestCase.php` — every test starts with all flags ACTIVE, the state a real install is in after `FeatureFlagSeeder`, rather than 74 per-file `setUp()` edits | D7 | ✅ DONE |
| P7-E5 | Performance — assert the index resolves N flags in a flat number of store reads, pinned as a **delta at two flag counts**. A ceiling like "under 20" passes for an N+1 that happens to fit under a number someone picked | D2 | ✅ DONE — `FeatureFlagPerformanceTest`, and it found a real one: `resolve()` called `isActive()` per slug, measuring 2 / 4 / 8 queries for 2 / 4 / 8 flags. `FeatureCatalog::activeMap()` reads them in one `WHERE name IN (...)`. Sabotage-verified: restoring the loop turns it red at 2 vs 8 |
| P7-E6 | Docs — `docs/base/features/feature-flags.md` (the phase happened; resolve the 400-vs-403 question; document the activation requirement), `task-tracker.md`, `progress.md`, `feature-tracker.md` row 24, and fix the broken link at `docs/base/ui/ui-architecture.md:158` → file is at `docs/base/features/feature-flags.md` | A–F | 🟡 PARTIAL — the two doc items closed in the Group A audit (`feature-flags.md` rewritten: catalogue, activation requirement, 403 settled, no-bypass rule; the `ui-architecture.md` link fixed). Trackers close with the phase. `feature-tracker.md` row 24 still reads "done" while enforcement does not exist |
| P7-E7 | Full verification — `php artisan test`, `npm run build`, `vendor/bin/pint --test`, `php artisan view:cache` | A–F | ✅ DONE — 846 passed / 3030 assertions, build OK, view:cache OK, pint passed on every Phase 7 file (repo-wide pint still fails on 33 PRE-EXISTING files, none from this phase) |

---

## Performance Notes

| Risk | Mitigation |
|---|---|
| Store read per flag per request | `FeatureCatalog::isActive()` goes through one reader and the index resolves once in `FeatureIndexAction`, never in a Blade loop (`P7-D2`, `P7-E5`) |
| Sidebar cost | the composer runs once per request; after D9 it calls `isActive()` per item, in-memory after the first read |
| Middleware ordering | `feature:` must run **after** `auth`. Inside the authenticated group that is automatic. On a public route an unauthenticated 403 reveals the flag exists — acceptable, the flag's existence is not a secret, but noted |
| Bulk toggles | one request, one audit row, all `from` values read before any write (`P7-F3`) |

## Security Notes

| Vector | Control |
|---|---|
| Flag bypass via superadmin | none exists — `EnsureFeatureIsEnabled` will have no `can()` escape hatch (`P7-C1`, `P7-C4`) |
| Flag bypass via API | covered — the same middleware is on `routes/api.php` (`P7-D8`), verified as part of the 62/62 walk |
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
ponytail: no features.manage bypass. A flag off refuses everyone, managers
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
Two classes, one line of duplicated logic, and the difference is 400 vs 403 — a
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
Group C (Middleware + Blade)       — C1 → C2 → C3 → C4 → C5      ✅
                                     ↓ Gate C: off => 403 for everyone, superadmin included
Group D (Route/menu gating)        — D7 → D8 → D9 → D10          ✅
                                     ↓ Gate D: matrix holds on web + API + sidebar
Group F (Bulk feature actions)     — F1 → F2 → F3 → F4 → F5 → F6 ✅
                                     ↓ Gate F: bulk safe for mixed selections, audited
Group E (Tests + docs)             — E1 → E7 ✅
                                     ↓ Gate E: full regression green
                                     Phase 7 COMPLETE (846 tests / 3030 assertions)
```

Sequential — C cannot be skipped, and it is what turns a flag into a kill switch.

## Task Tracker Reconciliation

- `FLAG-001` (Pennant foundation) stays **DONE** — Phase 1 shipped the package; this phase is what finally uses it.
- `FEAT-001` / `FEAT-002` move to **DONE** — both were rollups for exactly this work, and it shipped. The notes they carried while waiting ("`EnsureFeatureIsEnabled` does not exist", "no route carries the matrix") described the state before Groups C and D.
- `docs/planning/feature-tracker.md` row 24 (`Feature flags (DB-backed)`) read "done" back when the table existed and nothing used it — the same false positive. It now describes what actually ships, including which flags still control nothing.