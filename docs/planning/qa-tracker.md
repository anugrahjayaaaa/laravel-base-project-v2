# QA Tracker

> Separate from implementation status. Each feature should eventually have all QA categories listed below.

## QA Categories

|| Category | Description |
||----------|-------------|
|| Functional QA | Does the feature work as specified? |
|| Authorization QA | Are permissions properly enforced? |
|| Security QA | Input validation, escaping, auth boundary, secrets |
|| API QA | Response format, error handling, pagination, filtering |
|| Regression QA | Does this change break existing functionality? |
|| Documentation QA | Docs match code, accurate and complete? |

## QA Test Scenarios

### Authentication QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-AUTH-001 | Verify login using username | Functional | Login | PLANNED |
|| QA-AUTH-002 | Verify login using email | Functional | Login | PLANNED |
|| QA-AUTH-003 | Verify invalid credentials returns 401 | API | Login | PLANNED |
|| QA-AUTH-004 | Verify rate limit on login (5/min per IP+identifier) | Security | Login | PLANNED |
|| QA-AUTH-005 | Verify temporary lock after 5 failed attempts | Security | Failed login | PLANNED |
|| QA-AUTH-006 | Verify failed login counter resets on success | Functional | Failed login | PLANNED |
|| QA-AUTH-007 | Verify locked account cannot login | Authorization | Lock | PLANNED |
|| QA-AUTH-008 | Verify unlock allows login again | Functional | Unlock | PLANNED |
|| QA-AUTH-009 | Verify logout current device revokes session | Functional | Logout | PLANNED |
|| QA-AUTH-010 | Verify logout all devices revokes all sessions | Security | Logout all | PLANNED |
|| QA-AUTH-011 | Verify email verification flow | Functional | Email verification | PLANNED |
|| QA-AUTH-012 | Verify email verification ≠ account activation | Security | Email verification | PLANNED |
|| QA-AUTH-013 | Verify forgot password sends reset email | Functional | Forgot password | PLANNED |
|| QA-AUTH-014 | Verify password reset via email link | Functional | Password reset | PLANNED |
|| QA-AUTH-015 | Verify reset token expires after 60 min | Security | Password reset | PLANNED |
|| QA-AUTH-016 | Verify rate limit on forgot password (3/hour) | Security | Forgot password | PLANNED |
|| QA-AUTH-017 | Verify admin reset password generates secure link | Functional | Admin reset | PLANNED |
|| QA-AUTH-018 | Verify admin cannot see user's new password | Security | Admin reset | PLANNED |
|| QA-AUTH-019 | Verify password change requires current password | Authorization | Password change | PLANNED |
|| QA-AUTH-020 | Verify password change respects history | Security | Password history | PLANNED |
||| QA-AUTH-021 | Verify last_activity_at set on first successful login | Functional | last_activity_at | TODO |
||| QA-AUTH-022 | Verify last_activity_at is NULL for never-logged-in users | Functional | last_activity_at | TODO |
||| QA-AUTH-023 | Verify unlock does NOT update last_activity_at (NULL preserved) | Security | Unlock | TODO |
||| QA-AUTH-024 | Verify unlock does NOT update last_activity_at (existing timestamp preserved) | Security | Unlock | TODO |
||| QA-AUTH-025 | Verify last_activity_at updates only on meaningful activity, not every request | Functional | last_activity_at | TODO |
|||| QA-AUTH-026 | Verify NULL last_activity_at users included in inactivity query via grace_days | Functional | Inactivity | DONE |
|||| QA-AUTH-027 | Verify password change revokes existing sessions/tokens | Security | Password change | DONE — all Sanctum tokens, database sessions, and remember_token are cleared in the password mutation transaction |

