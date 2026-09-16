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
├── Telescope            (Laravel technical debugging)
├── Periscope            (Telescope companion UI — browsing/filtering/search)
└── System Health        (Operational status)
```

## Component Definitions

| Concept | Purpose | Audience | Retention |
|--------|---------|----------|-----------|
| Audit Trail | Who did what to which resource | Administrators, security/operational users, non-technical users | See [retention.md](../operations/retention.md) |
| Application Logs | Application/runtime problems, warnings, failures | Developers, SREs | Configurable |
| Security Logs | Security-relevant events (failed login, account lock, reset activity, violations) | Security team, developers | Typically shorter than audit (see retention.md) |
| Server Logs | Infrastructure problems (web server, PHP-FPM, OS, reverse proxy) | SREs, platform | Managed by deployment infrastructure |
|| Telescope | Laravel technical debugging and runtime behavior | Technical users (read-only / debug access) | Short-term |
|| Periscope | Companion UI for browsing/filtering/searching Telescope entries | Technical users (read-only / debug access) | Short-term (follows Telescope) |
|| System Health | Operational readiness checks | Monitoring tools, SREs | Live/operational |

## Separation of Concerns

| Aspect | Audit Trail | Application Logs | Security Logs | Telescope |
|--------|-------------|------------------|---------------|-----------|
| Audience | Non-technical users | Developers | Security team | Technical users |
| Purpose | Accountability | Debugging | Security monitoring | Debugging |
| Content | Business mutations | Technical warnings/errors | Auth events, violations | Runtime details |

See [observability.md](../infrastructure/observability.md) for the full
classification.

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

## Periscope (Purpose: Telescope companion UI)

|- Companion UI for browsing, filtering, and searching Telescope's existing
  entries (requests, exceptions, queries, jobs, mail, notifications, cache,
  events, logs).
|- Does **NOT** replace Telescope — reads the same `telescope_entries` data.
|- Accessible at `/periscope`.
|- Inherits Telescope's authorization (Telescope::check()).
|- No separate auth, gate, role, or migration.
|- Excludes its own requests from Telescope watchers to avoid noise.
|- Retention: 7 days (auto-purge, same as Telescope).
|- Not for non-technical users — those use the Audit Trail.

## ADR References

- ADR-013: Application Logging Strategy
- ADR-008: Audit Trail vs Technical Observability separation
- DEP-004: Telescope for Technical Observability (Telescope + Periscope)