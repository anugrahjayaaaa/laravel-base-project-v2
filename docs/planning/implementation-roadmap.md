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

| 10 | Audit Trail | P0 | DONE |
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
- Re-audit of the shipped phase — **DONE** (7 findings, all closed): credential reachable through the settings module, in-transaction dispatch on 3 actions + `$afterCommit` on all 7 classes, badge/list cache divergence, no retention, no rate limit, a dead model, and the mail switch able to break the four flows with no alternative channel
- Notification copy — **DONE** (researched and rewritten: subjects stand alone, bodies add the actor and the next step; `NotificationCopyTest` pins the contract)
- Performance — **MEASURED** (`NotificationBenchmarkTest`: no N+1, warm bell 0 queries, inbox bounded by pagination, audience 2 queries at any admin count)
- Security — **DONE** (`NotificationPentestTest`: 11 probes / 108 assertions, no findings)
- Retention — **DONE** (daily scheduled sweep, read notifications older than 90 days)
- Status: DONE

### Phase 10: Audit Trail
- Audit package integration — **DONE** (`spatie/laravel-activitylog ^4.8`, 3 migrations)
- Audit abstraction layer — **DONE** (`App\Models\Concerns\Auditable` trait, one entry point)
- Async export — **DONE** (job on a private disk, retention sweep, rate limit in `system_settings`)
- Reader, on **both** channels — **DONE** (`phase-12-api-v1.md` closed the API gap)
- Pentest — **DONE** (`AuditPentestTest`: 63 probes, verified to fail with the gate removed; `CsvInjectionPentestTest`: 4 probes, 2026-10-14)
- Status: DONE — Groups A–F closed. Follow-ups and the two open questions in
  `phase-10-audit-trail.md` §11 remain; see `progress.md`.

### Phase 11: Monitoring & Observability
- Laravel Pulse integration (`^1.8`, in `require`) — **DONE** (`85384b4`)
- `pulse` feature flag + route enforcement — **DONE** (`b08b8b4`)
- `pulse.view` permission + `viewPulse` gate override — **DONE** (2026-10-15, P11-B1/B2)
- Sidebar entry for Pulse — **DONE** (2026-10-15, P11-A1 — Monitoring group, both dashboards)
- Health check API endpoint — **DONE** (`/api/v1/health`, ungated by design)
- System health dashboard (permission `health.view` + flag `health`) — **DONE** (2026-10-15, P11-B3/C1/A2 + A3/A4 gate and render tests)
- Queue conventions (named queues, `$tries`/`$timeout`, `failed()`) — **DONE** (2026-10-16, P11-E1..E3)
- Slow-query detection on production — **DEFERRED by decision**: MySQL slow log covers it; the `DB::listen()` logger is added only when a case appears the slow log cannot see
- Status: **DONE 2026-10-16** — Groups A–F closed, plus the post-audit gap closure (§14 of the breakdown); full suite 2016 passed / 6940 assertions, Pint clean. Committed on `feature/phase-11-monitoring-observability`, awaiting review and merge to `main`

> Full breakdown: [phase-11-monitoring-observability.md](phase-11-monitoring-observability.md)
> — Groups A–F, the access-gating decision table, the slow-query diagnosis
> ladder, the VM runbook, and the Pulse/Horizon/Vantage queue-dashboard
> decision all live there; this section is the status summary only.
> **Superseded scope:** this phase originally specified `laravel/telescope` with
> `periscope` as a companion UI. Both were removed in `85384b4` in favour of
> Laravel Pulse — see [DEP-004](../base/architecture/decision-records/DEP-004-laravel-pulse-observability.md).

### Phase 12: API V1
- Versioned API routes
- API resources
- Documentation (Scramble/API docs)
- Status: **DONE 2026-10-21** — Groups A–E closed, plus the 2026-10-24
  post-close audit (contract envelope and authorization in the generated
  document, the `bearerAuth` scheme, module tags, and the three untested
  endpoints covered). `/docs/api` and `/docs/api.json` are gated by
  `api_docs_public` / `api_docs.view` and served from the deploy export
  (ADR-025). See [phase-12-api-v1.md](phase-12-api-v1.md).

### Phase 13: Security Hardening
- Security headers (CSP, HSTS, etc.)
- CSRF, CORS
- Input sanitization
- Status: IN PROGRESS — Groups A (headers/CSP) and B (CSRF) DONE 2026-10-27; Groups C–E PLANNED

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