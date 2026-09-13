# Dependency Rules

## Core Dependency Direction

```
Core/Application
      ↓
API / Web / Mobile clients
      ↓
Replaceable UI
```

- Lower layers must not depend on higher layers.
- Abstraction must not depend on details. Details must depend on abstraction.

## Package Dependencies

Prefer Laravel-native functionality when appropriate.

Use established packages for capabilities better provided by mature ecosystem solutions:
- RBAC → Spatie Permission
- Audit → established audit package
- Telescope → Laravel Telescope

Do NOT add packages simply because they exist.

## Package Governance

Every package must have documented:
- Purpose
- Reason for use
- Alternatives considered where relevant
- Security considerations
- Upgrade considerations
- Whether it is Base/Core or optional

## Infrastructure Abstraction

- Business logic must not depend directly on Redis. Use Laravel abstractions (Cache, Queue, Lock facades).
- Default queue: database. Remain Redis-compatible.
- Redis may later be used for: Queue, Cache, Rate limiting, Locks, Distributed coordination.
- The architecture must remain Redis-compatible but Redis is not mandatory.

## Secret Management

- Secrets belong in environment/deployment configuration.
- Never commit `.env`, passwords, API secrets, private keys, mail credentials, database credentials.

## Data Retention Dependencies

Retention policies are defined separately for each data type (audit, logs, sessions, tokens, etc.). Do not apply one global retention period.