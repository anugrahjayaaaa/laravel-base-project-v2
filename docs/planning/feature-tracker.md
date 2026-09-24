# Feature Tracker — Laravel Base Project v2

Auto-generated from codebase scan. Not committed.

## Feature Matrix

| # | Feature | Web | API | Auth | Docs | Status |
|---|---------|-----|-----|------|------|--------|
| 1 | Login (username/email) | ✅ | ✅ | — | security/authentication.md | done |
| 2 | Email verification | ✅ | ✅ | — | security/authentication.md | done |
| 3 | Forgot password | ✅ | ✅ | — | security/password-security.md | done |
| 4 | Password reset | ✅ | ✅ | — | security/password-security.md | done |
| 5 | Password change (self) | ✅ | ✅ | own | security/password-security.md | done |
| 6 | Password change (expired bypass) | — | ✅ | sanctum | — | done |
| 7 | Logout current device | ✅ | — | own | security/session-security.md | done |
| 8 | Logout all devices | ✅ | ✅ | own | security/session-security.md | done |
| 9 | Session listing | ✅ | ✅ | own | — | done |
| 10 | Resend verification | ✅ | ✅ | own | — | done |
| 11 | Email change flow | ✅ | — | own | — | done |
| 12 | Failed login tracking + lock | — | — | — | security/authentication.md | done |
| 13 | Rate limiting (per-endpoint) | ✅ | ✅ | — | security/rate-limiting.md | done |
| 14 | Account activate/deactivate/lock/unlock | ✅ | ✅ | admin | features/user-management.md | done |
| 15 | User CRUD | ✅ | ✅ | admin | features/user-management.md | done |
| 16 | Soft delete / force delete / restore | ✅ | ✅ | admin | features/user-management.md | done |
| 17 | Bulk actions | ✅ | ✅ | admin | — | done |
| 18 | Admin resend verification | — | ✅ | admin | — | done |
| 19 | Profile view/edit | ✅ | ✅ | own | — | done |
| 20 | System settings | ✅ | ✅ | admin | features/settings.md | done |
| 21 | Registration (configurable) | ✅ | — | public | features/registration.md | done |
| 22 | RBAC roles & permissions (Spatie) | ✅ | — | — | features/roles-permissions.md | done |
| 23 | Superadmin protection | — | — | — | features/roles-permissions.md | done |
| 24 | Feature flags (DB-backed) | — | — | — | features/feature-flags.md | done |
| 25 | Audit trail (Spatie activitylog) | — | — | — | features/audit-trail.md | done |
| 26 | Notifications (email + DB) | — | — | — | features/notifications.md | done |
| 27 | Health check | ✅ | ✅ | — | features/monitoring.md | done |
| 28 | Telescope | — | — | — | features/monitoring.md | done |
| 29 | Queue (DB + Redis compat) | — | — | — | infrastructure/queue.md | done |
| 30 | Cache abstraction | — | — | — | infrastructure/cache.md | done |
| 31 | API v1 (versioned) | — | ✅ | — | api/api-architecture.md | done |
| 32 | Scramble API docs | — | — | — | api/documentation.md | done |
| 33 | Storage abstraction | — | — | — | infrastructure/storage.md | done |
| 34 | Data retention | — | — | — | operations/retention.md | planned |
| 35 | Backup/DR | — | — | — | infrastructure/backup-disaster-recovery.md | planned |
| 36 | Correlation ID tracing | ✅ | ✅ | — | infrastructure/logging.md | done |
| 37 | Password history | — | — | — | security/password-security.md | planned |
| 38 | Password expiration | — | — | — | security/password-security.md | planned |
| 39 | Password policy (IM8) | — | — | — | security/password-security.md | planned |
| 40 | Initial password gen (temp) | — | ✅ | admin | security/password-security.md | planned |
| 41 | Admin reset password | — | ✅ | admin | security/password-security.md | planned |
| 42 | Inactivity lock | — | — | — | features/user-management.md | planned |
| 43 | Theme toggle (UI) | ✅ | — | own | ui/design-system.md | done |
| 44 | Password toggle (UI) | ✅ | — | — | — | done |
| 45 | Blade components (UI primitives) | ✅ | — | — | ui/design-system.md | done |
| 46 | AdminLTE 4 layout | ✅ | — | — | ui/ui-adminlte-setup.md | done |

## Legend
- Web = web routes (routes/web.php)
- API = API routes (routes/api.php)
- Auth = middleware guard: `own` (self), `admin`, `sanctum`, `—` (public)
- Status: done / planned / in-progress
