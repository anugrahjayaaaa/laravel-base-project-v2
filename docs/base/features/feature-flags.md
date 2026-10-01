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

Eight flags ship today, across four module groups: `users`, `roles`,
`permissions`, `settings`, `translations`, `sessions`, `activity_logs`, `pulse`.

`registration` is deliberately **not** a flag. `registration_enabled` is already a
`system_settings` row read at all four entry points, so a flag for it would be two
writers for one question and whichever ran last would win with neither knowing.

### Declaring a flag is not activating it

**This is the trap worth knowing.** With the `database` store, `Feature::active()`
resolves against a row in `features`. **No row → false, fail-closed.** A flag
added to config and wired to a route produces a route that refuses for everyone,
superadmin included, until it is activated:

```
php artisan tinker --execute="Laravel\Pennant\Feature::activate('users');"
```

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

`/features` itself is deliberately **never** gated. A gate there would remove the
only page that can bring a flag back, leaving a redeploy as the sole way out.

### Management UI

`/features` (`can('features.view')`), toggling via
`POST /features/{feature}/toggle` (`can('features.manage')`). A viewer without
`features.manage` sees status badges rather than disabled switches — a control
that looks editable and silently discards input is worse than an absent one.
Every toggle audits `feature.toggled` with `from`/`to`, reading the old value
**before** the write.

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

// Define a feature (in a feature class or via Pennant::define)
// Check in application/controller code — enforcement boundary
if (! FeatureCatalog::isActive('new-dashboard')) {
    abort(403);
}

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

## Settings Integration

Feature availability may be driven by settings:
```
registration.enabled
```

Or by feature flags:
```
features.new_dashboard.enabled
```

## API Responses

When a feature is disabled:
```json
{
  "message": "This feature is not available.",
  "code": "FEATURE_UNAVAILABLE",
  "meta": { "feature": "new_dashboard" }
}
```

When a feature is available but user lacks permission:
```json
{
  "message": "This action is unauthorized.",
  "code": "FORBIDDEN"
}
```

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