### User Management QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-USER-001 | Verify user list is paginated | API | User list | PLANNED |
|| QA-USER-002 | Verify user detail shows correct data | Functional | User detail | PLANNED |
|| QA-USER-003 | Verify create user generates temp password | Functional | Create user | PLANNED |
|| QA-USER-004 | Verify new user forced to change password on first login | Security | Force change | PLANNED |
|| QA-USER-005 | Verify deactivate prevents login | Authorization | Deactivate | PLANNED |
|| QA-USER-006 | Verify activate restores login | Functional | Activate | PLANNED |
|| QA-USER-007 | Verify lock prevents login but allows unlock | Authorization | Lock | PLANNED |
|| QA-USER-008 | Verify soft delete hides user but preserves data | Functional | Soft delete | PLANNED |
|| QA-USER-009 | Verify non-admin cannot access user management | Authorization | RBAC | PLANNED |
|| QA-USER-010 | Verify password history prevents reuse | Security | Password history | PLANNED |
|| QA-USER-011 | Verify profile update with valid data succeeds | Functional | Profile | PLANNED |
|| QA-USER-012 | Verify profile update with invalid email rejected | Validation | Profile | PLANNED |
|| QA-USER-013 | Verify change password requires current password | Authorization | Profile | PLANNED |
|| QA-USER-014 | Verify user list respects @can authorization | Authorization | User mgmt | PLANNED |

### RBAC QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-RBAC-001 | Verify role permission changes propagate to users | Functional | Role permissions | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_the_role_change_is_audited_with_before_and_after` — edit a user's roles, confirm the `user.roles_assigned` activity row records before AND after. Manual: change a user's role, check the audit log
|| QA-RBAC-002 | Verify user inherits role permissions | Functional | Role permissions | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_changing_the_role_changes_what_the_account_can_do` + `test_the_sync_writes_nothing_to_the_user_permission_pivot` — the role change moves `can()` and leaves `model_has_permissions` at 0 rows (ADR-004). Manual: give a user `staff`, confirm they gain users.view with no direct permission row
|| QA-RBAC-003 | Verify cannot delete last superadmin | Security | Superadmin | DONE | DONE (2026-09-30) — `LastSuperadminGuardTest::test_the_last_superadmin_cannot_be_removed` (soft-deleted case). NOTE: this was BROKEN until Group E — deleting the account bypassed the role guard entirely; confirmed exploitable down to zero superadmins, now fixed. Manual: as a non-superadmin holding users.delete, try to delete the only superadmin — refused, account intact
|| QA-RBAC-004 | Verify cannot deactivate last superadmin | Security | Superadmin | DONE | DONE (2026-09-30) — `LastSuperadminGuardTest` deactivated case + `test_the_bulk_path_cannot_remove_the_last_superadmin` + `test_the_api_cannot_deactivate_the_last_superadmin` (409 LAST_SUPERADMIN). NOTE: also BROKEN until Group E, and the bulk bar bypassed the action via a raw UPDATE. Manual: deactivate the only superadmin via row button, bulk dropdown and API — all three refused
|| QA-RBAC-005 | Verify superadmin bypasses auth where explicitly allowed | Authorization | Superadmin | DONE | DONE (2026-09-30) — Sidebar/route cases in `GateDUiGatingTest` + `RbacAuthorizationMatrixTest` — a superadmin reaches every gated screen. Mechanism is `Gate::before` (AuthServiceProvider), documented in `docs/base/security/authorization.md`. Manual: sign in as superadmin, confirm /users, /roles, /permissions, /settings all open
|| QA-RBAC-006 | Verify superadmin does NOT bypass every boundary | Security | Superadmin | DONE | DONE (2026-09-30) — The bypass is scoped, not total: `SuperadminVisibilityTest` (a delegated admin still cannot grant superadmin, even WITH confirm_superadmin), `LastSuperadminGuardTest` (E5 binds superadmins too), `RoleManagementTest::test_a_system_role_permissions_cannot_be_edited` (even a superadmin cannot strip `admin`'s set). Manual: as superadmin, try to demote the last superadmin — refused
|| QA-RBAC-007 | Verify registration default role is 'user' | Authorization | Registration | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_a_create_without_roles_lands_on_the_default_role` — an admin create with no `roles` key lands on the configured default, not on no role. Manual: create a user leaving roles empty, confirm they hold `user`
|| QA-RBAC-008 | Verify public cannot select admin/superadmin role | Security | Registration | DONE | DONE (2026-09-30) — `RegisterTest` + `RbacPentestTest::test_roles_cannot_be_smuggled_through_the_profile_endpoint` — a self-registering account cannot select a role and cannot acquire one later via a mass-assigned field. Manual: register, then PUT /profile with roles[]=superadmin — refused

### API QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-API-001 | Verify all API responses use consistent envelope | API | API v1 | PLANNED |
|| QA-API-002 | Verify 401 for unauthenticated API access | API | API v1 | PLANNED |
|| QA-API-003 | Verify request_id in all responses | Security | API v1 | PLANNED |
|| QA-API-004 | Verify 429 on rate-limited endpoint | API | Rate limiting | PLANNED |
|| QA-API-005 | Verify pagination metadata in collections | API | API v1 | PLANNED |

