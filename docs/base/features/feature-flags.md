# Feature Availability

## Concept

Three conceptual layers:
```
Authentication → Authorization → Feature Availability
```

- **Permission answers**: "WHO can use this?"
- **Feature availability answers**: "IS this capability available?"

## Enforcement

- Feature availability must be enforced at the backend/application boundary.
- UI hiding is NOT security.

## Feature Flag Package Foundation (Phase 1)

Phase 1 includes the **foundation** for a feature-flag system using **Laravel
Pennant** (`laravel/pennant`):

- Pennant is installed via Composer and auto-discovered (no manual provider
  registration required on Laravel 13+).
- A storage migration creates the `features` table (the default `database`
  store). The store is configurable via the `PENNANT_STORE` environment
  variable (`database` or `array`).
- The `@feature`/`@featureany` Blade directives are available for conditional
  UI rendering (UX convenience only — backend enforcement is always required).
- Feature classes use Pennant's `Feature` base class with the standard
  `laravel/pennant` conventions — no custom abstraction layer is introduced.

See [Pennant Stores](#pennant-stores) for store configuration and the
[Storage Migration](#storage-migration) section for migration details.

Actual feature-specific flags arrive in **Phase 7** — see
[Flag Catalogue (Phase 7)](#flag-catalogue-phase-7).

## Flag Catalogue (Phase 7)

Flags are declared in `config/pennant.php`, not stored with labels and
descriptions in the database. A flag's identity and its human copy are code, like
a permission name in `PermissionCatalog` — a DB row nothing reads is a trap.

Eight flags ship today, across five module groups (Users, Settings, Security,
Audit, Monitoring): `users`, `roles`, `permissions`, `settings`, `translations`,
`sessions`, `activity_logs`, `pulse`.

**Five of the eight are wired to something.** `users`, `roles`, `permissions`,
`settings` and `sessions` gate real routes. `pulse` gates the vendor dashboard
through `pulse.middleware`. `translations` and `activity_logs` are declared ahead
of their modules and gate nothing yet — their rows on `/features` say so, rather
than presenting a switch that reports success and changes nothing.

`registration` is deliberately **not** a flag. `registration_enabled` is already a
`system_settings` row read at all four entry points, so a flag for it would be two
writers for one question and whichever ran last would win with neither knowing.

### Declaring a flag is not activating it

**This is the trap worth knowing.** With the `database` store, `Feature::active()`
resolves against a row in `features`. **No row → false, fail-closed.** A flag
added to config and wired to a route produces a route that refuses for everyone,
superadmin included, until it is activated:

Activate it from `/features` (needs `features.manage`), or run the seeder on a
fresh install:

```
php artisan db:seed --class=FeatureFlagSeeder
```

**Do not activate through `tinker`.** `Feature::activate()` writes the store row
and stops there; it does not forget the resolved snapshot, so `/features` keeps
rendering the old state for up to 30 seconds — and a user holding only
`features.view` has no write path to force it cold. The toggle actions and the
seeder both flush, which is why they are the supported route.

Every flag therefore needs **both** a config entry **and** an activation.
`FeatureFlagSeeder` makes that non-forgettable: it activates any catalogue slug
with no row, and deactivates **only** where config says `disabled => true`. It
never blanket-activates, so a flag an operator turned off stays off across a
reseed.

Scope is forced global via `Feature::resolveScopeUsing(fn () => 'global')`.
Without it Pennant scopes per authenticated user, and the management page becomes
a lie — it would show one person's flags as though they were the installation's.

Read a flag through `FeatureCatalog::isActive($slug)`, never
`Feature::active()` directly: it consults config first, so `disabled => true`
wins over a row an operator set earlier.

### Enforcement

Routes are gated with the `feature:` alias, which maps to
`App\Http\Middleware\EnsureFeatureIsEnabled`:

```php
Route::middleware(['auth', 'feature:users'])->group(function () {
    // ...
});
```

Any inactive flag → `abort(403)`. Variadic, so several flags are ANDed — and the
alias is written **once**: `feature:users,roles`. Repeating it
(`feature:users,feature:roles`) resolves the second entry to the literal string
`'feature:roles'`, an undeclared slug, which fails closed into a 403 that looks
exactly like a working kill switch.

**Why this project's own middleware rather than Pennant's.** Two reasons, both
measured: Pennant's aborts **400**, and it resolves through `Feature::active()`,
which asks the store — so a `disabled => true` flag with a stored active row reads
as *active* to it. Going through `FeatureCatalog::isActive()` is what makes the
store state and the config override answer the same question.

### Applying the gate

```php
Route::middleware('feature:users')->group(function () {
    // every route of the module
});
```

**One group per flag, never a call per route.** A flag applied to 3 of 9 routes is
a partial gate, and the routes missed keep working. The group is the unit, so a
route added inside it inherits the gate by construction.

Gate the **web and API** sides identically — an API-only gap is the same hole under
a different URL. Two exclusions worth knowing:

- `logout` stays outside `feature:sessions`. Logging out must keep working when the
  module is off, or a bad flag strands an admin who cannot end a session.
- Give each flag its own group. `feature:roles,permissions` ANDs them, which
  switches the permission catalogue off whenever roles are off.

`route:list` does **not** show `feature:{slug}` in its Middleware column. To audit
the gate, walk the routes instead:

```php
array_walk_recursive($route->gatherMiddleware(), fn ($m) =>
    is_string($m) && str_starts_with($m, 'feature:') && print($m));
```

**The sidebar follows the routes.** `AppMenuComposer` takes a `feature` key per
item and checks it *before* the permission, because an item with no permission
would otherwise survive its flag. Superadmin is the case that matters: they pass
every `can()`, so a permission-only filter leaves the item visible to the one
person guaranteed to click it and be refused.

`/features` itself is deliberately **never** gated. A gate there would remove the
only page that can bring a flag back, leaving a redeploy as the sole way out.

### Management API

The management surface is also exposed over the API, sharing the same actions
and the same `BulkFeatureRequest`, so the `features.manage` gate and the
undeclared-slug refusal cannot drift between channels:

| Route | Gate |
|---|---|
| `GET /api/v1/features` | `can('features.view')` |
| `POST /api/v1/features/{feature}/toggle` | `can('features.manage')`, plus the check in the controller |
| `POST /api/v1/features/bulk-action` | `BulkFeatureRequest::authorize()`; `throttle:bulk-action` |

**None of them carries `feature:` middleware, and none ever may.** The
middleware goes on the routes of the modules being *controlled*. Putting it on
the flag management API means switching a flag off deletes the only way to
switch it back on — the module disables its own recovery. `FeatureFlagApiTest`
walks every declared slug in turn for exactly this: switch it off, then switch
it back on through the API. A test that checked one slug would pass while the
group was gated on some other flag.

**The read is a projection, not the page model.** `GET /api/v1/features` serves
`FeatureIndexAction`'s resolved rows cut down to `slug`, `label`, `group`,
`description`, `enabled`, `pending`. The action also builds a web `toggle_url`,
the viewer's `manageable` bit and each flag's owned `permissions` — a JSON client
cannot POST to the first (session + CSRF), the second is a button affordance, and
the third is management copy. `FeatureFlagApiTest` pins the six keys so the page
model cannot leak back in. `pending` is carried because a flag whose module has
no routes yet is not "disabled".

The state the read serves is the state the writer produced: `FeatureToggleAction`
flushes `FeatureIndexAction::SNAPSHOT_KEY`, so a toggle followed by a read cannot
answer from a snapshot up to 30 seconds old.

### Management UI

`/features` (`can('features.view')`), toggling via
`POST /features/{feature}/toggle` (`can('features.manage')`). A viewer without
`features.manage` sees status badges rather than disabled switches — a control
that looks editable and silently discards input is worse than an absent one.
Every toggle audits `feature.toggled` with `from`/`to`, reading the old value
**before** the write.

`POST /features/bulk-action` (`features.manage`) does the same for a selection:
one request, one transaction, one `feature.bulk_toggled` row carrying the full
`requested` list plus each slug's own `from`/`to` and the ones that were
already in that state. Every `from` is read before the first write — read after,
it is always the new value and the row records a change from a state that never
existed. A slug outside the catalogue aborts the whole batch before any flag is
touched.

`data-bulk-mixed="disable_feature"` is the rule the bar encodes: on a mixed
selection only disable is safe for every selected row, and for a kill switch an
accidental enable is the more expensive direction.

**Both events carry no subject.** Spatie hangs audit rows off a model via
`->on($model)`; a Pennant flag is a row in a store the package owns, so these
write a causer and properties with `subject_type`/`subject_id` null. Inventing
a model to hang them on would put a row in the database whose only job is to be
an audit target. A viewer filtering by subject therefore has to filter by
`subject_type IS NULL AND properties->>'feature'`, not by a subject relation.

## Use Cases

Feature availability controls:
- Module enable/disable
- Beta feature rollout
- Maintenance mode
- Registration enable/disable
- Password policies configuration
- Any capability that can be toggled on/off

## Implementation

Feature flags use Laravel Pennant (`Laravel\Pennant\Feature`):

```php
use Laravel\Pennant\Feature;

// Identity is declared in config/pennant.php, NOT via Pennant::define
// Read through the catalogue — it is the only place the kill switch is applied
if (! FeatureCatalog::isActive('pulse')) {
    abort(403);
}

// Many flags at once, in ONE store read (a per-slug loop is an N+1)
$states = FeatureCatalog::activeMap(FeatureCatalog::slugs()); // ['users' => true, ...]

// Blade (UX only — backend must still enforce)
@feature('new-dashboard')
    <!-- rendered content -->
@endfeature
```

Feature availability is separate from authorization:
- A feature may be available but the user lacks permission → **403 Forbidden**.
- A feature may be unavailable even with permission → **403 Forbidden**.

**403 — settled 2026-10-01** (this was 404 until then). A disabled module is
refused exactly the way an unpermitted one is.

| Status | Truthful for a disabled module? |
|---|---|
| **400** | **no** — the request was well-formed; the server declined to serve it |
| **404** | **no** — the route does exist; 404 produces a "this 404s intermittently" support trail |
| **403** | **yes** — understood, and not serving it |

403 is also what `can:` and `CheckAccountState` already return, so one status
carries one meaning across the whole admin. The cost: *module killed*, *no
permission* and *account disabled* share a status — a client needing to tell them
apart asks `/features` (never gated) rather than inferring from the code.

**Why not Pennant's middleware.** Two reasons, the second decisive:

1. It aborts **400**.
2. It **cannot read `disabled => true`** — it resolves through `Feature::active()`,
   which asks the store:

   ```php
   config(['pennant.features.users.disabled' => true]);
   FeatureCatalog::isActive('users')    => false   ← correct
   Feature::active('users')            => true    ← Pennant's view
   Feature::someAreInactive(['users'])  => false   ← what its middleware sees
   ```

   Aliasing it would leave the config kill switch inert on every gated route.
   `App\Http\Middleware\EnsureFeatureIsEnabled` goes through
   `FeatureCatalog::isActive()` so store state and config override agree.

`AuthController` still 404s `registration` (`abort_unless($registration_enabled, 404)`)
— a *setting*-gated feature, deliberately left as it was.

**No `features.manage` bypass.** A flag off refuses the route for everyone,
managers included; a manager re-enables from `/features` first. A kill switch
superadmin can walk through is not a kill switch, and "the feature is off but the
CEO can still see it" is a state nobody asked for.

## Permission Integration

- Feature management itself must only be accessible to users with appropriate permission.
- Permission to manage features: `features.manage`.
- Permission to view features: `features.view`.

### A permission follows its module's flag

`PermissionCatalog::FEATURE_BY_RESOURCE` maps a **resource prefix** to the flag
that owns it (`audit.*` → `activity_logs`, `queues.*` → `vantage`). It is the one
answer to "whose permission is this", and three things consume it:

| When a flag goes off | What happens | Where |
|---|---|---|
| The grant | removed from every role and every direct user grant, inside the toggle's transaction, and named in the audit row's `revoked` property | `FeatureRevokePermissionsAction`, called by `FeatureToggleAction` / `FeatureBulkToggleAction` |
| The offer | hidden from the role matrix and the permission index | `PermissionCatalog::visibleNames()` |
| The write path | a grant the matrix cannot render is kept as it is; a posted id the matrix cannot offer is ignored | `PersistsRole::assignablePermissionIds()` |

Four consequences that are decisions, not details:

- **The revoke is final.** Switching the flag back on re-grants nothing.
  Restoring would need the previous holdings stored somewhere, and the state the
  operator asked for — "this module is off, nobody holds its permissions" —
  would silently undo itself. Re-granting is a deliberate act through the role
  matrix.
- **Hiding without revoking would be a data-loss bug.** A flag switched off in
  `config/pennant.php` (the deploy-time kill switch) never runs a toggle, so the
  grant is still in the database while the checkbox is gone: the matrix cannot
  show it and nobody can remove it through the UI.
- **`features` is the one resource with no flag**, and `translations` / `sessions`
  own no permissions at all. The page that re-enables a flag cannot itself be
  behind a flag, and its `features.view` / `features.manage` permissions are
  therefore unconditional. `PermissionFeatureMapTest` asserts the unmapped set is
  exactly `PermissionCatalog::UNMAPPED_RESOURCES` and that every mapped flag is
  declared, so a new resource with no flag has to be recorded on purpose.
- **The write path filters rather than refuses.** There is no checkbox an operator
  could have ticked to send a hidden id, so the only caller that can is one that
  never saw the matrix — the role is created without that permission instead of
  answering 422.

The flags on **both** monitoring dashboards are also ANDed into their gates
(`viewPulse`, `viewVantage`), so a `@can()` reads the same answer the route
middleware does. See ADR-024.

### Which flags own permissions

| Flag | Permissions it owns — revoked from every role and user when the flag goes off |
|---|---|
| `users` | `users.view`, `users.create`, `users.update`, `users.delete`, `users.force_delete`, `users.restore`, `users.activate`, `users.deactivate`, `users.lock`, `users.unlock`, `users.assign_roles` |
| `roles` | `roles.view`, `roles.create`, `roles.update`, `roles.delete`, `roles.force_delete`, `roles.restore`, `roles.assign_permissions` |
| `permissions` | `permissions.view` |
| `settings` | `settings.view`, `settings.manage` |
| `notifications` | `notifications.view`, `notifications.manage`, `notifications.send_test` |
| `activity_logs` | `audit.view`, `audit.export` — resource `audit`, flag `activity_logs` |
| `pulse` | `pulse.view` |
| `health` | `health.view` |
| `vantage` | `queues.view`, `queues.retry` — resource `queues`, flag `vantage` |
| *no flag* | `features.view`, `features.manage` — the resource is deliberately unmapped, so no switch can revoke them |
| `translations`, `sessions` | none — these flags own no permissions |

`PermissionFeatureMapTest` keeps the map behind this table honest: it fails when a
resource is missing an entry, when a mapped flag is not declared, and when the
unmapped set drifts from `PermissionCatalog::UNMAPPED_RESOURCES`.

**Disabling a flag is not just hiding a page, and the UI says so.** The
confirmation modal for a flag that owns permissions states that disabling hides
the module for everyone and revokes those permissions from all roles and users,
and that re-enabling does not restore them — so they have to be assigned again.
It names the permission when the flag owns exactly one (`pulse`, `health`) and
counts them when it owns more; a flag that owns none keeps the plain
hide-for-everyone copy. The bulk-disable modal carries the same warning for the
whole selection. Both are held to **two sentences** by test
(`FeatureFlagUiRenderTest`, `BulkActionCopyTest`): a confirmation modal is read
in one glance, and a paragraph hides the consequence it exists to state.
`FeatureIndexAction` hands each row its `permissions` for that copy, so the wording
cannot drift from the map above.

**Re-enabling a flag is only half of restoring a module.** The permissions stay
revoked, so an admin re-assigns them afterwards — through the role matrix, or as a
direct grant on the user — before anyone holds them again. Until then the module
is reachable only through `Gate::before`, i.e. by `superadmin`, whose bypass needs
no permission row. That is the deliberate cost of the revoke being final: the
alternative — remembering the previous holdings and replaying them — would undo
the state the operator asked for.

## Settings Integration

Self-registration is a **SystemSetting, not a flag**:
```
registration_enabled
```
read through `SystemSetting::getBool('registration_enabled', false)`. It is
deliberately not duplicated as a flag — two writers for one switch is a race
with no winner. `FeatureFlagCatalogTest` pins that no `registration%` slug
competes with it.

Everything else is a flag: an identity in `config/pennant.php`, its state in the
Pennant store, read through `FeatureCatalog::isActive()`. There is no
`features.*.enabled` settings path.

## API Responses

A disabled feature and a missing permission both return **403**, and this
project does not distinguish them in the body:

```json
{
  "message": "Request could not be completed.",
  "code": "HTTP_ERROR"
}
```

That is the deliberate consequence of settling on 403 (see
[Enforcement](#enforcement)): one status, one code, no table for a client to
consult. `EnsureFeatureIsEnabled` calls a bare `abort(403)`, so nothing in the
response names the flag.

A client that genuinely must tell *module killed* from *no permission* from
*account disabled* cannot read it off the status. `GET /features` is not gated
on any flag (it carries `features.view`, never `feature:*`) and reports every
flag's state — that is the capability endpoint, and it is the intended way to
ask. A non-browser client asks the same question at
`GET /api/v1/features` (`features.view`), which returns the same resolved state
as `data.features`.

## Pennant Stores

| Store | Driver | Description |
|-------|--------|-------------|
| `database` | `database` | Default. Stores flag values in the `features` table. |
| `array` | `array` | In-memory store for testing. Reset between requests. |

Configure via `PENNANT_STORE` in `.env` (default: `database`).

## Storage Migration

The `features` table migration is published from Pennant's package and lives
at `database/migrations/`. It is included in the base migration set — no custom
migration is required. See the published migration:
`database/migrations/*_create_features_table.php`.

## ADR References

- DEP-001: Sanctum API authentication
- DEP-005: Native Laravel first