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
- Telescope integration
- Health check endpoint
- System health dashboard
- Status: PLANNED

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