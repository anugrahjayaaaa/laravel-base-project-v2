# Testing Matrix

## Coverage Matrix

| Component | Unit | Feature | API | Auth | Authorization | Security | Validation | DB | Queue | Events | Notification | Policy | Middleware | Concurrency | Regression |
|-----------|------|---------|-----|------|---------------|----------|-----------|-----|-------|--------|--------------|--------|------------|-------------|------------|
| Login | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | | | ✓ | | | ✓ |
| Logout | | ✓ | ✓ | ✓ | | ✓ | | | | | | ✓ | | | ✓ |
| Logout all devices | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Forgot password | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Password reset | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | ✓ | ✓ | | ✓ | | | ✓ |
| Email verification | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | ✓ | | ✓ | | | ✓ |
| Session management | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Token management | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Failed login protection | | ✓ | ✓ | ✓ | | ✓ | | | | | | ✓ | | | ✓ |
| Registration | | ✓ | ✓ | ✓ | | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| User list | | | ✓ | | | | | ✓ | | | | | ✓ | | ✓ |
| User detail | | | ✓ | | | | | ✓ | | | | | ✓ | | ✓ |
| Create user | | | ✓ | | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | ✓ | | ✓ |
| Update user | | | ✓ | | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | ✓ | | ✓ |
| Activate/Deactivate | | | ✓ | | ✓ | ✓ | | ✓ | | ✓ | | | ✓ | | | ✓ |
| Lock/Unlock | | | ✓ | | ✓ | ✓ | | ✓ | | ✓ | | | ✓ | | | ✓ |
| Force password change | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| RBAC roles | ✓ | | | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| RBAC permissions | ✓ | | | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| Superadmin protection | | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | | ✓ | | | ✓ |
| Feature flags | ✓ | ✓ | ✓ | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| Audit trail | | | ✓ | | ✓ | | | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| Settings | ✓ | ✓ | | | ✓ | | ✓ | ✓ | | | | ✓ | | | ✓ |
| Notifications | | | | | | | | | ✓ | | ✓ | | | | ✓ |
| Mail | | ✓ | | | | | | | ✓ | | ✓ | | | | ✓ |
| Queue jobs | ✓ | ✓ | | | | | | ✓ | ✓ | | | | | ✓ | ✓ |
| Events/listeners | ✓ | ✓ | | | | | | | ✓ | ✓ | | | | | ✓ |
| Rate limiting | | ✓ | ✓ | | ✓ | ✓ | | | | | | ✓ | ✓ | | ✓ |
| Security headers | | ✓ | ✓ | | | ✓ | | | | | | ✓ | | | ✓ |
| Validation rules | ✓ | | | | | | ✓ | | | | | | | | ✓ |
| Error handling | | | ✓ | | | | | | | | | | | | ✓ |
| API versioning | | | ✓ | | | | | | | | | | | | ✓ |

## Key

- ✓ = Test required
- Empty = Not applicable
- Some tests span multiple categories (marked with multiple checks)

## Notes

- Authentication tests cover login, logout, session, token flows
- Authorization tests cover RBAC, policies, superadmin protection
- Security tests cover input validation, escaping, auth boundary checks
- Concurrency tests for rate limiting, failed login tracking, password history