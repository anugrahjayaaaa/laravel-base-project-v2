# Remediation Tracker

Updated: 2026-09-25

| ID | Finding | Resolution | Status |
|----|---------|------------|--------|
| P5C-001 | Dual password expiration keys in readers and persistence | Canonicalized on `password_expiry_days`; legacy key removed from runtime readers and covered by cleanup migration | RESOLVED |
| P5C-002 | `password-expired` view had no route/controller caller | Added `password.expired` route, controller action, middleware exemption, redirect, and render regression test | RESOLVED |
| P5C-003 | Password expiry warning partial was dead code | Included in authenticated app layout; service data supplied by `AppServiceProvider` view composer; render regression test added | RESOLVED |
| P5C-004 | Null `last_activity_at` was ignored by inactivity policy | Uses `created_at` as the reference; `inactivity_lock_grace_enabled` controls whether `inactivity_lock_grace_days` is applied; sweep candidates include null activity; regression tests added | RESOLVED |
| P5C-005 | Request-time inactivity lock had no audit trail | Middleware records `auth.inactivity_lock.middleware` with SYSTEM properties and null `causer_id`; regression test added | RESOLVED |
| P5C-006 | Jobs used direct activity logging instead of the requested audit entry point | Both sweeps use `$this->audit()` through `AuditsSystemActivity` | RESOLVED |
| P5C-007 | Settings Web/API persistence logic was duplicated | Both controllers use `UpdateSystemSettingsAction` | RESOLVED |
| P5C-008 | Settings timezone catalog was hardcoded | Added database-backed `Timezone` model, migration, Aisense seeder, validation, and UI catalog | RESOLVED |
| P5C-009 | Sweep schedule was hardcoded daily | Minute schedule reads runtime sweep time and timezone, then dispatches both jobs | RESOLVED |
| P5C-010 | Queue/scheduler operations were not documented | Added `bin/run-workers.sh` modes and queue operations documentation | RESOLVED |
| P5C-011 | Phase 3 documentation could be mistaken for phase-level completion | Phase 3 remains IN PROGRESS; implementation breakdown is marked complete; reconciliation remains separate | TRACKING |

## Verification

See [Phase 5C QA report](./phase-reports/phase-5C-password-and-security-lifecycle.md).
