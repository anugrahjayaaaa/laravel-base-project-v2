# Task Tracker

> Machine-readable + human-readable task tracker. All tasks start in `PLANNED` status. Only mark DONE after verification.

## Status Legend

| Status | Meaning |
|--------|---------|
| PLANNED | Task defined, not ready to implement |
| READY | Dependencies met, can be picked up |
| IN_PROGRESS | Actively being worked |
| BLOCKED | Cannot proceed (dependency or blocker) |
| REVIEW | Implementation done, awaiting review |
| DONE | Verified complete |
| CANCELLED | No longer needed |

## Columns

| Column | Description |
|--------|-------------|
| ID | Stable unique identifier |
| Task | Short description |
| Phase | Implementation phase |
| Priority | P0/P1/P2/P3 |
| Depends On | Blocking task IDs |
| Status | Current lifecycle status |
| Acceptance Criteria | What "done" looks like |
| Tests | Test scenarios required |
| Docs | Documentation file(s) to update |
| Security Impact | Security relevance |
| Notes | Additional context |

## Tasks

### Phase 0 — Architecture & Conventions

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P0-001 | Define architecture principles | 0 | P0 | — | DONE |
| P0-002 | Define naming conventions | 0 | P0 | — | DONE |
| P0-003 | Define folder structure | 0 | P0 | — | DONE |
| P0-004 | Define dependency rules | 0 | P0 | — | DONE |
| P0-005 | Define ADR list (12 initial) | 0 | P0 | — | DONE |
| P0-006 | Create documentation structure | 0 | P0 | — | DONE |
| P0-007 | Create AI execution guide | 0 | P0 | — | DONE |
| P0-008 | Create task tracker | 0 | P0 | — | DONE |
| P0-009 | Create QA tracker | 0 | P0 | — | DONE |
| P0-010 | Create implementation roadmap | 0 | P0 | — | DONE |
| P0-011 | Set up Definition of Done | 0 | P0 | — | DONE |

### Phase 1 — Foundation & Environment

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| FOUND-001 | Initialize Laravel 13 project | 1 | P0 | P0-002 | DONE |
|| FOUND-002 | Configure .env / .env.example | 1 | P0 | FOUND-001 | DONE |
|| FOUND-003 | Set up config files (auth, cache, queue, session, mail, logging) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-004 | Install Sanctum for API auth | 1 | P0 | FOUND-001 | DONE |
|| FOUND-005 | Install Spatie Permission (RBAC) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-006 | Install audit package (e.g. spatie/laravel-activitylog) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-007 | Install Telescope | 1 | P0 | FOUND-001 | DONE |
| FOUND-008 | Create correlation/request ID middleware | 1 | P0 | FOUND-001 | DONE |
| FOUND-009 | Set up PSR-12 linting (PHP CS Fixer) | 1 | P1 | FOUND-001 | DONE |
| FOUND-010 | Configure health check endpoint | 1 | P1 | FOUND-001 | DONE |
| CACHE-001 | Define cache config | 1 | P1 | FOUND-003 | DONE |
| QUEUE-001 | Configure queue (database + Redis compat) | 1 | P0 | FOUND-003 | DONE |
| CACHE-002 | Implement cache tagging & invalidation | 1 | P1 | CACHE-001 | PLANNED |
| UI-001 | Vendor AdminLTE 4.9.1 + wire Blade layout | 1 | P1 | FOUND-001 | DONE |
| UI-002 | Application shell (header, sidebar, footer, theme toggle) | 1 | P1 | UI-001 | DONE |
| UI-003 | Shared UI component conventions (buttons, forms, tables, modals) | 1 | P1 | UI-001 | DONE |
| UI-004 | Reusable confirmation modal component | 1 | P1 | UI-002 | DONE |
| UI-005 | Theme toggle (system default + manual dark/light) | 1 | P1 | UI-001 | DONE |
| UI-006 | UI foundation cleanup & style refinement (partials rename, theme fix, i18n removal, style guide) | 1 | P1 | UI-001,UI-002,UI-003,UI-004,UI-005 | DONE |
| SOFT-001 | Soft delete / trash / permanent delete convention | 1 | P1 | FOUND-002 | DONE |
| TABLE-001 | Shared table conventions (sortable, filterable, bulk actions, pagination) | 1 | P2 | UI-003 | DONE |
|| FLAG-001 | Feature flag package foundation | 1 | P1 | FOUND-003 | DONE |

