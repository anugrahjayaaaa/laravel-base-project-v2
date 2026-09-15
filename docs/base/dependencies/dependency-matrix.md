# Dependency Matrix

> Single-glance reference for every dependency in the Base Project.
> See [overview.md](./overview.md) for full details per dependency.
|> **Phase 1 (Laravel Foundation + Environment) complete. Packages installed per dependency matrix.**

## Dependency Inventory

| Area | Implementation | Composer Package | Status | Priority | Direct App Usage |
|------|----------------|------------------|--------|----------|------------------|
| Framework | Laravel | `laravel/laravel` + `laravel/framework` | Required (core) | P0 | Yes |
| API Auth | Sanctum | `laravel/sanctum` | Required | P0 | Through Auth abstraction |
| RBAC | Spatie Permission | `spatie/laravel-permission` | Required | P0 | Through Gate/Policy layer |
| Audit Trail | Spatie Activitylog | `spatie/laravel-activitylog` | Required | P0 | Through Audit abstraction |
| Technical Observability | Telescope | `laravel/telescope` | Required | P1 | Restricted (technical users only) |
| API Documentation | Scramble | `dedoc/scramble` | Planned | P1 | Dev-only, documentation generation |
| Queue (default) | Laravel Queue | Native | Core | P0 | Through Queue facade |
| Cache (default) | Laravel Cache | Native | Core | P0 | Through Cache facade |
| Redis (option) | Redis backend | `predis/predis` / `ext-redis` | Optional / Deployment | P2 | Never directly (facades only) |
| Backup | Spatie Backup | `spatie/laravel-backup` | Planned | P2 | Infrastructure / scheduled jobs |
|| AdminLTE (UI) | Vendored ZIP (not npm) | AdminLTE release ZIP → `public/vendor/adminlte/` | Complete (v4.9.1) | P1 | Layout templates only |
| External Monitoring | (deployment choice) | `sentry/sentry-laravel` etc. | Deployment-specific | P3 | Through logging/observability layer |

## Status Legend

| Status | Meaning |
|--------|---------|
| Required (core) | Laravel framework itself — the foundation, never optional |
| Required | Package selected per architecture decision; to be installed at the documented Phase |
| Planned | Package decision made; to be installed at a later phase |
| Optional / Deployment | Package is optional; deployment may install if infrastructure exists |
| Deployment-specific | Varies per deployment; no single package selected by the Base Project |
| Core | Native Laravel capability — no Composer package needed |

## Version Targets

| Package | Constraint | Rationale |
|---------|------------|-----------|
| `laravel/framework` | `^13.0` | Current target (Laravel 13) |
| `laravel/sanctum` | `^4.0` | Laravel 13 compatible |
| `spatie/laravel-permission` | `^6.0` | Laravel 13 + PHP 8.3 compatible |
| `spatie/laravel-activitylog` | `^4.8` | Laravel 13 + PHP 8.3 compatible (**not v5**, requires PHP 8.4+) |
| `laravel/telescope` | `^5.0` | Laravel 13 compatible |
|| `dedoc/scramble` | `^0.13` | Laravel 13 compatible (latest stable; ^2.0 not yet released) |
| `spatie/laravel-backup` | `^10.0` | Laravel 13 compatible |
| AdminLTE | v4.x (release ZIP) | Vendored to `public/vendor/adminlte/`; NOT in `package.json`; NOT via npm |
| `predis/predis` | `^2.0` | Redis client (if Redis chosen) |

## Native vs Package Coverage

| Concern | Implementation |
|---------|----------------|
| Auth foundation | Sanctum (package) + native Auth |
| RBAC | Spatie Permission (package) + native Gate/Policy |
| Audit Trail | Spatie Activitylog (package) + Audit abstraction |
| Monitoring | Telescope (package) + native logging |
| API Docs | Scramble (package) |
| Queue | Native Laravel Queue (database default, Redis optional) |
| Cache | Native Laravel Cache (file default, Redis optional) |
| Rate Limiting | Native Laravel RateLimiter |
| Validation | Native Laravel Validation |
| Soft Deletes | Native Eloquent SoftDeletes |
| Transactions | Native DB transactions |
| Logging | Native Laravel Logging + Monolog |
| Everything else | Native Laravel (see [Intentional Non-Dependencies](./overview.md#intentional-non-dependencies)) |