# Dependency Map

## Feature-Level Dependencies

```
Laravel Foundation (Phase 1)
    ↓
Database Foundation (Phase 2)
    ↓
Authentication (Phase 3)
    ↓
User Lifecycle (Phase 4)
    ↓
Password/Security (Phase 5)
    ↓
RBAC & Authorization (Phase 6)
    ↓
Feature Availability (Phase 7)
    ↓
Settings (Phase 8)
    ↓
Notifications (Phase 9)
    ↓
Audit Trail (Phase 10)
    ↓
API V1 (Phase 12)
    ↓
Security Hardening (Phase 13)
    ↓
Storage/Backup/Retention (Phase 14)
    ↓
Testing (Phase 15)
```

## Detailed Dependency Graph

### Foundation Layer
```
FOUND-001 (Laravel install)
├── FOUND-002 (.env config)
├── FOUND-003 (config files)
├── FOUND-004 (Sanctum)
├── FOUND-005 (Spatie Permission)
├── FOUND-006 (Audit package)
├── FOUND-007 (Telescope)
├── FOUND-008 (Correlation ID middleware)
├── FOUND-009 (PSR-12 linting)
└── FOUND-010 (Health check)
```

### Database Layer
```
FOUND-001 → FOUND-002 → DB-001 (migrations) → DB-002 (seed data)
                                      ↓
                               RBAC-001 (seed roles)
```

### Authentication Layer
```
FOUND-004 (Sanctum) → AUTH-002 (Sanctum config)
FOUND-008 (Correlation ID) → CORR-001 (middleware)
DB-002 (seed) → AUTH-003 (auth model)
AUTH-001 (requirements) → AUTH-004 (login validation) → AUTH-005 (login action) → AUTH-006 (session creation)
AUTH-006 → AUTH-009 (logout current)
AUTH-006 → AUTH-011 (email verification)
AUTH-001 → AUTH-012 (forgot password) → AUTH-013 (password reset)
```

### User Management Layer
```
AUTH-003 (auth model) → USER-001 (user management) → USER-002 (list/detail) → USER-004 (update)
USER-001 → USER-005 (soft delete)
USER-001 → USER-006 (activate/deactivate)
USER-001 → USER-007 (lock/unlock)
USER-003 (create) → USER-008 (force password change)
USER-003 → USER-009 (admin reset)
USER-003 → PWD-006 (temp password + force change)
```

### Password/Security Layer
```
P0-001 (principles) → PWD-001 (IM8 policy) → PWD-002 (validation) → PWD-003 (history) → PWD-004 (expiration)
PWD-002 → AUTH-014 (password change)
AUTH-005 (login action) → AUTH-007 (failed login tracking) → AUTH-008 (temp lock)
SET-001 (settings) → RATE-001 (rate limit config) → RATE-002 (rate limit middleware)
RATE-002 → AUTH-015 (login rate limiting)
USER-001 → INACT-001 (inactivity tracking)
```

### RBAC Layer
```
DB-002 (seed) → RBAC-001 (roles) → RBAC-005 (superadmin protection)
RBAC-001 → RBAC-002 (role management)
RBAC-001 → RBAC-003 (permission management)
RBAC-001 → RBAC-004 (define permission set)
RBAC-005 → FEAT-001 (feature flags)
RBAC-005 → AUDIT-001 (audit package)
AUDIT-001 → AUDIT-002 (abstraction) → AUDIT-003 (recording) → AUDIT-004 (view API)
AUDIT-002 + QUEUE-001 → AUDIT-005 (async export)
```

### Settings & Cache Layer
```
DB-001 → SET-001 (settings schema) → SET-002 (CRUD) → SET-003 (validation) → SET-004 (audit) → SET-005 (cache invalidation)
CACHE-001 (config) → CACHE-002 (tagging) → SET-005 (cache invalidation)
QUEUE-001 (queue) → NOTIF-003 (queue email)
```

### Infrastructure Layer
```
FOUND-003 → CACHE-001 → CACHE-002
FOUND-003 → NOTIF-001 (mail config) → NOTIF-002 (channels) → NOTIF-003 (queue email)
FOUND-010 → MONITOR-002 (health check)
STOR-001 → STOR-002 (audit export storage)
BACKUP-001 → STOR-001 (backup strategy)
DB-001 → RETAIN-001 (retention jobs)
AUDIT-005 → STOR-002 (async export)
```

### API & Security Layer
```
SET-003 → API-001 (routes) → API-002 (resources) → API-003 (docs)
SEC-001 → SEC-002 → SEC-003 (security headers + CSRF/CORS + error handling)
```

## Circular Dependencies Check

No circular dependencies identified. All flows are unidirectional from foundation → features → API → hardening.

## Critical Paths

1. **Authentication**: `FOUND → DB → AUTH → USER` (no shortcuts)
2. **RBAC**: `DB → RBAC → AUDIT` (RBAC protects audit)
3. **API**: `RBAC → SETTINGS → AUDIT → API` (API gates need all three)
4. **Security**: `FOUND → RBAC → SEC` (security headers + auth)

## Blocking Tasks

| Task | Blocked By | Impact |
|------|-----------|--------|
| DB-002 (seed) | DB-001, RBAC-001 | Blocks all auth/user tasks |
| AUTH-003 (model) | DB-002 | Blocks all auth flows |
| RBAC-005 (superadmin) | RBAC-001 | Blocks feature flags, audit |
| SET-001 (settings) | DB-001 | Blocks rate limiting, password policy |
| CORR-001 (correlation ID) | FOUND-008 | Blocks logging, audit correlation