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
├── Queue Monitor        (Vantage: live backlog, job payloads, retry chain)
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
| Queue Monitor (Vantage) | Live queue backlog, individual job rows and their retry chain | Technical users (read-only; retry is a separate permission) | `vantage.retention_days`, swept daily by `vantage:prune` |
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

**The gate is an override, and it reads the flag too.** Pulse defines `viewPulse`
itself as `environment('local')` at
`vendor/laravel/pulse/src/PulseServiceProvider.php:100`. `AuthServiceProvider`
redefines it as `FeatureCatalog::isActive('pulse') && $user->can('pulse.view')`,
which wins because `Gate::define()` overwrites by name and our provider boots
after Pulse's. Do not call `Pulse::auth()` — it does not exist in 1.8.

`Gate::before` is untouched, so superadmin keeps access without holding a
permission row, and `admin` inherits the permission via
`PermissionCatalog::all()` in `PermissionSeeder`. The flag check sits inside the
gate callback, so the bypass is unaffected by it — superadmin is stopped by the
middleware below, not by the gate.

**The flag is not a role.** `Gate::before` answers the *permission* question;
`EnsureFeatureIsEnabled` consults no actor at all
(`app/Http/Middleware/EnsureFeatureIsEnabled.php:35`), so a flag-off `/pulse`
403s superadmin exactly as it does a permission holder. That is what makes it a
kill switch. Pinned by `test_the_flag_off_refuses_superadmin_too`
(Phase 11 D-3, closed 2026-10-16).

