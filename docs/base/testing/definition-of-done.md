# Definition of Done

## Criteria

A feature is NOT complete merely because its controller/model exists.

For each feature evaluate ALL of the following:

| # | Criterion | Description |
|---|-----------|-------------|
| 1 | **Architecture** | Does it follow the separation of responsibilities? Is it API-first? UI-independent? |
| 2 | **Implementation** | Is the code clean, follows conventions, minimal diff? |
| 3 | **Authorization** | Are policies in place? Is access properly gated? |
| 4 | **API** | Is the API contract designed? Versioned if needed? |
| 5 | **UI** | If applicable — is UI consistent (or absent because UI-independent)? |
| 6 | **Audit** | Are mutations audited with full metadata? |
| 7 | **Validation** | Are inputs validated at trust boundaries? |
| 8 | **Error handling** | Are errors handled gracefully? Consistent error contracts? |
| 9 | **Tests** | Unit, feature, API, auth, authorization, security, validation, DB, queue, events, regression — all where applicable. |
| 10 | **Documentation** | Does documentation match code? Updated after behavior change? |
| 11 | **Manual QA** | Verified manually where automated tests can't cover. |
| 12 | **Security** | Input validation, auth, escaping, secrets, no info leakage. |
| 13 | **Regression impact** | Will this break existing features? Regression tests added for fixes. |

## Gate

A feature is **DONE** only when ALL applicable criteria pass review.

- Never silently mark a task DONE.
- Update task status only after verification.
- Tests must be green before commit.
- Documentation must match code.
- Security must not be weakened to make tests pass.

## Coverage Thresholds (P15-E2)

Enforced on a schedule and on release tags, not per PR (a second full suite
with instrumentation doubles PR wall-clock for a slow-moving number):

| Layer | Bar | Enforced by |
|-------|-----|-------------|
| Whole `app/` | ≥ 85 % | `php artisan test --coverage --min=85` |
| `app/Actions` | ≥ 95 % | `php bin/coverage_gate.php` (same clover run) |
| `app/Policies` | = 100 % | same |
| `app/Http/Requests` | ≥ 95 % | same |

A missed bar is a recorded gap list with owners — never a lowered threshold,
never a deleted test.

## AI Execution Protocol Checks

Before completing a task, verify:
1. ✅ Implementation
2. ✅ Tests
3. ✅ Security
4. ✅ Documentation
5. ✅ Regression impact