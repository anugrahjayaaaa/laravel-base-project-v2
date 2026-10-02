# Observability

## Monitoring Structure

The Monitoring area is conceptually structured as:

```
Monitoring
├── Audit Trail
├── Application Logs
├── Security Logs
├── Server Logs
├── Laravel Pulse (runtime metrics dashboard)
└── System Health
```

## Components

### Audit Trail (Purpose: Business/security accountability)
- Intended for administrators, security users, operational users, non-technical users.
- Read-only through the UI.
- Metadata: actor, action, subject, before, after, metadata, IP, user agent, request/correlation ID, timestamp.
- Export via asynchronous job (see [audit-trail.md](../features/audit-trail.md))
- Source of truth: mutation caller (NOT observers).
- Answers: **Who did what to which resource, and was it committed?**
- Retention: indefinite (see [retention.md](../operations/retention.md))

### Application Logs (Purpose: Application/runtime problems)
- Application error logs, warnings, info-level events.
- Structured with correlation IDs.
- Answers: **What happened technically?** (failures, exceptions, warnings,
  unexpected conditions)
- Retention: 30 days (configurable)

### Security Logs (Purpose: Security-relevant events)
- Failed login attempts, account locks, rate-limit violations,
  authentication failures, authorization denials, suspicious activity.
- Structured with correlation IDs, user identifiers, IP addresses.
- Answers: **What security-relevant events occurred?**
- Retention: 90 days (longer than application logs for investigation)
- Separate from Application Logs — security events must be queryable
  independently for incident response.

### Server Logs (Purpose: Infrastructure/server problems)
- Nginx/Apache access logs, PHP-FPM logs, system logs.
- Managed by deployment infrastructure (not application code).
- Answers: **What happened at the infrastructure level?**
- Retention: configured by infra team (separate policy)

## Laravel Pulse (Purpose: runtime metrics dashboard)
 - First-party metrics dashboard at `/pulse`: queue depth, cache behaviour,
   exception rate, slow requests, scheduler history.
 - Records aggregates, not per-request payloads.
 - Intended for technical users only (developers, DevOps, SRE, technical admins).
 - NOT a replacement for Audit Trail or Application Logs.
 - Do not merge Pulse with Audit Trail concerns.
 - Replaced `laravel/telescope` + `periscope/periscope` (commit `85384b4`).
 - Access requires the `pulse` feature flag AND the `pulse.view` permission.
   See [monitoring.md](../features/monitoring.md) § Access — two independent gates.
 - Retention: Pulse's own retention configuration.
 - Not a request-level debugger. Slow-query diagnosis goes through the MySQL slow
   log and `EXPLAIN`, not Pulse — see
   [implementation-roadmap.md](../../planning/implementation-roadmap.md).

### System Health (Purpose: Operational status)
- Application health check endpoints.
- Database connectivity.
- Queue worker status.
- Disk space, memory.
- External service connectivity.

## Important Separation

- Pulse is the runtime metrics dashboard, gated by flag + permission.
- Audit Trail is for non-technical operational/security users.
- Security Logs are distinct from Application Logs — security events must
  be queryable independently for incident response.
- Application Logs are distinct from Server Logs — application-level events
  must be separable from infrastructure-level events.
- Pulse must not be merged with Audit Trail concerns.

## Correlation ID

Every request should have a traceable request identifier, available to:

- Application logs
- Audit metadata
- Security logs
- Exception handling
- Relevant queue context

See [logging.md](logging.md) §Request / Correlation ID for the full
propagation strategy.

## ADR References

- ADR-008: Audit Trail vs Technical Observability separation
- ADR-013: Application Logging Strategy
- DEP-004: Laravel Pulse for Technical Observability