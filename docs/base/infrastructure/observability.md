# Observability

## Monitoring Structure

The Monitoring area is conceptually structured as:

```
Monitoring
├── Audit Trail
├── Application Logs
├── Server Logs
├── Telescope
└── System Health
```

## Components

### Audit Trail (Purpose: Business/security accountability)
- Intended for administrators, security users, operational users, non-technical users.
- Read-only through the UI.
- Metadata: actor, action, subject, before, after, metadata, IP, user agent, request/correlation ID, timestamp.
- Export via asynchronous job (see [audit-trail.md](../features/audit-trail.md))
- Source of truth: mutation caller (NOT observers).

### Application Logs (Purpose: Application/runtime problems)
- Application error logs, warnings, info-level events.
- Structured with correlation IDs.
- Retention configurable per environment.

### Server Logs (Purpose: Infrastructure/server problems)
- Nginx/Apache access logs, PHP-FPM logs, system logs.
- Managed by deployment infrastructure (not application code).

### Telescope (Purpose: Laravel technical debugging)
- Laravel Telescope — technical debugging.
- Intended for technical users only.
- NOT a replacement for Audit Trail.
- Do not merge Telescope with Audit Trail concerns.

### System Health (Purpose: Operational status)
- Application health check endpoints.
- Database connectivity.
- Queue worker status.
- Disk space, memory.
- External service connectivity.

## Important Separation

- Audit Trail ≠ Telescope
- Audit Trail is for non-technical operational/security users.
- Telescope/technical monitoring is for technical users.
- Do not merge these concerns.

## Correlation ID

Every request should have a traceable request identifier, available to:
- Application logs
- Audit metadata
- Exception handling
- Relevant queue context

See also: [Correlation/Request ID](#correlation-id) concept from section #23 of the architecture spec.

## ADR References

- ADR-008: Audit Trail vs Telescope separation