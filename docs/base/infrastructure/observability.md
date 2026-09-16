# Observability

## Monitoring Structure

The Monitoring area is conceptually structured as:

```
Monitoring
├── Audit Trail
├── Application Logs
├── Security Logs
├── Server Logs
├── Telescope
├── Periscope (Telescope companion UI)
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

## Telescope (Purpose: Laravel technical debugging)
 - Laravel Telescope — technical debugging.
 - Intended for technical users only (developers, DevOps, SRE, technical admins).
 - NOT a replacement for Audit Trail or Application Logs.
 - Do not merge Telescope with Audit Trail concerns.
 - Retention: 7 days (auto-purge)

## Periscope (Purpose: Telescope companion UI)
 - Browsing, filtering, and searching Telescope's existing data (requests,
   exceptions, queries, jobs, mail, notifications, cache, events, logs).
 - Does NOT replace Telescope — reads the same `telescope_entries` data.
 - Accessible at `/periscope`.
 - Inherits Telescope's authorization via `Telescope::check($request)`.
 - No separate auth, gate, role, or migration — reads Telescope's tables.
 - Excludes its own requests from Telescope watchers to avoid noise.
 - Retention: follows Telescope's 7-day auto-purge.

### System Health (Purpose: Operational status)
- Application health check endpoints.
- Database connectivity.
- Queue worker status.
- Disk space, memory.
- External service connectivity.

## Important Separation

- Telescope is the data collector and primary debugging dashboard.
- Periscope is a companion UI that reads Telescope's existing data.
- Audit Trail is for non-technical operational/security users.
- Security Logs are distinct from Application Logs — security events must
  be queryable independently for incident response.
- Application Logs are distinct from Server Logs — application-level events
  must be separable from infrastructure-level events.
- Telescope and Periscope must not be merged with Audit Trail concerns.

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

- ADR-008: Audit Trail vs Telescope separation
- ADR-013: Application Logging Strategy
- DEP-004: Telescope for Technical Observability (Telescope + Periscope)