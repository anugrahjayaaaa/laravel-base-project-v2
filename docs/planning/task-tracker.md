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
| FOUND-001 | Initialize Laravel 13 project | 1 | P0 | P0-002 | PLANNED |
| FOUND-002 | Configure .env / .env.example | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-003 | Set up config files (auth, cache, queue, session, mail, logging) | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-004 | Install Sanctum for API auth | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-005 | Install Spatie Permission (RBAC) | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-006 | Install audit package (e.g. spatie/laravel-activitylog) | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-007 | Install Telescope | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-008 | Create correlation/request ID middleware | 1 | P0 | FOUND-001 | PLANNED |
| FOUND-009 | Set up PSR-12 linting (PHP CS Fixer) | 1 | P1 | FOUND-001 | PLANNED |
| FOUND-010 | Configure health check endpoint | 1 | P1 | FOUND-001 | PLANNED |

*(Task list truncated for phases 2-17. See full list in the JSON version below.)*

---

## Full Task List (JSON for AI parsing)

```json
[
  {"id": "AUTH-001", "task": "Define authentication requirements", "phase": 3, "priority": "P0", "depends_on": ["FOUND-004"], "status": "PLANNED", "tests": ["TEST-AUTH-001"], "docs": ["authentication.md", "requirements.md"]},
  {"id": "AUTH-002", "task": "Configure Sanctum API token driver", "phase": 3, "priority": "P0", "depends_on": ["FOUND-004"], "status": "PLANNED"},
  {"id": "AUTH-003", "task": "Create authentication data model", "phase": 3, "priority": "P0", "depends_on": ["DB-002"], "status": "PLANNED"},
  {"id": "AUTH-004", "task": "Implement login validation (username/email)", "phase": 3, "priority": "P0", "depends_on": ["AUTH-001"], "status": "PLANNED"},
  {"id": "AUTH-005", "task": "Implement login action", "phase": 3, "priority": "P0", "depends_on": ["AUTH-004"], "status": "PLANNED"},
  {"id": "AUTH-006", "task": "Implement session creation", "phase": 3, "priority": "P0", "depends_on": ["AUTH-005"], "status": "PLANNED"},
  {"id": "AUTH-007", "task": "Implement failed-login tracking", "phase": 5, "priority": "P0", "depends_on": ["AUTH-005"], "status": "PLANNED"},
  {"id": "AUTH-008", "task": "Implement temporary lock after failed attempts", "phase": 5, "priority": "P0", "depends_on": ["AUTH-007"], "status": "PLANNED"},
  {"id": "AUTH-009", "task": "Implement logout (current device)", "phase": 3, "priority": "P0", "depends_on": ["AUTH-006"], "status": "PLANNED"},
  {"id": "AUTH-010", "task": "Implement logout-all-devices", "phase": 3, "priority": "P1", "depends_on": ["AUTH-009"], "status": "PLANNED"},
  {"id": "AUTH-011", "task": "Implement email verification", "phase": 3, "priority": "P0", "depends_on": ["AUTH-006"], "status": "PLANNED"},
  {"id": "AUTH-012", "task": "Implement forgot password", "phase": 3, "priority": "P0", "depends_on": ["AUTH-001"], "status": "PLANNED"},
  {"id": "AUTH-013", "task": "Implement password reset", "phase": 3, "priority": "P0", "depends_on": ["AUTH-012"], "status": "PLANNED"},
  {"id": "AUTH-014", "task": "Implement password change (user)", "phase": 5, "priority": "P0", "depends_on": ["PWD-002"], "status": "PLANNED"},
  {"id": "AUTH-015", "task": "Implement rate limiting for login", "phase": 5, "priority": "P0", "depends_on": ["RATE-001"], "status": "PLANNED"},
  {"id": "USER-001", "task": "Create user management module", "phase": 4, "priority": "P1", "depends_on": ["AUTH-003"], "status": "PLANNED"},
  {"id": "USER-002", "task": "Implement user list/detail API", "phase": 4, "priority": "P1", "depends_on": ["USER-001"], "status": "PLANNED"},
  {"id": "USER-003", "task": "Implement create user (admin)", "phase": 4, "priority": "P1", "depends_on": ["AUTH-001"], "status": "PLANNED"},
  {"id": "USER-004", "task": "Implement update user", "phase": 4, "priority": "P1", "depends_on": ["USER-002"], "status": "PLANNED"},
  {"id": "USER-005", "task": "Implement soft delete user", "phase": 4, "priority": "P2", "depends_on": ["USER-001"], "status": "PLANNED"},
  {"id": "USER-006", "task": "Implement activate/deactivate", "phase": 4, "priority": "P1", "depends_on": ["USER-003"], "status": "PLANNED"},
  {"id": "USER-007", "task": "Implement lock/unlock", "phase": 4, "priority": "P1", "depends_on": ["USER-001"], "status": "PLANNED"},
  {"id": "USER-008", "task": "Implement force password change", "phase": 4, "priority": "P1", "depends_on": ["AUTH-014"], "status": "PLANNED"},
  {"id": "USER-009", "task": "Implement admin reset password", "phase": 4, "priority": "P1", "depends_on": ["AUTH-013"], "status": "PLANNED"},
  {"id": "PWD-001", "task": "Define IM8 password policy", "phase": 5, "priority": "P1", "depends_on": ["P0-001"], "status": "PLANNED"},
  {"id": "PWD-002", "task": "Implement password validation rule", "phase": 5, "priority": "P0", "depends_on": ["PWD-001"], "status": "PLANNED"},
  {"id": "PWD-003", "task": "Implement password history", "phase": 5, "priority": "P1", "depends_on": ["PWD-002"], "status": "PLANNED"},
  {"id": "PWD-004", "task": "Implement password expiration", "phase": 5, "priority": "P1", "depends_on": ["PWD-002"], "status": "PLANNED"},
  {"id": "PWD-005", "task": "Implement admin password reset", "phase": 4, "priority": "P1", "depends_on": ["AUTH-013"], "status": "PLANNED"},
  {"id": "PWD-006", "task": "Implement temp password + force change", "phase": 4, "priority": "P1", "depends_on": ["USER-003"], "status": "PLANNED"},
  {"id": "RBAC-001", "task": "Seed roles (superadmin, admin, user)", "phase": 6, "priority": "P0", "depends_on": ["DB-002"], "status": "PLANNED"},
  {"id": "RBAC-002", "task": "Implement role management", "phase": 6, "priority": "P0", "depends_on": ["RBAC-001"], "status": "PLANNED"},
  {"id": "RBAC-003", "task": "Implement permission management", "phase": 6, "priority": "P0", "depends_on": ["RBAC-001"], "status": "PLANNED"},
  {"id": "RBAC-004", "task": "Define permission set", "phase": 6, "priority": "P0", "depends_on": ["P0-004"], "status": "PLANNED"},
  {"id": "RBAC-005", "task": "Implement superadmin protection", "phase": 6, "priority": "P0", "depends_on": ["RBAC-001"], "status": "PLANNED"},
  {"id": "FEAT-001", "task": "Implement feature flags backend", "phase": 7, "priority": "P1", "depends_on": ["RBAC-005"], "status": "PLANNED"},
  {"id": "FEAT-002", "task": "Implement feature availability enforcement", "phase": 7, "priority": "P1", "depends_on": ["FEAT-001"], "status": "PLANNED"},
  {"id": "SET-001", "task": "Design settings schema", "phase": 8, "priority": "P1", "depends_on": ["DB-002"], "status": "PLANNED"},
  {"id": "SET-002", "task": "Implement settings CRUD", "phase": 8, "priority": "P1", "depends_on": ["SET-001"], "status": "PLANNED"},
  {"id": "SET-003", "task": "Implement settings validation", "phase": 8, "priority": "P1", "depends_on": ["SET-002"], "status": "PLANNED"},
  {"id": "SET-004", "task": "Implement settings audit", "phase": 8, "priority": "P1", "depends_on": ["SET-002"], "status": "PLANNED"},
  {"id": "SET-005", "task": "Implement settings cache invalidation", "phase": 8, "priority": "P1", "depends_on": ["SET-002", "CACHE-002"], "status": "PLANNED"},
  {"id": "NOTIF-001", "task": "Configure mail", "phase": 9, "priority": "P1", "depends_on": ["FOUND-003"], "status": "PLANNED"},
  {"id": "NOTIF-002", "task": "Implement notification channels", "phase": 9, "priority": "P1", "depends_on": ["NOTIF-001"], "status": "PLANNED"},
  {"id": "NOTIF-003", "task": "Queue email sending", "phase": 9, "priority": "P1", "depends_on": ["QUEUE-001"], "status": "PLANNED"},
  {"id": "AUDIT-001", "task": "Integrate audit package", "phase": 10, "priority": "P0", "depends_on": ["RBAC-005"], "status": "PLANNED"},
  {"id": "AUDIT-002", "task": "Create audit abstraction layer", "phase": 10, "priority": "P0", "depends_on": ["AUDIT-001"], "status": "PLANNED"},
  {"id": "AUDIT-003", "task": "Implement audit recording in Actions", "phase": 10, "priority": "P0", "depends_on": ["AUDIT-002"], "status": "PLANNED"},
  {"id": "AUDIT-004", "task": "Implement audit view/detail API", "phase": 10, "priority": "P1", "depends_on": ["AUDIT-002"], "status": "PLANNED"},
  {"id": "AUDIT-005", "task": "Implement async audit export", "phase": 10, "priority": "P2", "depends_on": ["AUDIT-002", "QUEUE-001"], "status": "PLANNED"},
  {"id": "RATE-001", "task": "Define rate limit config", "phase": 5, "priority": "P0", "depends_on": ["SET-001"], "status": "PLANNED"},
  {"id": "RATE-002", "task": "Implement rate limiting middleware", "phase": 5, "priority": "P0", "depends_on": ["RATE-001", "FOUND-003"], "status": "PLANNED"},
  {"id": "CACHE-001", "task": "Define cache config", "phase": 1, "priority": "P1", "depends_on": ["FOUND-003"], "status": "PLANNED"},
  {"id": "CACHE-002", "task": "Implement cache tagging & invalidation", "phase": 1, "priority": "P1", "depends_on": ["CACHE-001"], "status": "PLANNED"},
  {"id": "QUEUE-001", "task": "Configure queue (database + Redis compat)", "phase": 1, "priority": "P0", "depends_on": ["FOUND-003", "DB-001"], "status": "PLANNED"},
  {"id": "DB-001", "task": "Create base migration scaffold", "phase": 2, "priority": "P0", "depends_on": ["FOUND-002"], "status": "PLANNED"},
  {"id": "DB-002", "task": "Create seed data (roles, permissions)", "phase": 2, "priority": "P0", "depends_on": ["DB-001", "RBAC-001"], "status": "PLANNED"},
  {"id": "API-001", "task": "Define API v1 routes", "phase": 12, "priority": "P0", "depends_on": ["SET-003", "AUDIT-004"], "status": "PLANNED"},
  {"id": "API-002", "task": "Implement API resources (v1)", "phase": 12, "priority": "P0", "depends_on": ["API-001"], "status": "PLANNED"},
  {"id": "API-003", "task": "Generate OpenAPI documentation", "phase": 12, "priority": "P1", "depends_on": ["API-002"], "status": "PLANNED"},
  {"id": "SEC-001", "task": "Implement security headers", "phase": 13, "priority": "P0", "depends_on": ["FOUND-003"], "status": "PLANNED"},
  {"id": "SEC-002", "task": "Implement CSRF/CORS (web)", "phase": 13, "priority": "P0", "depends_on": ["SEC-001"], "status": "PLANNED"},
  {"id": "SEC-003", "task": "Implement error handling (consistent JSON)", "phase": 13, "priority": "P0", "depends_on": ["SEC-001"], "status": "PLANNED"},
  {"id": "STOR-001", "task": "Define storage disks (local, public, tmp)", "phase": 14, "priority": "P1", "depends_on": ["FOUND-003"], "status": "PLANNED"},
  {"id": "STOR-002", "task": "Implement audit export storage (private)", "phase": 14, "priority": "P1", "depends_on": ["STOR-001", "AUDIT-005"], "status": "PLANNED"},
  {"id": "BACKUP-001", "task": "Define backup strategy", "phase": 14, "priority": "P1", "depends_on": ["STOR-001"], "status": "PLANNED"},
  {"id": "RETAIN-001", "task": "Implement retention policy jobs", "phase": 14, "priority": "P1", "depends_on": ["DB-001"], "status": "PLANNED"},
  {"id": "MONITOR-001", "task": "Integrate Telescope", "phase": 11, "priority": "P1", "depends_on": ["FOUND-007"], "status": "PLANNED"},
  {"id": "MONITOR-002", "task": "Implement health check endpoint", "phase": 11, "priority": "P1", "depends_on": ["FOUND-010"], "status": "PLANNED"},
  {"id": "CORR-001", "task": "Implement correlation ID middleware", "phase": 1, "priority": "P0", "depends_on": ["FOUND-008"], "status": "PLANNED", "tests": ["TEST-API-003"], "docs": ["logging.md"]},
  {"id": "INACT-001", "task": "Implement inactivity tracking", "phase": 5, "priority": "P1", "depends_on": ["USER-001"], "status": "PLANNED"}
]
```