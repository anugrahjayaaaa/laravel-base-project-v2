# Feature Matrix

## Feature Coverage

| Feature | Description | Section | Phase | Priority | Docs |
|---------|-------------|---------|-------|----------|------|
| Login (username/email) | Authenticate via username or email | #5 | 3 | P0 | [authentication.md](../base/security/authentication.md) |
| Email verification | Verify email ownership | #5, #15 | 3 | P0 | [authentication.md](../base/security/authentication.md) |
| Forgot password | Request password reset | #5 | 3 | P0 | [password-security.md](../base/security/password-security.md) |
| Password reset | Reset via email link | #5 | 3 | P0 | [password-security.md](../base/security/password-security.md) |
| Password change | User-initiated change | #5 | 5 | P0 | [password-security.md](../base/security/password-security.md) |
| Admin reset password | Admin resets user password | #6 | 4 | P1 | [password-security.md](../base/security/password-security.md) |
| Initial password gen | Temp password on creation | #6 | 4 | P1 | [password-security.md](../base/security/password-security.md) |
| Force password change | After initial creation | #6 | 4 | P1 | [password-security.md](../base/security/password-security.md) |
| Password expiration | Configurable expiry | #9 | 5 | P1 | [password-security.md](../base/security/password-security.md) |
| Password history | Prevent reuse | #9 | 5 | P1 | [password-security.md](../base/security/password-security.md) |
| Password policy (IM8) | Password strength | #9 | 5 | P1 | [password-security.md](../base/security/password-security.md) |
| Logout current device | Revoke current session | #6 | 3 | P0 | [session-security.md](../base/security/session-security.md) |
| Logout all devices | Revoke all sessions | #6 | 3 | P1 | [session-security.md](../base/security/session-security.md) |
| Session/token expiration | Configurable expiry | #16 | 3 | P0 | [session-security.md](../base/security/session-security.md) |
| Account lock | Security lock | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Account unlock | Unlock | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Account activation | Activate account | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Account deactivation | Deactivate account | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Failed login tracking | Track failed attempts | #8 | 5 | P0 | [authentication.md](../base/security/authentication.md) |
| Failed login temp lock | After excessive failures | #8 | 5 | P0 | [authentication.md](../base/security/authentication.md) |
| Inactivity lock | After inactivity period | #7 | 5 | P1 | [user-management.md](../base/features/user-management.md) |
| User list/detail | CRUD operations | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Create user | Admin creation | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Update user | Edit user | #6 | 4 | P1 | [user-management.md](../base/features/user-management.md) |
| Soft delete user | Soft delete | #6 | 4 | P2 | [user-management.md](../base/features/user-management.md) |
| Registration | Configurable public | #11 | 3 | P1 | [registration.md](../base/features/registration.md) |
| RBAC roles | Role management | #12 | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
| RBAC permissions | Permission management | #12 | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
| Superadmin protection | Protect last superadmin | #13 | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
| Feature flags | Backend enforcement | #14 | 7 | P1 | [feature-flags.md](../base/features/feature-flags.md) |
| Settings management | Configurable settings | #24 | 8 | P1 | [settings.md](../base/features/settings.md) |
| Audit trail | View/list/search/export | #21 | 10 | P0 | [audit-trail.md](../base/features/audit-trail.md) |
| Notifications | Email/database notifications | #48 | 9 | P1 | [notifications.md](../base/features/notifications.md) |
| Queue | Database queue w/ Redis compat | #18 | 1 | P0 | [queue.md](../base/infrastructure/queue.md) |
| Cache | Cache abstraction | #254 | P1 | P1 | [cache.md](../base/infrastructure/cache.md) |
| Rate limiting | Per-endpoint limits | #17 | 5 | P0 | [rate-limiting.md](../base/security/rate-limiting.md) |
| API v1 | Versioned API | #25 | 12 | P0 | [api-architecture.md](../base/api/api-architecture.md) |
| API documentation | OpenAPI docs | #27 | 12 | P1 | [documentation.md](../base/api/documentation.md) |
| Monitoring | Audit/Logs/Telescope/Health | #22 | 11 | P1 | [monitoring.md](../base/features/monitoring.md) |
| Backup/DR | Backup & restore | #35 | 14 | P1 | [backup-disaster-recovery.md](../base/infrastructure/backup-disaster-recovery.md) |
| Storage abstraction | Private/public/tmp storage | #32 | 14 | P1 | [storage.md](../base/infrastructure/storage.md) |
| Data retention | Configurable retention | #36 | 14 | P1 | [retention.md](../base/operations/retention.md) |
| Correlation ID | Request tracing | #23 | 3 | P0 | [logging.md](../base/infrastructure/logging.md) |