# DEP-004: Telescope for Technical Observability

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Monitoring (Phase 11 `MONITOR-001`, Phase 1 `FOUND-007`)

## Context

The Base Project needs a technical observability layer for developers and
technical administrators to debug Laravel runtime behavior (queries, jobs,
exceptions, requests, cache, events, mail, notifications, logs). This is
distinct from the Audit Trail (business/security accountability for
non-technical users).

## Decision

Use `laravel/telescope` for technical observability, gated to technical users
only and disabled in production.

## Alternatives Considered

- **Sentry**: Excellent for error tracking and distributed tracing, but it is
  an external/production observability platform (deployment-specific), not a
  local development debugging tool. Telescope and Sentry are complementary —
  Telescope for dev/local introspection, Sentry for production error
  tracking.
- **Custom logging + debug bar**: A debug bar provides limited request-level
  introspection. Telescope provides deep multi-facet inspection (queries,
  jobs, cache hits, exceptions, mail, notifications).
- **OpenTelemetry / OTel**: Production-grade observability. Complementary to
  Telescope, not a replacement for dev-time introspection.
- **Laravel Pulse**: Metrics dashboard for queues, caches, exceptions — a
  production monitoring tool, not a request-level debugger.

## Why This Decision

Telescope is Laravel's first-party technical debugging tool. It provides:
- Request/exception/query/job/command/mail/notification/cache/event/log
  introspection with zero custom instrumentation
- A local UI (no external infrastructure needed)
- Deep Laravel integration (captures framework internals)

## Consequences

- Telescope is **disabled in production** by default (`TELLESCOPE_ENABLED=false`).
- Access is gated via `TelescopeServiceProvider::gate()` — only technical
  users (developers, super-admin technical role) may access the UI.
- Telescope data retention: 7 days (`retention.telescope.days`), auto-purge.
- Application code must NEVER depend on Telescope — it is a passive
  observer only.
- Telescope is NOT a replacement for the Audit Trail or Application Logs.

## Security Implications

- Telescope must be disabled in production or gated behind IP allowlist +
  authentication.
- Telescope records full SQL queries and request payloads — if enabled in
  production, it may expose sensitive data.
- Telescope route access must be restricted via a Gate in
  `TelescopeServiceProvider`.

## Maintenance Implications

- Telescope version must track the Laravel framework version.
- Telescope migrations create dedicated tables — review before major upgrades.
- In test environment, Telescope should be disabled for performance.

## Reversal / Replacement

- Telescope is a technical tool with no runtime coupling to application code.
- To remove: uninstall the package, remove `TelescopeServiceProvider`
  registration. Application code is unaffected.
- Production monitoring may use Sentry/OpenTelemetry independently.