### Security QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-SEC-001 | Verify HSTS header present | Security | Headers | PLANNED |
|| QA-SEC-002 | Verify CSP header present | Security | Headers | PLANNED |
|| QA-SEC-003 | Verify X-Frame-Options set | Security | Headers | PLANNED |
|| QA-SEC-004 | Verify CSRF token on web forms | Security | CSRF | PLANNED |
|| QA-SEC-005 | Verify no stack traces in production errors | Security | Error handling | PLANNED |
|| QA-SEC-006 | Verify no secrets in error responses | Security | Error handling | PLANNED |
|| QA-SEC-007 | Verify input validation at trust boundaries | Security | Validation | PLANNED |
|| QA-SEC-008 | Verify password hashing (bcrypt/argon2) | Security | Password security | PLANNED |

### Audit QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-AUDIT-001 | Verify audit records created on mutations | Functional | Audit Trail | PLANNED |
||| QA-AUDIT-002 | Verify audit metadata includes IP, UA, request_id | Security | Audit Trail | PLANNED |
||| QA-AUDIT-003 | Verify audit records are read-only in UI | Authorization | Audit Trail | PLANNED |
||| QA-AUDIT-004 | Verify async export works end-to-end | Functional | Audit Export | PLANNED |
||| QA-AUDIT-005 | Verify export file expires after lifecycle | Security | Audit Export | PLANNED |
||| QA-AUDIT-006 | Verify audit records written within transaction before commit | Security | Audit Trail | TODO |
||| QA-AUDIT-007 | Verify rollback produces no audit record | Functional | Audit Trail | TODO |

### Logging QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
|| QA-LOG-001 | Verify failures produce correct log level/event name | Functional | Logging | PLANNED |
|| QA-LOG-002 | Verify unexpected exceptions are logged | Security | Logging | PLANNED |
|| QA-LOG-003 | Verify sensitive data (passwords/tokens/secrets) is not logged | Security | Logging | PLANNED |
|| QA-LOG-004 | Verify request/correlation ID present in log entries | Security | Logging | PLANNED |
|| QA-LOG-005 | Verify audit records created within transaction before commit | Security | Logging | TODO |
|| QA-LOG-006 | Verify failed transactions do not create false successful audit events | Functional | Logging | PLANNED |
|| QA-LOG-007 | Verify queue failures are observable in logs | Security | Logging | PLANNED |
|| QA-LOG-008 | Verify authorization/security failures are appropriately logged | Security | Logging | PLANNED |

### Settings QA

|| ID | Scenario | Category | Feature | Status |
||----|----------|----------|---------|--------|
| QA-SET-001 | Verify settings validation on save | Validation | Settings | DONE — `SystemSettingUpdateTest::test_every_numeric_bound_rejects_a_value_outside_it` walks all 40 min:/max: bounds; `SettingsPentestTest` adds 10 out-of-range/malformed payloads |
| QA-SET-002 | Verify settings change is audited | Security | Settings | DONE — causer attribution, `source` channel per web/API, and rejected-writes-leave-no-row, all asserted in `SettingsPentestTest` |
| QA-SET-003 | Verify cache invalidation on settings change | Functional | Settings | DONE — `SystemSetting::set()` busts via `DB::afterCommit`; `SET-005` records the transaction-read hazard that motivated it |
| QA-SET-004 | Verify only authorized users can change settings | Authorization | Settings | DONE — guest, no-permission, and `settings.view`-only are each refused in `SettingsPentestTest`, web and API |

## ADR Numbering Convention

- Architecture ADRs: `ADR-001` through `ADR-NNN` (in `docs/planning/decisions.md`)
- Dependency ADRs: `DEP-001` through `DEP-NNN` (in
  `docs/base/architecture/decision-records/`)
- Both sequences are independent and **do not collide**.
- The next ADR number is determined by listing existing files in the
  relevant directory and taking the highest number + 1.
- New dependency decisions use `DEP-NNN` format.
- New architecture decisions use `ADR-NNN` format.
- Cross-references between ADR files must specify the prefix
  (`ADR-NNN` for architecture, `DEP-NNN` for dependency) when ambiguity
  could arise.