**The flag stops the writes, not just the page** (ADR-024). `Pulse::filter(fn ()
=> FeatureCatalog::isActive('pulse'))` in `AppServiceProvider::boot()` is
consulted at INGEST, so with the flag off nothing is recorded — in the worker as
well as in a request — and the filter costs a store read only when there is
something to write. Switching a flag off through `/features` also revokes its
permission from every role (`pulse.view`); see
[feature-flags.md](feature-flags.md#a-permission-follows-its-modules-flag).
`PULSE_ENABLED` is unchanged and stays the deploy-time master switch: with it
off, Pulse registers no recorders at all and this filter is never asked.

### Pulse upgrade notes

Two things this project layers on top of the package; both cost maintenance on
upgrade.

- **Keep `PULSE_CACHE_DRIVER` on `array`.** `RemembersQueries` caches Collection
  payloads, and `cache.serializable_classes => false` (phase-1 hardening) turns
  every object read back from a serializing store into `__PHP_Incomplete_Class`.
  Setting redis/file/database per environment reintroduces the crash —
  `PulseQueryCacheTest` reproduces it and fails at `Queues.php:35`. The cost of
  `array` is per-process state: the Servers heartbeat throttle and `pulse:check`
  signals do not cross processes.
- **Two vendor views are overridden for the CSP nonce** —
  `resources/views/vendor/pulse/components/pulse.blade.php` and
  `theme-switcher.blade.php`. A Pulse upgrade can drift them;
  `ContentSecurityPolicyTest` catches nonce regressions and nothing else. Re-diff
  both against `vendor/laravel/pulse` after any upgrade touching Pulse.

### Toggling Pulse off

```bash
# flip the flag via the UI at /features, or per-environment in code
php artisan config:clear && php artisan config:cache
```

For the flag to be honoured, config must be re-cached. On the VM also reload
PHP-FPM, or opcache keeps serving the old config.

## Slow-Query Diagnosis

Pulse is deliberately **absent** from the slow-query diagnosis ladder. The full
ladder — `/up` → `top`/`free` → MySQL slow log → `DB::listen()` → `EXPLAIN` —
lives in
[phase-11-monitoring-observability.md](../../planning/phase-11-monitoring-observability.md)
§7 (Operational runbooks).

## Health Check Endpoint

`GET /api/v1/health`:

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

Status: **shipped** (FOUND-010 — `Api\V1\HealthCheck\HealthCheckController`,
`HealthCheckService`, `HealthCheckResource`). It stays **ungated and
unauthenticated** because its caller is an uptime monitor that cannot log in;
the response shape above is the ceiling on what it may ever expose
(phase-11-monitoring-observability.md F3).

Two things bound what one request costs (ADR-023, amended by ADR-024):

- **A cached check set for 10 seconds** in `health_check:results`. `check()`
  reads the configured store first, then the `file` store, and only then runs the
  probes; the write is best effort to both. It reads *before* it computes because
  `Cache::remember()` runs the callback before attempting the store — so a store
  that cannot be written ran all seven round trips on every request, i.e. the
  ceiling disappeared in exactly the situation that makes the endpoint matter.
  When neither store can answer it logs `health_check.results_cache.failed` and
  runs the checks per request; that residual is pinned by
  `HealthCheckCacheFallbackTest`.
- **`throttle:health`** — `RateLimiter::for('health')`, keyed on the IP,
  `health_rate_limit_per_minute` (default 120, validated `1..600`), refusing in
  JSON regardless of the caller's `Accept` header.

The fallback store is the local disk, so the ceiling is per server: the same
status answer ten seconds apart on one host, which is all this endpoint
promises.

## System Health Dashboard (web)

`GET /health` — the read-only admin page over the **same** `HealthCheckService`
(Phase 11 A2/C1):

| Piece | Where |
|---|---|
| View | `resources/views/pages/health/index.blade.php` — page-level badge + one card per check (database, cache, queue, storage), no query from Blade |
| Controller | `App\Http\Controllers\Web\V1\HealthController@index` — passes `$checks` + `$isHealthy()`; no FormRequest, no audit (a read writes nothing) |
| Route | `GET /health` → `health.index` |

### Access — two independent gates

`/health` requires **both**, same deliberate shape as `/pulse`:

| Gate | Kind | Enforced in |
|---|---|---|
| `health` feature flag | deploy-time kill switch | route middleware `feature:health` (`EnsureFeatureIsEnabled`) |
| `health.view` permission | per-role | route middleware `can:health.view` |

Both failures return 403, so a caller cannot tell which fired — consistent with
Phase 7. The sidebar entry (`System Health`, `fas fa-stethoscope`) is filtered in
`AppMenuComposer` on the same flag + permission pair, so the menu cannot
advertise a screen the route refuses. Superadmin reaches it through
`Gate::before` without holding the row; `admin` inherits the permission via
`PermissionCatalog::all()`.

**A declared flag is not an active flag.** The Pennant `database` store is
fail-closed, so `/health` 403s for everyone — superadmin included — until
`FeatureFlagSeeder` writes the `health` row. `pulse` had a row from Phase 7, but
an environment seeded before Phase 11 needs
`php artisan db:seed --class=FeatureFlagSeeder` once.

The API endpoint above and this dashboard answer the same service checks at two
authorization levels: machine-to-machine without a login, and inside the admin
with the full treatment. The page calls the same `check()`, so it inherits the
same ten-second set and the same fallback store.

## Queue Monitor (Vantage, web)

`storviaio/vantage ^0.1.25` at `/vantage` (Phase 11 MONITOR-003, ADR-022). It
closes the two gaps Pulse's queues card leaves: Pulse shows a throughput graph,
not a live backlog count, and `failed_jobs` keeps one row per job, so the retry
chain that explains the *first* failure is lost. Vantage keeps every attempt.

| Piece | Where |
|---|---|
| Routes | `routes/web.php` — the six `vantage.*` web routes, declared by the app (not the package) |
| Config | `config/vantage.php` — `routes => false`, `store_payload => env('VANTAGE_STORE_PAYLOAD', false)`, `enabled => env('VANTAGE_ENABLED', true)` |
| Sidebar | `AppMenuComposer` — `Queue Monitor`, Monitoring group, `queues.view` + `feature:vantage` |

### Access — two independent gates

Same deliberate shape as `/pulse` and `/health`:

| Gate | Kind | Enforced in |
|---|---|---|
| `vantage` feature flag | deploy-time kill switch | route middleware `feature:vantage` (`EnsureFeatureIsEnabled`) |
| `queues.view` / `queues.retry` | per-role | route middleware `can:queues.*` — `view` on all reads, `retry` on the one POST |

Both failures return 403, so a caller cannot tell which fired. Reading the queue
and re-queueing a job are different acts, on the `audit.view` / `audit.export`
rule: `queues.retry` guards only `POST /vantage/jobs/{job}/retry`.

**The app declares the routes, not the package.** The vendor provider calls
`loadRoutesFrom()` bare, so its routes carry no `feature:`/`can:` middleware and
the group cannot be given any from config. `config/vantage.php` sets
`routes => false` and `routes/web.php` re-declares the six routes under the
admin middleware group. Route names stay the vendor's (`vantage.*`) because its
Blade views build every link from them.

**The `viewVantage` gate is overridden** in `AuthServiceProvider` to
`FeatureCatalog::isActive('vantage') && $user->can('queues.view')`. The package
default admits *any authenticated user*; our routes use `can:` and never consult
it, so the override is the safety net for the package's own `AuthorizeVantage`
middleware if vendor routes were re-enabled — with the flag ANDed in, so the
switch answers there too (ADR-024).

**The flag stops the recording, not only the page** (ADR-024). Vantage offers no
`filter()` hook: its recorders re-read `config('vantage.enabled')` inside
`handle()`, so that config key *is* the call-time switch.
`Event::listen(Looping::class, ...)` in `AppServiceProvider::boot()` refreshes it
from the flag once per daemon iteration, before the job is fetched. Not
`JobProcessing`: the package listens on that event and registered first (packages
boot before app providers), so an app listener there is always one job behind.
`VANTAGE_ENABLED` stays the deploy-time master switch, and it is the only switch
a worker reaching the queue through `queue:listen` or a synchronous connection
ever consults — those never fire `Looping`. `bin/run-workers.sh` runs
`queue:work`, which is the shape this covers; a worker started before a flip
applies it from its next loop.

**Retry has a ceiling** (ADR-024). `POST /vantage/jobs/{id}/retry` carries
`throttle:queue-retry`: `RateLimiter::for('queue-retry')`, keyed on user id + IP,
reading `queue_retry_rate_limit_per_minute` (default 10, validated `1..120`). Its
over-limit response is a redirect with a session `error` flash, not a field
error, because the vendor layout renders `session('error')` and has no `$errors`
bag — the standard shape would reload the page saying nothing.

**`store_payload => false`.** The package default (`true`) persists full job
payloads; redaction covers named keys, not the export filters, model ids and mail
arguments this project's jobs carry. Set `VANTAGE_STORE_PAYLOAD=true` only where
that exposure is understood.

**Retry reads the framework's payload, not a Vantage one.** With
`store_payload => false` the vendor's retry had nothing to restore, so
`POST /vantage/jobs/{id}/retry` shipped and could never succeed.
`JobRestorer` is bound to `App\Support\Vantage\FailedJobPayloadRestorer`
(`AppServiceProvider::register()`), which falls back to the `failed_jobs` record
`queue:retry` reads — matched on the payload uuid Vantage already stores — and
then runs the package's own restore path, security checks included. Two
consequences worth knowing before reading a refusal: `queue:flush` empties that
store while `vantage_jobs` lives on, so those rows refuse the retry (logged as
`Queue Monitor: no failed-job record for this retry`), and a job class that is a
queued notification or mailable was never restorable either way — the vendor
validates `job_class` (`SendQueuedNotifications`), while the payload holds the
Notification itself.

**Checking it by hand.** `php artisan queue:probe-failure` dispatches
`App\Jobs\QueueFailureProbe`, which always throws on its first attempt, so a
worker produces both rows these screens read: `failed_jobs` (the payload the
retry restores from) and `vantage_jobs` (`status = failed`). A worker has to be
running on `sweeps` — `bin/run-workers.sh local`; `composer dev`'s listener takes
no `--queue` and answers `default` only. Pressing Retry then queues another
attempt, which fails the same way: the new row is the proof, and it links back
through `retried_from_id`.

`vantage:prune --force --keep-processing` runs daily on the scheduler (name
`vantage-retention`, `withoutOverlapping`) behind `config('vantage.enabled')`.
`vantage_jobs` is otherwise unswept.

**A declared flag is not an active flag** — the Pennant store is fail-closed, so
`/vantage` 403s for everyone until `FeatureFlagSeeder` writes the `vantage` row.

**Content Security Policy.** The vendor layouts load Tailwind's Play CDN and
Chart.js, and inline scripts and `onclick`/`onchange` handlers the nonce-only
policy refuses. `AddSecurityHeaders::policy()` now scopes two extra `script-src`
origins (`cdn.tailwindcss.com`, `cdn.jsdelivr.net`) and `'unsafe-eval'` to
`vantage.*` — the same route-scoped shape `pulse` uses, for the same reason (the
Play CDN compiles classes with `new Function`). The four vendor views that carry an
inline script are overridden under `resources/views/vendor/vantage/` to add
`@nonce`, and their inline handlers are re-bound by id inside the nonced script.
No `'unsafe-inline'` anywhere; `ContentSecurityPolicyTest` pins all of it. Re-diff
those four views against `vendor/storviaio/vantage` after every upgrade — the drift
cost is the same as Pulse's two view overrides. The retry override is the fourth
project-side dependency on the vendor's internals; an upgrade that changes
`JobRestorer::restore()`'s signature breaks `FailedJobPayloadRestorer`, which
`VantageRetryTest` reports.

## ADR References

- ADR-013: Application Logging Strategy
- ADR-008: Audit Trail vs Technical Observability separation
- DEP-004: Laravel Pulse for Technical Observability
- ADR-022: Vantage queue monitor, gated like every other module
- ADR-023: the ungated health endpoint gets a ceiling and a cached check set
- ADR-024: the flag reaches the writes, and the permission follows the flag
