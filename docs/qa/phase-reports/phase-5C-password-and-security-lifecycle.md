# Phase 5C QA Report — Password & Security Lifecycle

Date: 2026-09-25
Scope: Password expiration, forced password change, warning banner, inactivity lock, settings contract, runtime sweeps, and audit wiring.
Status: DONE

## Verification

- Targeted remediation/lifecycle tests: 24 passed, 54 assertions.
- Full Laravel suite: 274 passed, 698 assertions.
- Existing risky test: `Tests\\Feature\\Security\\DebugRateLimiterTest` performs no assertions.
- Pint: passed.
- PHP syntax checks: passed.
- Blade cache: passed.
- Password route registration: passed.
- Vite production build: passed.

## Delivered

- Canonical password expiry key: `password_expiry_days`.
- Legacy `password_expiration_days` references removed from runtime code and docs; cleanup migration added.
- `password.expired` route and controller render the forced password-change screen.
- Password expiry warning partial included in the authenticated app layout.
- Warning calculation preserves existing day-boundary behavior.
- Inactivity lock handles `last_activity_at = NULL` through `created_at`; `inactivity_lock_grace_enabled` controls whether `inactivity_lock_grace_days` is applied.
- Middleware locks inactive users during a request, revokes sessions/tokens through the shared service, and records `auth.inactivity_lock.middleware` with `causer = SYSTEM` in properties and a null `causer_id`.
- Sweep jobs use `$this->audit()` through `AuditsSystemActivity`; business logs contain counters/timing and no passwords or tokens.
- Password change/reset revokes Sanctum tokens, database sessions, and `remember_token` in the same mutation transaction.
- Sweep jobs process users in 500-row `chunkById` batches.
- The minute-based scheduler has `withoutOverlapping()` protection.
- Settings use the shared Web/API action and the database-backed timezone catalog.
- Sweep schedule reads runtime `Sweep Time` and `Sweep Timezone`; scheduler and queue remain separate processes.

## Deployment Notes

- Run the scheduler through `bin/run-workers.sh cron` or the platform scheduler.
- Run the queue through `bin/run-workers.sh queue` under Supervisor/systemd in production.
- `QUEUE_VERBOSITY` controls queue lifecycle output; business logs remain in `storage/logs/laravel.log`.
- Local migration history is inconsistent: the physical `system_settings` table exists but its original migration is not recorded. Do not reset the local database; use the existing isolated migration/seed procedure where needed.

## Remaining Non-Blocking Follow-ups

- Add explicit production Supervisor/systemd unit examples when deployment infrastructure is introduced.
- Add visual browser checks for the settings layout and responsive warning banner.
- Add DST/multi-timezone scheduler tests when the deployment timezone policy is finalized.

## Phase Tracking

Phase 5C is DONE. Phase 3 remains IN PROGRESS at the phase level; its implementation breakdown is documented as complete and tracker reconciliation remains separate.
