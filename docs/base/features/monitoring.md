# Monitoring

## Overview

The Monitoring/Observability area is conceptually structured as five distinct
concerns:

```
Observability
├── Audit Trail          (Business/security accountability)
├── Application Logs     (Technical application behavior/warnings/errors)
├── Security Logs        (Security-relevant events: failed login, lock, etc.)
├── Server Logs          (Infrastructure/server problems)
├── Laravel Pulse        (Runtime metrics dashboard: queue, cache, exceptions)
└── System Health        (Operational status)
```

## Component Definitions

| Concept | Purpose | Audience | Retention |
|--------|---------|----------|-----------|
| Audit Trail | Who did what to which resource | Administrators, security/operational users, non-technical users | See [retention.md](../operations/retention.md) |
| Application Logs | Application/runtime problems, warnings, failures | Developers, SREs | Configurable |
| Security Logs | Security-relevant events (failed login, account lock, reset activity, violations) | Security team, developers | Typically shorter than audit (see retention.md) |
| Server Logs | Infrastructure problems (web server, PHP-FPM, OS, reverse proxy) | SREs, platform | Managed by deployment infrastructure |
| Laravel Pulse | Runtime metrics: queue depth, cache behaviour, exception rate, slow requests | Technical users (read-only) | Pulse's own retention config |
| System Health | Operational readiness checks | Monitoring tools, SREs | Live/operational |

## Separation of Concerns

| Aspect | Audit Trail | Application Logs | Security Logs | Laravel Pulse |
|--------|-------------|------------------|---------------|---------------|
| Audience | Non-technical users | Developers | Security team | Technical users |
| Purpose | Accountability | Debugging | Security monitoring | Runtime health |
| Content | Business mutations | Technical warnings/errors | Auth events, violations | Aggregate metrics |

See [observability.md](../infrastructure/observability.md) for the full
classification.

## Laravel Pulse (Purpose: runtime metrics dashboard)

- First-party metrics dashboard at `/pulse`.
- Records aggregates (queue depth, cache hit rate, exception rate, slow
  requests), **not** per-request payloads.
- Ingestion runs on a schedule into the `pulse_*` tables; bounded by Pulse's own
  retention configuration.
- Not a request-level debugger. See [DEP-004](../architecture/decision-records/DEP-004-laravel-pulse-observability.md)
  for what that trade costs and what covers the gap.
- Ignores its own routes, so monitoring the monitor is not a feedback loop.
- Replaced `laravel/telescope` + `periscope/periscope` (commit `85384b4`).

### Access — two independent gates

`/pulse` requires **both**:

| Gate | Kind | Enforced in |
|---|---|---|
| `pulse` feature flag | deploy-time kill switch | `config/pulse.php` — `feature:pulse` in the `pulse` middleware group |
| `pulse.view` permission | per-role | `viewPulse` gate defined in `App\Providers\AuthServiceProvider` |

Both failures return 403, so a caller cannot tell which one fired. That is
deliberate and consistent with the Phase 7 decision (see
[phase-7-feature-flags.md](../../planning/phase-7-feature-flags.md)).

**The gate is an override.** Pulse defines `viewPulse` itself as
`environment('local')` at
`vendor/laravel/pulse/src/PulseServiceProvider.php:100`. `AuthServiceProvider`
redefines it as `$user->can('pulse.view')`, which wins because `Gate::define()`
overwrites by name and our provider boots after Pulse's. Do not call
`Pulse::auth()` — it does not exist in 1.8.

`Gate::before` is untouched, so superadmin keeps access without holding a
permission row, and `admin` inherits the permission via
`PermissionCatalog::all()` in `PermissionSeeder`.

### Toggling Pulse off

```bash
# flip the flag via the UI at /features, or per-environment in code
php artisan config:clear && php artisan config:cache
```

For the flag to be honoured, config must be re-cached. On the VM also reload
PHP-FPM, or opcache keeps serving the old config.

## Slow-Query Diagnosis

Pulse is deliberately **absent** from the slow-query diagnosis ladder. See
[implementation-roadmap.md](../../planning/implementation-roadmap.md) for the
full ladder: `/up` → `top`/`free` → MySQL slow log → `DB::listen()` →
`EXPLAIN`.

## Health Check Endpoint

`/api/v1/health`:

```json
{
  "status": "ok",
  "timestamp": "2024-01-01T00:00:00Z",
  "checks": {
    "database": "ok",
    "queue": "ok",
    "cache": "ok",
    "storage": "ok"
  }
}
```

Status: **PLANNED** (Phase 11, MONITOR scope). Documented here so the contract is
agreed; not yet implemented.

## ADR References

- ADR-013: Application Logging Strategy
- ADR-008: Audit Trail vs Technical Observability separation
- DEP-004: Laravel Pulse for Technical Observability
