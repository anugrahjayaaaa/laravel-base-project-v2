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

Actual feature-specific flags are NOT implemented in Phase 1 — they are added
by future feature phases when a feature requires one.

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
- A feature may be available but the user lacks permission → 403 Forbidden.
- A feature may be unavailable even with permission → 404 or feature disabled response.

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