**Audit pattern (cross-phase convention):** All mutations log audit at the mutation
site — Action self-logs when logic is complex/shared; Controller logs directly
(using `$this->audit()` helper on base Controller) for thin operations. No model
observers for audit. See `docs/base/architecture/application-components.md` §Action/Service.

---

### Phase 4 — User Lifecycle & User Management

|| ID | Task | Phase | Priority | Depends On | Status |
||----|------|-------|----------|-----------|--------|
|| USER-001 | Create user management module | 4 | P1 | AUTH-003 | DONE |
|| USER-002 | Implement user list/detail API | 4 | P1 | USER-001 | DONE |
|| USER-003 | Implement create user (admin) | 4 | P1 | AUTH-001 | DONE |
|| USER-004 | Implement update user | 4 | P1 | USER-002 | DONE |
|| USER-005 | Implement soft delete user | 4 | P2 | USER-001 | DONE |
|| USER-006 | Implement activate/deactivate | 4 | P1 | USER-003 | DONE |
|| USER-007 | Implement lock/unlock | 4 | P1 | USER-001 | DONE |
|| USER-008 | Implement force password change | 4 | P1 | AUTH-014 | DONE |
|| USER-009 | Implement admin reset password | 4 | P1 | AUTH-013 | DONE |

*(Task list truncated for phases 2-17. See full list in the JSON version below.)*

---

## Full Task List (JSON for AI parsing)

