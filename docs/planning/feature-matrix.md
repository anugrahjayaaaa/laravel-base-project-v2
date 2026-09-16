# Feature Matrix

## Feature Coverage

|| Feature | Description | Phase | Priority | Docs |
||---------|-------------|-------|----------|------|
|| Login (username/email) | Authenticate via username or email | 3 | P0 | [authentication.md](../base/security/authentication.md) |
|| Email verification | Verify email ownership | 3 | P0 | [authentication.md](../base/security/authentication.md) |
|| Forgot password | Request password reset | 3 | P0 | [password-security.md](../base/security/password-security.md) |
|| Password reset | Reset via email link | 3 | P0 | [password-security.md](../base/security/password-security.md) |
|| Password change | User-initiated change | 5 | P0 | [password-security.md](../base/security/password-security.md) |
|| Admin reset password | Admin resets user password | 4 | P1 | [password-security.md](../base/security/password-security.md) |
|| Initial password gen | Temp password on creation | 4 | P1 | [password-security.md](../base/security/password-security.md) |
|| Force password change | After initial creation | 4 | P1 | [password-security.md](../base/security/password-security.md) |
|| Password expiration | Configurable expiry | 5 | P1 | [password-security.md](../base/security/password-security.md) |
|| Password history | Prevent reuse | 5 | P1 | [password-security.md](../base/security/password-security.md) |
|| Password policy (IM8) | Password strength | 5 | P1 | [password-security.md](../base/security/password-security.md) |
|| Logout current device | Revoke current session | 3 | P0 | [session-security.md](../base/security/session-security.md) |
|| Logout all devices | Revoke all sessions | 3 | P1 | [session-security.md](../base/security/session-security.md) |
|| Session/token expiration | Configurable expiry | 3 | P0 | [session-security.md](../base/security/session-security.md) |
|| Account lock | Security lock | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Account unlock | Unlock | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Account activation | Activate account | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Account deactivation | Deactivate account | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Failed login tracking | Track failed attempts | 5 | P0 | [authentication.md](../base/security/authentication.md) |
|| Failed login temp lock | After excessive failures | 5 | P0 | [authentication.md](../base/security/authentication.md) |
|| Inactivity lock | After inactivity period | 5 | P1 | [user-management.md](../base/features/user-management.md) |
|| User list/detail | CRUD operations | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Create user | Admin creation | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Update user | Edit user | 4 | P1 | [user-management.md](../base/features/user-management.md) |
|| Soft delete user | Soft delete | 4 | P2 | [user-management.md](../base/features/user-management.md) |
|| Registration | Configurable public | 3 | P1 | [registration.md](../base/features/registration.md) |
|| RBAC roles | Role management | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
|| RBAC permissions | Permission management | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
|| Superadmin protection | Protect last superadmin | 6 | P0 | [roles-permissions.md](../base/features/roles-permissions.md) |
|| Feature flags | Backend enforcement | 7 | P1 | [feature-flags.md](../base/features/feature-flags.md) |
|| Settings management | Configurable settings | 8 | P1 | [settings.md](../base/features/settings.md) |
|| Audit trail | View/list/search/export | 10 | P0 | [audit-trail.md](../base/features/audit-trail.md) |
|| Notifications | Email/database notifications | 9 | P1 | [notifications.md](../base/features/notifications.md) |
|| Queue | Database queue w/ Redis compat | 1 | P0 | [queue.md](../base/infrastructure/queue.md) |
|| Cache | Cache abstraction | 1 | P1 | [cache.md](../base/infrastructure/cache.md) |
|| Rate limiting | Per-endpoint limits | 5 | P0 | [rate-limiting.md](../base/security/rate-limiting.md) |
|| API v1 | Versioned API | 12 | P0 | [api-architecture.md](../base/api/api-architecture.md) |
|| API documentation | Scramble docs | 12 | P1 | [documentation.md](../base/api/documentation.md) |
|| Monitoring | Audit/Logs/Periscope/Telescope/Health | 11 | P1 | [monitoring.md](../base/features/monitoring.md) |
|| Backup/DR | Backup & restore | 14 | P1 | [backup-disaster-recovery.md](../base/infrastructure/backup-disaster-recovery.md) |
|| Storage abstraction | Private/public/tmp storage | 14 | P1 | [storage.md](../base/infrastructure/storage.md) |
|| Data retention | Configurable retention | 14 | P1 | [retention.md](../base/operations/retention.md) |
|| Correlation ID | Request tracing | 3 | P0 | [logging.md](../base/infrastructure/logging.md) |