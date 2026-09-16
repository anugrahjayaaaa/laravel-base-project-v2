# Dependency Governance

> Rules for selecting, evaluating, and introducing Composer dependencies into
> the Base Project. Every package addition must pass this checklist.

## Package Selection Rules

Before adding a new package, evaluate all of the following:

1. **Does Laravel already provide the capability?** If yes, do not add a
   package. Use the native Laravel implementation. Document the decision in
   this file's [Intentional Non-Dependencies](../dependencies/overview.md#intentional-non-dependencies)
   list.
2. **Is the functionality complex enough to justify a dependency?** A feature
   that can be implemented in ~50 lines of well-tested code should not pull in
   a package — avoid dependency inflation.
3. **Is the package actively maintained?** Check commit frequency, issue
   response time, and release cadence on the package's repository. Abandoned
   packages become security liabilities.
4. **Is it compatible with the project's Laravel/PHP versions?** The Base
   Project targets Laravel 13 + PHP 8.3. Verify the package's compatibility
   matrix before selecting.
5. **Is it secure?** Review the package's security history, audit reports,
   and whether it logs or stores sensitive data.
6. **Is its license acceptable for the project?** The Base Project uses an
   acceptable open-source license. Do not introduce GPL-incompatible or
   unknown-license packages.
7. **Does it introduce unnecessary transitive dependencies?** Each transitive
   dependency is a future upgrade/security risk. Minimize the dependency tree.
8. **Does it conflict with the established architecture?** The package must
   integrate through the appropriate layer (facade, abstraction, or
   designated boundary). It must not bypass established architecture.
9. **Can it be replaced reasonably?** The package should integrate behind an
   application-level abstraction so it can be swapped if necessary.
10. **Does it provide sufficient value over a native implementation?** Only
    introduce a package when reimplementing would be substantially more
    costly or error-prone than adopting it.

## Forbidden Behavior

AI agents and developers must **NOT**:

- Install packages merely for convenience (e.g., a package that generates a
  few lines of boilerplate).
- Duplicate Laravel functionality with third-party packages (e.g., a
  validation package when Laravel's `Validator` exists).
- Add packages without documenting the decision in this dependency
  documentation and the changelog.
- Introduce packages that bypass established architecture (e.g., a package
  that reaches directly into the database, bypassing models).
- Couple business logic directly to infrastructure packages. All package
  calls must route through the appropriate application abstraction or Laravel
  facade.
- Introduce packages for concerns already covered by the [Intentional
  Non-Dependencies](../dependencies/overview.md#intentional-non-dependencies) list without
  an explicit architecture-approved exception.
- Modify `composer.json` or run `composer require` without approval.

## Package Introduction Workflow

1. Add the package to the [dependency
   overview](../dependencies/overview.md) with full documentation (purpose, why, security,
   maintenance, testing, abstraction, replacement).
2. Add an ADR in [decision-records](../architecture/decision-records/) if
   the selection is a significant architectural decision.
3. Update the [dependency matrix](../dependencies/dependency-matrix.md).
4. Update the changelog (`docs/planning/changelog.md`).
5. Update the [Implementation Roadmap](../../planning/implementation-roadmap.md)
   if the package introduces a new phase dependency.
6. Only then may the package be installed (Phase 1 scaffolding).

## Abstraction Requirement

Packages that provide business-impacting functionality must integrate behind
an application-level abstraction:

| Package | Required Abstraction | Rationale |
|---------|---------------------|-----------|
| `laravel/sanctum` | Auth/Session Service (`app/Services/Auth/`) | Session lifecycle policy lives in application layer |
| `spatie/laravel-permission` | Native Gate/Policy + User model traits | Authorization resolved through Laravel's native Gate |
| `spatie/laravel-activitylog` | `Audit` service class | Audit policy, transaction boundaries, metadata enrichment |
| `laravel/telescope` | None (technical tool only) | Application code never calls Telescope directly |
| `laravel/pennant` | None (Feature facade is the API) | Lightweight feature flag layer for runtime feature availability |
| `seanbarton/laravel-periscope` | None (technical tool only) | Companion UI for Telescope; application code never calls Periscope directly |
| `dedoc/scramble` | None (dev-only) | Documentation generation, not runtime |
| `spatie/laravel-backup` | None (scheduled jobs) | Infrastructure concern, scheduled via Kernel |

## Upgrade Policy

- Package version bumps are tied to the Laravel framework version lifecycle.
- Never upgrade a package independently of the Laravel framework version it
  depends on.
- Run the full test suite after any package upgrade.
- Review each package's upgrade guide before bumping — major versions may
  require migration steps.
- Document version constraints in the [dependency
  matrix](../dependencies/dependency-matrix.md).