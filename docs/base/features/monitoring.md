# Monitoring

## Overview

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

### Audit Trail
- Purpose: Business/security accountability
- Audience: Administrators, security/operational users, non-technical users
- Features: View, detail, search, filter, pagination, asynchronous export
- See: [audit-trail.md](./audit-trail.md)

### Application Logs
- Purpose: Application/runtime problems
- Structured with request IDs and context
- Retention: configurable

### Server Logs
- Purpose: Infrastructure/server problems
- Managed by deployment infrastructure
- Includes: web server, PHP-FPM, system logs

### Telescope
- Purpose: Laravel technical debugging
- Audience: Technical users only
- NOT a replacement for Audit Trail
- Do not merge Telescope with Audit Trail concerns

### System Health
- Purpose: Operational status
- Checks: DB connectivity, queue workers, disk space, memory, external services
- Health check endpoint for monitoring tools

## Separation of Concerns

| Aspect | Audit Trail | Telescope |
|--------|-------------|-----------|
| Audience | Non-technical users | Technical users |
| Purpose | Accountability | Debugging |
| Content | Business events | Technical details |
| Access | Admin/Security UI | Technical UI |

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

## ADR References

- ADR-008: Audit Trail vs Telescope separation