```json[
  {
    "id": "AUTH-001",
    "task": "Define authentication requirements",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "FOUND-004"
    ],
    "status": "DONE",
    "note": "requirements.md + authentication.md exist. Phase 3 implemented per spec."
  },
  {
    "id": "AUTH-002",
    "task": "Configure Sanctum API token driver",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "FOUND-004"
    ],
    "status": "DONE",
    "note": "Sanctum configured — middleware, token creation in LoginController, logout deletes token."
  },
  {
    "id": "AUTH-003",
    "task": "Create authentication data model",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "DB-002"
    ],
    "status": "DONE",
    "note": "User model: email_verified_at, is_active, is_locked, must_change_password, password_expires_at, last_activity_at, verification_token."
  },
  {
    "id": "AUTH-004",
    "task": "Implement login validation (username/email)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE",
    "note": "LoginRequest: identifier+password, custom messages, anti-enumeration."
  },
  {
    "id": "AUTH-005",
    "task": "Implement login action",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-004"
    ],
    "status": "DONE",
    "note": "LoginController: __invoke, uses AuthenticateUserAction (throttle + findUser + account state). Controllers thin — API JSON, Web redirect. Audit by controller with channel."
  },
  {
    "id": "AUTH-006",
    "task": "Implement session creation",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "Sanctum token via createToken."
  },
  {
    "id": "AUTH-007",
    "task": "Implement failed-login tracking",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "LoginThrottle::recordFailed/reset/lockedFor/clearIfExpired."
  },
  {
    "id": "AUTH-008",
    "task": "Implement temporary lock after failed attempts",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-007"
    ],
    "status": "DONE",
    "note": "LoginThrottle::isLocked with exponential backoff."
  },
  {
    "id": "AUTH-009",
    "task": "Implement logout (current device)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-006"
    ],
    "status": "DONE",
    "note": "LogoutController: delete current token + audit."
  },
  {
    "id": "AUTH-010",
    "task": "Implement logout-all-devices",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-009"
    ],
    "status": "DONE",
    "note": "LogoutAllController: delete all tokens + audit."
  },
  {
    "id": "AUTH-011",
    "task": "Implement email verification",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-006"
    ],
    "status": "DONE",
    "note": "VerifyEmailController → VerifyEmailAction (markEmailAsVerified + audit). ResendVerificationController unchanged."
  },
  {
    "id": "UI-A-001",
    "task": "Auth login page (Blade view + form)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/login.blade.php, layouts/auth.blade.php, route /login."
  },
  {
    "id": "UI-A-002",
    "task": "Auth forgot password page",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-012"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/forgot-password.blade.php, route /forgot-password."
  },
  {
    "id": "UI-A-003",
    "task": "Auth reset password page",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/reset-password.blade.php, route /reset-password."
  },
  {
    "id": "UI-A-004",
    "task": "Auth verify email page",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-011"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/verify-email.blade.php + verified.blade.php, routes /verify-email, /email/verify/{id}/{hash}."
  },
  {
    "id": "UI-A-005",
    "task": "Connect UI views to API logic (JS fetch/AJAX)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "UI-A-001",
      "UI-A-002",
      "UI-A-003",
      "UI-A-004"
    ],
    "status": "DONE",
    "note": "Web forms wired via controller redirects (not JS fetch). POST /login, /forgot-password, /reset-password, /email/resend → AuthControllerredirect."
  },
  {
    "id": "AUTH-012",
    "task": "Implement forgot password",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE",
    "note": "PasswordForgotController → SendPasswordResetLinkAction (lock check + send link + audit). Anti-enumeration: always-same-response."
  },
  {
    "id": "AUTH-013",
    "task": "Implement password reset",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-012"
    ],
    "status": "DONE",
    "note": "PasswordResetController → ResetPasswordAction (lock check + reset + audit). Uses Laravel Password facade."
  },
  {
    "id": "AUTH-014",
    "task": "Implement password change (user)",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Web/API password change flow uses the shared action and revokes all Sanctum tokens, database sessions, and remember_token atomically."
  },
  {
    "id": "AUTH-015",
    "task": "Implement rate limiting for login",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "RATE-001"
    ],
    "status": "DONE",
    "note": "Login throttling and progressive lockout are implemented and covered by the authentication test suite."
  },
  {
    "id": "USER-001",
    "task": "Create user management module",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-003"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-002",
    "task": "Implement user list/detail API",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-003",
    "task": "Implement create user (admin)",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-004",
    "task": "Implement update user",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-002"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-005",
    "task": "Implement soft delete user",
    "phase": 4,
    "priority": "P2",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-006",
    "task": "Implement activate/deactivate",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-003"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-007",
    "task": "Implement lock/unlock",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-008",
    "task": "Implement force password change",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-014"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-009",
    "task": "Implement admin reset password",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE"
  },
  {
    "id": "PWD-001",
    "task": "Define IM8 password policy",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "P0-001"
    ],
    "status": "DONE",
    "note": "Group A complete: PasswordPolicy + PasswordStrengthRule + views + tests. P5-A1..A9 shipped."
  },
  {
    "id": "PWD-002",
    "task": "Implement password validation rule",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "PWD-001"
    ],
    "status": "DONE",
    "note": "Group A complete: PasswordStrengthRule wired into ChangePasswordAction, ResetPassword, ProfileUpdate requests."
  },
  {
    "id": "PWD-003",
    "task": "Implement password history",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Phase 5 Group B complete: password history table, policy enforcement, settings UI, and tests shipped."
  },
  {
    "id": "PWD-004",
    "task": "Implement password expiration",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Phase 5 Group C complete: password expiry service, middleware, forced-change screen, warning banner, sweeps, settings, and tests shipped."
  },
  {
    "id": "PWD-005",
    "task": "Implement admin password reset",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE"
  },
  {
    "id": "PWD-006",
    "task": "Implement temp password + force change",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-003"
    ],
    "status": "DONE"
  },
  {
    "id": "RBAC-001",
    "task": "Seed roles (superadmin, admin, user)",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "DB-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RBAC-002",
    "task": "Implement role management",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RBAC-003",
    "task": "Implement permission management",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RBAC-004",
    "task": "Define permission set",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P0-004"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RBAC-005",
    "task": "Implement superadmin protection + system role protection (deletion/rename/permission manipulation guards, last-superadmin enforcement)",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "FEAT-001",
    "task": "Implement feature flags backend",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "FEAT-002",
    "task": "Implement feature availability enforcement",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "FEAT-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-001",
    "task": "Design settings schema",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "DB-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-002",
    "task": "Implement settings CRUD",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-003",
    "task": "Implement settings validation",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-004",
    "task": "Implement settings audit",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-005",
    "task": "Implement settings cache invalidation",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002",
      "CACHE-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-001",
    "task": "Configure mail",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-002",
    "task": "Implement notification channels",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "NOTIF-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-003",
    "task": "Queue email sending",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "QUEUE-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-001",
    "task": "Integrate audit package",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-002",
    "task": "Create audit abstraction layer",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-003",
    "task": "Implement audit recording in Actions",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-004",
    "task": "Implement audit view/detail API",
    "phase": 10,
    "priority": "P1",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-005",
    "task": "Implement async audit export",
    "phase": 10,
    "priority": "P2",
    "depends_on": [
      "AUDIT-002",
      "QUEUE-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RATE-001",
    "task": "Define rate limit config",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "SET-001"
    ],
    "status": "DONE",
    "note": "AuthServiceProvider::boot() — 4 RateLimiter: login (5/min), forgot-password (3/min), reset-password (3/min), resend-verification (5/hour). Config: config/rate_limits.php."
  },
  {
    "id": "RATE-002",
    "task": "Implement rate limiting middleware",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "RATE-001",
      "FOUND-003"
    ],
    "status": "DONE",
    "note": "throttle:login, throttle:forgot-password, throttle:reset-password, throttle:resend-verification wired on web + API routes. Shared key via LoginThrottle::key()."
  },
  {
    "id": "CACHE-001",
    "task": "Define cache config",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "DONE",
    "docs": [
      "cache.md"
    ],
    "verifies": "FOUND-003 config + DEP-006"
  },
  {
    "id": "CACHE-002",
    "task": "Implement cache tagging & invalidation",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "CACHE-001"
    ],
    "status": "PLANNED",
    "note": "Deferred: base project has no application-level cached data requiring grouped invalidation. Pattern documented in cache.md; implement when a feature introduces cacheable data needing cache::tags() invalidation."
  },
  {
    "id": "QUEUE-001",
    "task": "Configure queue (database + Redis compat)",
    "phase": 1,
    "priority": "P0",
    "depends_on": [
      "FOUND-003",
      "DB-001"
    ],
    "status": "DONE",
    "verifies": "FOUND-003 config + DEP-006"
  },
  {
    "id": "DB-001",
    "task": "Create base migration scaffold",
    "phase": 2,
    "priority": "P0",
    "depends_on": [
      "FOUND-002"
    ],
    "status": "DONE"
  },
  {
    "id": "DB-002",
    "task": "Create seed data (roles, permissions)",
    "phase": 2,
    "priority": "P0",
    "depends_on": [
      "DB-001"
    ],
    "status": "DONE"
  },
  {
    "id": "API-001",
    "task": "Define API v1 routes",
    "phase": 12,
    "priority": "P0",
    "depends_on": [
      "SET-003",
      "AUDIT-004"
    ],
    "status": "PLANNED"
  },
  {
    "id": "API-002",
    "task": "Implement API resources (v1)",
    "phase": 12,
    "priority": "P0",
    "depends_on": [
      "API-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "API-003",
    "task": "Generate API documentation (Scramble)",
    "phase": 12,
    "priority": "P1",
    "depends_on": [
      "API-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-001",
    "task": "Implement security headers",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-002",
    "task": "Implement CSRF/CORS (web)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-003",
    "task": "Implement error handling (consistent JSON)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "STOR-001",
    "task": "Define storage disks (local, public, tmp)",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "STOR-002",
    "task": "Implement audit export storage (private)",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "STOR-001",
      "AUDIT-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "BACKUP-001",
    "task": "Define backup strategy",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "STOR-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RETAIN-001",
    "task": "Implement retention policy jobs",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "DB-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "MONITOR-001",
    "task": "Integrate Telescope + Periscope companion UI",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-007"
    ],
    "status": "DONE",
    "docs": [
      "monitoring.md",
      "observability.md",
      "overview.md",
      "DEP-004-telescope-technical-observability.md"
    ],
    "tests": [
      "PeriscopeFoundationTest"
    ],
    "note": "Telescope installed at FOUND-007 (Phase 1). Periscope v0.3 added as companion UI reading the same Telescope data via Telescope::check(); inherits Telescope authorization. No separate auth/gate/migration."
  },
  {
    "id": "MONITOR-002",
    "task": "Implement health check endpoint",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-010"
    ],
    "status": "DONE",
    "docs": [
      "monitoring.md"
    ],
    "tests": [
      "HealthCheckEndpointTest"
    ]
  },
  {
    "id": "CORR-001",
    "task": "Implement correlation ID middleware",
    "phase": 1,
    "priority": "P0",
    "depends_on": [
      "FOUND-008"
    ],
    "status": "DONE",
    "tests": [
      "TEST-API-003",
      "CorrelationIdMiddlewareTest"
    ],
    "docs": [
      "logging.md"
    ]
  },
  {
    "id": "UI-001",
    "task": "Vendor AdminLTE from official release ZIP (not npm) + wire initial Blade layout (UI-independent)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-adminlte-setup.md",
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-002",
    "task": "Application shell (header, sidebar, footer, theme toggle)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-003",
    "task": "Shared UI component conventions (buttons, forms, tables, modals)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "design-system.md",
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-004",
    "task": "Reusable confirmation modal component",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-002"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-005",
    "task": "Theme toggle (system default + manual dark/light)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "SOFT-001",
    "task": "Soft delete / trash / permanent delete convention",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-002"
    ],
    "status": "DONE",
    "docs": [
      "soft-delete.md"
    ]
  },
  {
    "id": "TABLE-001",
    "task": "Shared table conventions (sortable, filterable, bulk actions, pagination)",
    "phase": 1,
    "priority": "P2",
    "depends_on": [
      "UI-003"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "FLAG-001",
    "task": "Feature flag package foundation (install + configure + UI conventions)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "DONE",
    "docs": [
      "feature-flags.md"
    ],
    "tests": [
      "PennantFoundationTest"
    ],
    "note": "Implemented with Laravel Pennant. Migration published (features table). Config via env PENNANT_STORE. No business-specific flags created in Phase 1."
  },
  {
    "id": "UI-006",
    "task": "UI foundation cleanup: rename partials (no app-* prefix), remove obsolete adminlte layout, fix theme (system default + manual override, no flash), remove i18n from UI foundation, add style guide",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001",
      "UI-002",
      "UI-003",
      "UI-004",
      "UI-005"
    ],
    "status": "DONE",
    "docs": [
      "style-guide.md",
      "ui-architecture.md",
      "ai-execution-guide.md"
    ]
  },
  {
    "id": "INACT-001",
    "task": "Implement inactivity tracking",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE",
    "note": "Phase 5 Group C complete: last activity login tracking, null-user grace period, request-time lock audit, scheduled lock sweep, and tests shipped."
  },
  {
    "id": "ROUTE-001",
    "task": "Group routes: public vs auth (web + api)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "web.php: public routes (/, login, forgot-password, reset-password, verify-email) + auth group (dashboard). api.php: public (login, forgot, reset, verify) + protected (logout, logout-all, resend, password.change)."
  },
  {
    "id": "ROUTE-002",
    "task": "Dashboard auth middleware",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "Added 'auth' middleware to /dashboard route. Unauthenticated access returns 302 redirect to login."
  },
  {
    "id": "ROUTE-003",
    "task": "Brute force throttle on web/auth endpoints",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "API: throttle:login (5/min), throttle:forgot-password (3/min), throttle:resend-verification (5/hour). Shared keys via LoginThrottle::key() (identifier+IP). Web forms POST to API endpoints \u2192 shared throttle."
  },
  {
    "id": "ROUTE-004",
    "task": "Document route grouping + throttle in planning docs",
    "phase": 3,
    "priority": "P1",
    "depends_on": [],
    "status": "DONE",
    "note": "Added route grouping table and brute force throttle table to phase-3-audit-breakdown.md \u00a71."
  }
]```