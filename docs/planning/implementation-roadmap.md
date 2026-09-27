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
| 9 | Notification/mail/queue | P1 | PLANNED |
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
- Packages installed: Sanctum, Spatie Permission, Activitylog, Telescope, Laravel Pennant (feature flags)
  (see [dependency overview](../base/dependencies/overview.md))
- AdminLTE initial UI setup (UI-001: vendor from release ZIP, wire Blade layout, UI-independent)
- Status: DONE

### Phase 2: Database Foundation
|- Base migrations (existing Laravel defaults + Spatie + Sanctum + Telescope + Pennant)
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
- Mail configuration
- Notification channels
- Status: PLANNED

### Phase 10: Audit Trail
- Audit package integration
- Audit abstraction layer
- Async export
- Status: PLANNED

### Phase 11: Monitoring & Observability
- Telescope integration (periscope companion UI) — **prod-gated, debug on demand**
- Slow-query detection on production
- Health check endpoint
- System health dashboard
- Status: PLANNED

**Prod gating (decided, pending implementation).** Telescope stays installed
on the VM but is OFF by default. `TelescopeServiceProvider::boot()` returns
before `Telescope::start()` when `config('telescope.enabled')` is false, so
watchers are never registered — a disabled Telescope costs nothing at runtime.

| Decision | Rationale |
|----------|-----------|
| `enabled` default `false` | a VM whose `.env` forgets the key must not record every request |
| `RequestWatcher` off | it stores request bodies (passwords, tokens) unencrypted in `telescope_entries` |
| `ModelWatcher` off | most expensive watcher — serialises every Eloquent model |
| `QueryWatcher` on | this is the actual requirement: slow-query diagnosis |
| periscope + telescope in `require` | periscope hard-requires telescope, so moving telescope to `require-dev` alone does not remove it from `--no-dev` installs |

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

Telescope is deliberately **absent** from that ladder. When it is on it adds
write load to `telescope_entries` for every request, which slows the server
precisely when the server is already slow — and grows that table unbounded
(nothing in `config/telescope.php` prunes it automatically).

**Runbook — toggling Telescope on the VM:**

```bash
# enable (config is cached, so config:clear and an fpm reload are both required)
echo 'TELESCOPE_ENABLED=true' >> .env
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.3-fpm     # without this, opcache keeps the old config

# inspect
php artisan telescope:list
# open /telescope (auth-gated by laravel/sentinel middleware)

# disable
echo 'TELESCOPE_ENABLED=false' >> .env
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.3-fpm

# prune — mandatory after a debugging session, no auto-prune exists
php artisan telescope:prune --hours=6
```

Note: `.env` is excluded from the rsync sync, so the toggle persists across
deploys. That is the intended behaviour, and also why the code default must be
`false` rather than relying on `.env` alone.

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