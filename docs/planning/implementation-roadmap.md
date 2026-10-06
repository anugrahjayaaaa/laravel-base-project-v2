# Implementation Roadmap

## Phases

| Phase | Title | Priority | Status |
|-------|-------|----------|--------|
| 0 | Architecture & project conventions | P0 | PLANNED |
| 1 | Laravel foundation & environment | P0 | DONE |
|| 2 | Database foundation | P0 | DONE |
|| 3 | Authentication foundation | P0 | IN PROGRESS |
|| 4 | User lifecycle & user management | DONE |
| 5 | Password/security lifecycle | P0 | PLANNED |
| 6 | RBAC & authorization | P0 | PLANNED |
| 7 | Feature availability / feature flags | P1 | PLANNED |
| 8 | Settings | P1 | PLANNED |
| 9 | Notification/mail/queue | P1 | DONE |

| 10 | Audit Trail | P0 | PLANNED |
| 11 | Monitoring/observability | P1 | PLANNED |
| 12 | API V1 | P0 | PLANNED |
| 13 | Security hardening | P0 | PLANNED |
| 14 | Storage/backup/retention | P1 | PLANNED |
| 15 | Comprehensive testing | P0 | PLANNED |
| 16 | Documentation verification | P0 | PLANNED |
| 17 | Full regression / final review | P0 | PLANNED |

## Dependency Map

```
Laravel Foundation (Phase 1)
    ↓
Database (Phase 2)
    ↓
Authentication (Phase 3)
    ↓
User Lifecycle (Phase 4)
    ↓
Password/Security (Phase 5)
    ↓
Authorization (Phase 6)
    ↓
Feature Availability (Phase 7)
    ↓
Settings / Security (Phase 8)
    ↓
Notifications / Queue (Phase 9)
    ↓
Audit (Phase 10)
    ↓
API (Phase 12)
    ↓
Observability (Phase 11)
    ↓
Testing / Hardening (Phases 13-17)
```

## Phase Details

### Phase 0: Architecture & Project Conventions
- Finalize architecture principles (done in docs)
- Set up coding conventions (naming, folder structure)
- Configure PSR standards, linting
- Status: Documentation complete; implementation ready

### Phase 1: Laravel Foundation & Environment
- Laravel 13 install
- `.env` / `.env.example`
- Config files (`config/`)
- Queue (database), cache (file) — Redis optional
- Correlation/request ID middleware
- Packages installed: Sanctum, Spatie Permission, Activitylog, Laravel Pulse, Laravel Pennant (feature flags)
  (see [dependency overview](../base/dependencies/overview.md))
- AdminLTE initial UI setup (UI-001: vendor from release ZIP, wire Blade layout, UI-independent)
- Status: DONE

### Phase 2: Database Foundation
|- Base migrations (existing Laravel defaults + Spatie + Sanctum + Pennant + Pulse)
|- Seed data: RoleSeeder (superadmin, admin, user) wired into DatabaseSeeder
|- Database constraints: users table has is_active, is_locked, must_change_password,
  password_expires_at, last_activity_at, soft-deletes
|- Status: DONE

### Phase 3: Authentication Foundation
|- Sanctum API token driver (AUTH-002): already configured in config/auth.php
|- Login (AUTH-004/AUTH-005): FormRequest + AuthController (authenticate, check
  account state, issue Sanctum token, update last_activity_at)
|- Email verification (AUTH-011): enable MustVerifyEmail on User, routes + controller
|- Forgot/reset password (AUTH-012/AUTH-013): Laravel password broker routes + controllers
|- Session management (AUTH-006/AUTH-009/AUTH-010): token issuance + revocation
  (current device + all devices)
|- Failed login tracking (AUTH-007): needs failed_login_attempts table migration
  + increment/lockout logic
|- Temporary lock (AUTH-008): 5 failed attempts → 15 min lock (configurable)
|- Rate limiting (login endpoint): throttle middleware
|- Status: IN PROGRESS
|
**Frontend (AdminLTE):** Login, register, forgot-password, reset-password,
email-verification-notice, password-change pages using AdminLTE auth layout.

### Phase 4: User Lifecycle & Management
- User CRUD
- Activate/deactivate
- Lock/unlock
- Admin user creation (temp password)
- Status: PLANNED

### Phase 5: Password & Security Lifecycle
- IM8 password policy
- Password history
- Password expiration
- Failed login + temp lock
- Inactivity lock
- Status: PLANNED

### Phase 6: RBAC & Authorization
- Spatie Permission setup
- Roles & permissions
- Superadmin protection
- Status: PLANNED

### Phase 7: Feature Availability
- Feature flags
- Backend enforcement
- Status: PLANNED

### Phase 8: Settings
- Settings management UI/API
- Settings validation
- Settings audit
- Status: PLANNED

