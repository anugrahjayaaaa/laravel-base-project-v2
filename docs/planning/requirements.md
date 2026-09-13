# Requirements

## Functional Requirements

| ID | Requirement | Section | Priority |
|----|-------------|---------|----------|
| REQ-AUTH-001 | Login using username OR email | #5 | P0 |
| REQ-AUTH-002 | Email verification | #5, #15 | P0 |
| REQ-AUTH-003 | Forgot password | #5 | P0 |
| REQ-AUTH-004 | Password reset | #5 | P0 |
| REQ-AUTH-005 | User-initiated password change | #5 | P0 |
| REQ-AUTH-006 | Admin-triggered password reset | #5 | P0 |
| REQ-AUTH-007 | Initial password generation during admin user creation | #6 | P1 |
| REQ-AUTH-008 | Forced password change after initial account creation | #6 | P1 |
| REQ-AUTH-009 | Password expiration | #9, #27 | P1 |
| REQ-AUTH-010 | Password history | #9, #27 | P1 |
| REQ-AUTH-011 | Password policy (IM8) | #9 | P1 |
| REQ-AUTH-012 | Session/token expiration | #6, #16 | P0 |
| REQ-AUTH-013 | Logout current device | #6, #16 | P0 |
| REQ-AUTH-014 | Logout all devices | #6, #16 | P1 |
| REQ-AUTH-015 | Session invalidation | #5, #16 | P0 |
| REQ-AUTH-016 | Account lock | #6, #16 | P1 |
| REQ-AUTH-017 | Account unlock | #6, #16 | P1 |
| REQ-AUTH-018 | Account activation | #6 | P1 |
| REQ-AUTH-019 | Account deactivation | #6 | P1 |
| REQ-AUTH-020 | Failed login tracking | #8 | P0 |
| REQ-AUTH-021 | Temporary lock after excessive failed logins | #8 | P0 |
| REQ-AUTH-022 | Rate limiting | #8, #17 | P0 |
| REQ-USER-001 | User list/detail | #6 | P1 |
| REQ-USER-002 | Create user (admin) | #6 | P1 |
| REQ-USER-003 | Update user | #6 | P1 |
| REQ-USER-004 | Soft delete user | #6 | P2 |
| REQ-USER-005 | Activate/deactivate | #6 | P1 |
| REQ-USER-006 | Lock/unlock | #6 | P1 |
| REQ-USER-007 | Force password change | #6 | P1 |
| REQ-USER-008 | Admin reset password | #6 | P1 |
| REQ-USER-009 | Inactivity-based lock | #7 | P1 |
| REQ-REG-001 | Configurable public registration | #11 | P1 |
| REQ-REG-002 | Default role assignment | #11 | P0 |
| REQ-RBAC-001 | Role permission auto-propagation | #12 | P0 |
| REQ-RBAC-002 | Superadmin protection | #13 | P0 |
| REQ-RBAC-003 | Permission: users.activate | #6 | P1 |
| REQ-RBAC-004 | Permission: users.deactivate | #6 | P1 |
| REQ-RBAC-005 | Permission: users.lock/unlock | #6 | P1 |
| REQ-FEAT-001 | Feature flags backend enforcement | #14 | P1 |
| REQ-AUDIT-001 | Audit trail (view/detail/search/export) | #21, #47 | P0 |
| REQ-AUDIT-002 | Asynchronous audit export | #21 | P2 |
| REQ-SET-001 | Configurable settings management | #24 | P1 |
| REQ-SET-002 | Settings validation | #24 | P1 |
| REQ-SET-003 | Settings audit | #24 | P1 |
| REQ-NOTIF-001 | Email notifications | #48 | P1 |
| REQ-API-001 | Versioned API v1 | #25 | P0 |
| REQ-API-002 | API documentation (OpenAPI) | #27 | P1 |
| REQ-API-003 | Consistent error handling | #28 | P0 |

## Non-Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| REQ-NFR-001 | API-first design | P0 |
| REQ-NFR-002 | UI-independent core | P0 |
| REQ-NFR-003 | Secure by default | P0 |
| REQ-NFR-004 | Testable | P0 |
| REQ-NFR-005 | Redis-compatible | P0 |
| REQ-NFR-006 | Configurable | P1 |
| REQ-NFR-007 | Documented | P0 |
| REQ-NFR-008 | Extensible | P1 |

## Scope Boundaries (Must NOT Include)

- Plan system
- License system
- Billing
- Subscription
- Project-specific business logic
- Domain modules
- Customer-specific workflows