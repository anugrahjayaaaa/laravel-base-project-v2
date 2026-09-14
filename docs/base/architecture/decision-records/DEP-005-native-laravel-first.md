# DEP-005: Native Laravel First

- **Status**: Accepted
- **Category**: Dependency Governance
- **Architecture Area**: All (governance principle)

## Context

Laravel is a batteries-included framework providing mature implementations of
authentication, validation, caching, queueing, rate limiting, notifications,
mail, encryption, hashing, logging, filesystem, HTTP client, soft deletes,
database transactions, encryption, and more. There is strong temptation to
introduce third-party packages for these capabilities.

## Decision

Use native Laravel functionality whenever it is sufficient. Do NOT introduce
a Composer package for any concern that Laravel's core provides well.

## Alternatives Considered

- **Per-concern packages**: A package exists for nearly every Laravel
  concern (validation, rate limiting, settings, notifications, etc.). Using
  them all would create a dependency-heavy project with significant upgrade
  friction.
- **Blanket "use packages for everything"**: Some projects adopt a
  package-first philosophy. This is rejected for the Base Project's
  maintainability goals.

## Why This Decision

- Laravel's core is maintained by the framework team, tested, and
  version-aligned.
- Native implementations avoid transitive dependencies, license risk, and
  upgrade friction.
- The framework's APIs are stable and documented.
- Adding packages for native capabilities violates YAGNI and the project's
  "packages should only be introduced when they provide substantial value"
  principle.

## Consequences

- [Intentional Non-Dependencies](../../dependencies/overview.md#intentional-non-dependencies)
  list explicitly documents every concern handled natively.
- Package authors must justify any new dependency against this principle
  (see [Dependency Governance](../../governance/dependency-governance.md)).
- Application code uses Laravel facades, traits, and core classes directly
  (e.g. `Illuminate\Support\Facades\Cache`, `Illuminate\Support\Facades\Queue`,
  `Illuminate\Database\Eloquent\SoftDeletes`, `Illuminate\Support\Facades\RateLimiter`).

## Security Implications

- Fewer dependencies = smaller attack surface.
- Native Laravel code is regularly audited by the framework team.
- Version-aligned upgrades reduce the window of vulnerability.

## Maintenance Implications

- Upgrade path follows Laravel framework versioning.
- No per-package upgrade coordination for native capabilities.

## Reversal / Replacement

- This is a governing principle, not a reversible decision. It applies to
  all future package selections.