### Phase 9: Notifications & Mail
- Mail configuration — **DONE** (`SystemSetting` + `encrypt()`, rebound into `config('mail')` after commit)
- Notification channels — **DONE** (global admin switches; `via()` reads them)
- In-app inbox — **DONE** (`/notifications/inbox`, Laravel's `database` channel, no permission)
- Audience rule — **DONE** (`NotificationAudience`, per-action trigger permissions)
- Status: DONE

### Phase 10: Audit Trail
- Audit package integration
- Audit abstraction layer
- Async export
- Status: PLANNED

### Phase 11: Monitoring & Observability
- Laravel Pulse integration (`^1.8`, in `require`) — **DONE** (`85384b4`)
- `pulse` feature flag + route enforcement — **DONE** (`b08b8b4`)
- `pulse.view` permission + `viewPulse` gate override — **PLANNED**
- Sidebar entry for Pulse — **PLANNED**
- Slow-query detection on production
- Health check endpoint
- System health dashboard
- Status: IN PROGRESS

> Full breakdown: [phase-11-monitoring-observability.md](phase-11-monitoring-observability.md).
> **Superseded scope:** this phase originally specified `laravel/telescope` with
> `periscope` as a companion UI. Both were removed in `85384b4` in favour of
> Laravel Pulse — see [DEP-004](../base/architecture/decision-records/DEP-004-laravel-pulse-observability.md).

**Access gating (decided 2026-10-02).** `/pulse` requires **both** the `pulse`
feature flag and the `pulse.view` permission. Two independent gates answering
different questions: the flag is the deploy-time kill switch, the permission is
per-role. Both failures are 403, so a caller cannot tell which fired — the same
deliberate trade Phase 7 made for feature availability.

Pulse ships its own `viewPulse` gate (`environment('local')`) at
`vendor/laravel/pulse/src/PulseServiceProvider.php:100`. `AuthServiceProvider`
redefines it as `$user->can('pulse.view')`; `Gate::define()` overwrites by name and
our provider boots after Pulse's, so ours wins. `Gate::before` is untouched, so
superadmin keeps access with no permission row.

| Decision | Rationale |
|----------|-----------|
| `pulse.view`, not `pulse.manage` | the dashboard is read-only; a second action is a row nothing checks |
| flag AND permission, keep both | deploy-time kill switch vs per-role authorization are not the same question |
| `laravel/pulse` in `require` | production observability here, not a dev-only debugger (contrast DEP-006's queue driver) |
| no `environment('local')` in the gate | the permission replaces it; local devs hold the permission like anyone else |
| no `Pulse::auth()` call | removed in 1.8; the gate is the supported hook |
| admin inherits the permission | `PermissionSeeder` grants `admin` the whole catalogue — no seeder edit needed |

**Slow-query diagnosis ladder for a slow production server** — run in order:

1. `GET /up` — not 200 means infra, not queries
2. `top` / `free -m` — CPU, RAM, swap before blaming the database
3. MySQL slow log — catches queries that never reach PHP (lock wait, disk I/O):
   ```sql
   SET GLOBAL slow_query_log = ON;
   SET GLOBAL long_query_time = 0.3;
   SET GLOBAL log_output = 'TABLE';
   ```
   ```sql
   SELECT start_time, LEFT(sql_text, 200), time_to_lock, rows_examined, rows_sent
   FROM mysql.slow_log ORDER BY start_time DESC LIMIT 20;
   ```
   `rows_examined >> rows_sent` means a missing index — most slow queries, and
   found without any application tooling.
4. `DB::listen()` threshold logger (Phase 11 deliverable) — the complement to
   step 3, covers queries that do reach PHP and measures DB time only
5. `EXPLAIN` / `EXPLAIN ANALYZE` each query surfaced by steps 3-4

Pulse is deliberately **absent** from that ladder, and the reasoning that
originally applied to Telescope still holds. Pulse records on every request while
enabled, adding write load precisely when the server is already slow. The
slow-query requirement — step 3 — is served by the database's own slow log, which
needs no application tooling at all.

**Runbook — toggling Pulse on the VM:**

```bash
# toggle the flag in the UI at /features (never gated), then re-cache config
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.3-fpm     # without this, opcache keeps the old config

# inspect — requires the pulse.view permission (superadmin passes via Gate::before)
# open /pulse

# retention is Pulse's own config; unlike Telescope there is no manual prune step
```

Note: `.env` is excluded from the rsync sync, so a toggle made there persists
across deploys. The flag itself lives in Pennant's store, not `.env` — which is
why flipping it in the UI survives a deploy.

### Phase 12: API V1
- Versioned API routes
- API resources
- Documentation (Scramble/API docs)
- Status: PLANNED

### Phase 13: Security Hardening
- Security headers (CSP, HSTS, etc.)
- CSRF, CORS
- Input sanitization
- Status: PLANNED

### Phase 14: Storage / Backup / Retention
- Storage disks (local, public, tmp)
- Backup strategy
- Retention jobs
- Status: PLANNED

### Phase 15: Comprehensive Testing
- All test types from [testing-matrix.md](../base/testing/testing-matrix.md)
- Coverage thresholds
- Security scanning
- Status: PLANNED

### Phase 16: Documentation Verification
- Verify docs match code
- Update as needed
- Status: PLANNED

### Phase 17: Full Regression / Final Review
- End-to-end testing
- Final review against Definition of Done
- Status: PLANNED