# DEP-004: Laravel Pulse for Technical Observability

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Monitoring (Phase 11 `MONITOR-001`)
- **Date**: 2026-10-02

## Context

An earlier draft of this record selected `laravel/telescope` for technical
observability, with `periscope/periscope` as a companion UI for browsing and
filtering its entries. That record was withdrawn before the number was reused.

In use, the pairing had problems that the design did not anticipate:

1. **Write load during incidents.** Every request writes to `telescope_entries`.
   When the server is already slow, the debugger makes it slower — the worst
   possible time to add load.
2. **Unbounded growth.** Nothing in `config/telescope.php` prunes the table
   automatically; cleanup was a manual `telescope:prune` after every debugging
   session.
3. **Two packages to maintain.** Periscope hard-requires Telescope, so moving
   Telescope to `require-dev` alone did not remove it from a `--no-dev` install.
   The indirection bought readability, not capability.
4. **Awkward prod gating.** A disabled-by-default flag on a vendor route group
   is workable but fiddly, because the vendor owns the route registration.

What the project actually needed was **production** observability — queue depth,
cache behaviour, exception rate, slow requests — not a request-level debugger.

## Decision

Use `laravel/pulse: ^1.8` (first-party, in `require`) for technical
observability. It is gated by a `pulse` feature flag **and** a `pulse.view`
permission, both required.

`laravel/telescope` and `periscope/periscope` are removed.

## Why Pulse

| Need | Pulse |
|---|---|
| Production metric dashboard | Built for it — queue, cache, exceptions, slow requests, schedulers |
| Continuous recording | Ingestion is designed for always-on; no manual prune step |
| Write cost | Deliberately small — it records aggregates, not every request payload |
| Storage | Own `pulse_*` tables, ingested in batches on a schedule |
| Request-level debugging | **Not its job** — that was Telescope's, and the MySQL slow log + `EXPLAIN` cover the query-diagnosis case that actually matters here |

Pulse is a metrics dashboard, not a request debugger. That is the trade
deliberately accepted: the debugging capability lost is real, but the slow-query
diagnosis ladder (MySQL slow log → `DB::listen()` → `EXPLAIN`) does not depend on
it, and it is the reliable path during an incident precisely because it adds no
write load.

## Alternatives Considered

- **Keep Telescope + Periscope**: rejected per the Context section — write load
  during incidents, manual pruning, two packages for one capability.
- **Telescope alone (drop Periscope)**: removes the dependency-chain problem, but
  keeps write load, unbounded growth, and no production metrics.
- **Sentry**: excellent production error tracking, but an external service and a
  deployment-specific integration. Not a self-hosted dashboard in the admin.
- **OpenTelemetry / OTel**: production-grade and complementary. Heavier than a
  Laravel base project needs at this stage.
- **Custom logging + debug bar**: insufficient multi-facet introspection, and
  more application code to own.

## Consequences

- `laravel/pulse` lives in `require` — this is production observability, not a
  dev-only tool. (Contrast DEP-006's queue driver, which is also in `require`
  because production runs it.)
- `/pulse` requires **both** the `pulse` feature flag and the `pulse.view`
  permission. Two independent gates: the flag is the deploy-time kill switch,
  the permission is per-role. See [Phase 11](../../../planning/phase-11-monitoring-observability.md).
- Pulse's default `viewPulse` gate (`environment('local')`) is **overridden** in
  `AuthServiceProvider`. The permission replaces the environment check;
  local developers hold the permission like any other role.
- `Gate::before` is untouched, so superadmin retains access without holding a
  permission row — the invariant `PermissionSeeder` depends on.
- Application code must NEVER depend on Pulse. It is a passive observer.
- Pulse is NOT a replacement for the Audit Trail or Application Logs.
- Request-level introspection is gone. If a future incident needs it, the
  options are a temporary `require-dev` Telescope install or Sentry — not a
  permanent return.

## Security Implications

- Pulse's dashboard exposes queue depth, failed jobs, exception traces, cache
  statistics and slow-request detail. Both gates must remain; `pulse.view` must
  not be granted to a non-technical role.
- Pulse ignores its own routes (`config/pulse.php`), so monitoring the monitor
  is not a feedback loop.
- The `pulse_*` tables are listed in the sensitive-data scrubber
  (`config/pulse.php:216`).
- Recording is bounded by Pulse's own retention configuration.

## Maintenance Implications

- Pulse is first-party and tracks the Laravel version — no third-party version
  lockstep problem, which Periscope imposed.
- `laravel/pulse` publishes migrations (`pulse_tables`); review them before a
  major framework upgrade.
- Disable Pulse in tests for speed — recording is pure overhead there.

## Reversal / Replacement

- Pulse has no runtime coupling to application code, so removing it is
  `composer remove laravel/pulse`, delete `config/pulse.php`, drop the
  `pulse.view` permission const, the gate override, the nav entry, and the
  `pulse` flag declaration.
- Production error tracking may use Sentry independently.

## Migration Record

Commit `85384b4` (2026-10-01) installed Pulse and removed both packages.
Commit `b08b8b4` gated `/pulse` on the `pulse` feature flag. The permission half
landed separately — see [Phase 11](../../../planning/phase-11-monitoring-observability.md).
