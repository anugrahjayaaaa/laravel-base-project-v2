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
added to config and wired to a route produces a route that 404s for everyone,
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
if (! Feature::active('new-dashboard')) {
    abort(404);
}

// Blade (UX only — backend must still enforce)
@feature('new-dashboard')
    <!-- rendered content -->
@endfeature
```

Feature availability is separate from authorization:
- A feature may be available but the user lacks permission → **403 Forbidden**.
- A feature may be unavailable even with permission → **404 Not Found**.

**404, not 400 — settled.** Pennant's own `EnsureFeaturesAreActive` middleware
aborts **400**, which is wrong here: 400 says "your request is malformed", sending
an integrator hunting a bug in their own code when in fact the server declined to
serve it. 403 says "you are not allowed", which invites an escalation request —
the wrong answer for a flag an operator turned off. 404 says "no such resource",
so the client stops asking. A kill switch exists so a module *disappears*.

Three places in this repo already answer 404 for a disabled capability:
`AuthController` (`abort_unless($registration_enabled, 404)`) and the matching
API controller. Pennant is the outlier and is not aliased.

**No `features.manage` bypass.** A flag off 404s the route for everyone,
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