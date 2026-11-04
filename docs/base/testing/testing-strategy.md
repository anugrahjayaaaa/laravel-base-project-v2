# Testing Strategy

## Overview

Testing goes beyond code coverage. Coverage is a metric, not the definition of test completeness.

## Testing Types

| Type | Purpose | Tools |
|------|---------|-------|
| Unit tests | Test individual classes/methods in isolation | PHPUnit, Pest |
| Feature tests | Test feature behavior end-to-end | Laravel Dusk (web), API tests |
| API tests | Test API endpoints, responses, contracts | PHPUnit, Pest |
| Authorization tests | Verify permission/access control | Policy tests |
| Authentication tests | Login, logout, password reset, session mgmt | Feature/API tests |
| Security tests | Input validation, escaping, auth checks | Custom + security scan |
| Validation tests | Form request validation rules | Unit tests |
| Database tests | Migrations, seeders, constraints | Unit/Feature |
| Queue tests | Job dispatch, execution, failure | Queue fake |
| Event/listener tests | Event dispatch, listener reaction | Event fake |
| Notification/mail tests | Delivery, queuing, channels | Notification fake, Mail fake |
| Policy tests | Authorization policy logic | Unit/Feature |
| Middleware tests | Request filtering, auth gates | Feature tests |
| Concurrency tests | Race condition handling | Feature tests + load simulation |
| Regression tests | Ensure previously fixed bugs don't return | PHPUnit |
| Static analysis | Type correctness over `app/` — level 8, Laravel-aware | PHPStan + Larastan (`composer analyse`), committed `phpstan-baseline.neon` as debt ledger (ADR-030) |
| Integration tests | Cross-component behavior | Full-stack tests || Manual QA | UI/UX, exploratory, human judgment | Manual test plans |

## Testing Principles

- Every feature must have explicit test scenarios (see QA Tracker).
- Tests must be deterministic and isolated.
- Do not weaken security to make a test pass.
- Never remove a test simply to make the suite pass.
- Add regression tests for discovered bugs.

## Test Organization

```
tests/
├── Unit/           # Isolated unit tests
├── Feature/        # Feature/API behavior tests
├── Api/            # API-specific tests (if needed)
├── Arch/           # Architecture compliance tests
└── TestCase.php    # Base test case
```

## Test Data

Use model factories and seeders:
- `database/factories/` for test data generation.
- `database/seeders/` for consistent baseline data.
- Avoid hardcoding test data; use factories for flexibility.

## CI Integration

- Tests run on every commit/PR.
- `vendor/bin/phpstan analyse --memory-limit=1G` exits 0 — the baseline fails
  on new errors **and** on entries that no longer match (ADR-030). CI file
  ownership of the run is P15-E3's decision.
- Coverage thresholds enforced.
- Security scan integrated.
- Architecture tests verify dependency rules.

## CI/CD Pipeline

- PR runs (owned by `.github/workflows/ci-cd.yml`): `pint --test` over the full
  tree (P15-D2), `php artisan test`, `composer audit --locked` +
  `npm audit --audit-level=high` at merge.
- Scheduled (`.github/workflows/testing.yml`, weekly): dependency audit against
  the lockfiles — catches advisories published after a merge, which the PR-only
  audit can never see (P15-D4). Same thresholds as the PR run. PHPStan and the
  coverage gate join this file in P15-E3.

---

## Test Coverage Areas

| Component | Test Type |
|-----------|-----------|
| Auth (login, logout, lock, unlock) | API + Feature |
| Registration | API + Feature |
| Password reset/security | API + Feature |
| RBAC (roles, permissions) | Unit + Feature |
| Feature flags | Unit + Feature |
| Audit Trail | Feature + Integration |
| Settings | Unit + Feature |
| Notifications | Unit + Feature |
| Queue jobs | Unit + Queue tests |
| Events/listeners | Unit + Event tests |
| Middleware | Feature tests |
| Policies | Policy tests |
| Concurrency-sensitive operations | Integration tests |
| Error handling | API tests |
| Validation | Unit tests |
| Security (CSRF, XSS, SQLi) | Feature + Security scan |