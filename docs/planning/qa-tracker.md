# QA Tracker

> Separate from implementation status. Each feature should eventually have all QA categories listed below.

## QA Categories

| Category | Description |
|----------|-------------|
| Functional QA | Does the feature work as specified? |
| Authorization QA | Are permissions properly enforced? |
| Security QA | Input validation, escaping, auth boundary, secrets |
| API QA | Response format, error handling, pagination, filtering |
| Regression QA | Does this change break existing functionality? |
| Documentation QA | Docs match code, accurate and complete? |

## QA Test Scenarios

### Authentication QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-AUTH-001 | Verify login using username | Functional | Login | DONE (2026-11-04) — `UsernameEmailChangeTest::test_login_works_with_username` posts `/login` with a username and asserts `assertAuthenticatedAs`; unit twin `AuthActionTest::test_a_correct_password_authenticates_by_email_and_by_username` |
| QA-AUTH-002 | Verify login using email | Functional | Login | DONE (2026-11-04) — `UsernameEmailChangeTest::test_login_works_with_email`; API twin `Api/Auth/LoginControllerTest::test_login_succeeds_for_valid_credentials` (200 + token) |
| QA-AUTH-003 | Verify invalid credentials returns 401 | API | Login | DONE (2026-11-04) — `Api/Auth/LoginControllerTest::test_login_fails_with_wrong_password` asserts 401 + code `UNAUTHENTICATED`. The 401 is the API shape: the web twin answers the 302 + `identifier` error by design (`error-contract.md`), which is why this row sits in the API category |
| QA-AUTH-004 | Verify rate limit on login (5/min per IP+identifier) | Security | Login | DONE (2026-11-04) — `Api/Auth/LoginControllerTest::test_api_login_rate_limited_after_5_attempts` (429) + `::test_api_login_is_limited_per_ip_across_accounts` (the separate `login-ip` bucket, so a second address is a second budget); limiter registered at `AuthServiceProvider.php:163` |
| QA-AUTH-005 | Verify temporary lock after 5 failed attempts | Security | Failed login | DONE (2026-11-04) — `AuthActionTest::test_repeated_failures_are_throttled_before_the_password_is_checked`: after 5 wrong passwords the CORRECT password still returns 429 with `lockedSeconds`, i.e. the lock is consulted before the hash. Web side records the attempt row (`AuthControllerTest::test_web_failed_login_creates_db_record`) |
| QA-AUTH-006 | Verify failed login counter resets on success | Functional | Failed login | PARTIAL — 2026-11-04, **asymmetry found, not fixed** (Phase 17 changes no runtime behaviour). The reset exists and clears both halves (`LoginThrottle::reset()` = `RateLimiter::clear` + deleting the `failed_login_attempts` rows, `app/Auth/LoginThrottle.php:149-157`), and the API channel calls it on a successful login (`Api/V1/Auth/LoginController.php:80`) and unlock calls it (`UserUnlockAction.php:47`) — but the web `AuthController::login` (`:68-89`) never does, and **no test asserts either path**. Owner: make the two channels agree (or state the difference in `docs/base/security/web-security.md`) and add the regression test |
| QA-AUTH-007 | Verify locked account cannot login | Authorization | Lock | DONE (2026-11-04) — `Api/Auth/LoginControllerTest::test_login_fails_for_locked_user` (403, account locked) — the account-state middleware refuses `is_locked` before authentication |
| QA-AUTH-008 | Verify unlock allows login again | Functional | Unlock | UNPROVEN — 2026-11-04. `UserStateWebTest::test_unlock_locked_user` and `Api/V1/User/UserStateTest::test_unlock_locked_user_via_api` assert `is_locked` flips to false; **neither attempts a login afterwards**, so 'unlock allows login again' is inferred from the flag, not proven. Missing assertion: unlock then authenticate → session created |
| QA-AUTH-009 | Verify logout current device revokes session | Functional | Logout | DONE (2026-11-04) — `AuthActionTest::test_an_api_logout_deletes_the_current_token_and_audits_it` (only the requesting token is deleted, another session survives) + web `AuthControllerTest::test_web_logout` `assertGuest` |
| QA-AUTH-010 | Verify logout all devices revokes all sessions | Security | Logout all | DONE (2026-11-04) — `AuthActionTest::test_logout_everywhere_clears_sessions_and_tokens_together` asserts 0 tokens AND 0 `sessions` rows |
| QA-AUTH-011 | Verify email verification flow | Functional | Email verification | DONE (2026-11-04) — `AuthControllerTest::test_web_verify_email_signed_link_marks_verified` — the signed link sets `email_verified_at`. Recorded in P15-C as a design question: web verify has no signature/expiry check of its own beyond the signed URL |
| QA-AUTH-012 | Verify email verification ≠ account activation | Security | Email verification | UNPROVEN — 2026-11-04. `EmailVerificationModeTest::test_login_still_requires_a_verified_address_in_every_mode` proves an unverified address blocks login in all three modes, but **no assertion relates `email_verified_at` to `is_active`** — the independence of the two columns (verifying email is not activating the account, and vice versa) is untested |
| QA-AUTH-013 | Verify forgot password sends reset email | Functional | Forgot password | DONE (2026-11-04) — `AuthActionTest::test_a_reset_link_is_sent_to_an_existing_account` asserts `Notification::assertSentTo($user, ResetPassword::class)` |
| QA-AUTH-014 | Verify password reset via email link | Functional | Password reset | DONE (2026-11-04) — `Api/V1/Auth/PasswordResetApiTest::test_a_valid_token_resets_the_password` (`Hash::check` on the new password) + `AuthActionTest::test_a_valid_token_moves_the_password_and_revokes_every_session` |
| QA-AUTH-015 | Verify reset token expires after 15 min | Security | Password reset | UNPROVEN — 2026-11-04. Scenario text corrected toward code (it said 60 min): `config/auth.php:110` is `env('PASSWORD_RESET_EXPIRE_MINUTES', 15)`, and 60 belongs to the separate email-verification link (`AuthServiceProvider.php:292`); `retention.md` already documents 15 min (Phase 16 C2). **No test ages a token past the window and asserts refusal** — the expiry is framework behaviour nobody has pinned here |
| QA-AUTH-016 | Verify rate limit on forgot password (3/minute per identifier+IP) | Security | Forgot password | DONE (2026-11-04) — `AuthControllerTest::test_web_forgot_password_rate_limited_after_3_attempts` (5th post refused with an `email` error). Scenario text corrected toward code (it said 3/hour): `AuthServiceProvider.php:198-205` is `Limit::perMinute($limit)` on `password_forgot_rate_limit`, default 3 |
| QA-AUTH-017 | Verify admin reset password generates secure link | Functional | Admin reset | N/A — 2026-11-04. **No admin-initiated password reset exists** on either channel: `routes/web.php` and `routes/api.php` expose forgot/reset as self-service plus `POST /api/v1/auth/password/change` for the account holder. The nearest shipped behaviour is admin CREATE issuing a temp password with `must_change_password`, which is QA-USER-003 and is itself unproven for the generation half. Kept as 'not built' rather than stamped DONE |
| QA-AUTH-018 | Verify admin cannot see user's new password | Security | Admin reset | DONE (2026-11-04) — `AdministrativeEventDispatchTest::test_registering_a_user_notifies_administrators_without_the_credential` asserts `assertNotSentTo($operator, UserCreatedNotification::class)` — the credential-bearing mail goes only to the account holder; `UserCreateAction` stores it hashed (`:151,171`) |
| QA-AUTH-019 | Verify password change requires current password | Authorization | Password change | DONE (2026-11-04) — `AuthActionTest::test_the_current_password_is_required_before_anything_is_written`: a wrong current password throws `ValidationException` on `current_password` and the stored hash is unchanged |
| QA-AUTH-020 | Verify password change respects history | Security | Password history | DONE (2026-11-04) — `AuthActionTest::test_a_recent_password_cannot_be_reused_while_history_is_enabled` + `::test_history_prunes_to_the_configured_count_and_keeps_the_newest`; web twin `PasswordHistoryTest::test_prevents_reuse_of_recent_passwords` |
| QA-AUTH-021 | Verify last_activity_at set on first successful login | Functional | last_activity_at | DONE (2026-11-04) — `AuthActionTest::test_the_web_channel_stamps_the_login_and_audits_the_account_as_causer`: the user starts with `last_activity_at` null and `assertNotNull` holds after `AuthLoginCompletedAction` (the write is `AuthLoginCompletedAction.php:63`, via `updateQuietly`) |
| QA-AUTH-022 | Verify last_activity_at is NULL for never-logged-in users | Functional | last_activity_at | UNPROVEN — 2026-11-04. The column is created null (`UserFactory.php:38`, `UserCreateAction.php:62`), but every test uses null as an INPUT precondition (`InactivityLockTest::test_is_inactive_returns_false_when_no_activity_set`) — nothing asserts the shipped default for a never-logged-in user |
| QA-AUTH-023 | Verify unlock does NOT update last_activity_at (NULL preserved) | Security | Unlock | UNPROVEN — 2026-11-04. `UserUnlockAction.php:45` writes only `is_locked=false`, so a NULL stays NULL by construction (ADR-018), but **no unlock test touches `last_activity_at`**. The ADR's preserved-NULL clause is asserted nowhere |
| QA-AUTH-024 | Verify unlock does NOT update last_activity_at (existing timestamp preserved) | Security | Unlock | UNPROVEN — 2026-11-04. Same shape as QA-AUTH-023: the action never writes the column, and no test asserts an existing timestamp survives an unlock |
| QA-AUTH-025 | Verify last_activity_at updates only on meaningful activity, not every request | Functional | last_activity_at | UNPROVEN — 2026-11-04. Measured behaviour is stronger than the row: `last_activity_at` has exactly **one writer in `app/`** — `AuthLoginCompletedAction.php:63`, at login. `EnsurePasswordChangeRequired`, `InactivityLock.php:27` and `InactivityLockSweep.php:134-138` only read it, so no per-request write exists to guard against. **No test asserts the column is unchanged after an ordinary authenticated request** |
| QA-AUTH-026 | Verify NULL last_activity_at users included in inactivity query via grace_days | Functional | Inactivity | DONE |
| QA-AUTH-027 | Verify password change revokes existing sessions/tokens | Security | Password change | DONE — all Sanctum tokens, database sessions, and remember_token are cleared in the password mutation transaction |

### User Management QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-USER-001 | Verify user list is paginated | API | User list | DONE (2026-11-04) — `UserCrudWebTest::test_index_paginates` (14 users, `per_page=5` → 5 rows, total 15) + API twin `Api/V1/UserCrudApiTest::test_index_returns_paginated_users` pins the four pagination keys and `per_page` 10 |
| QA-USER-002 | Verify user detail shows correct data | Functional | User detail | DONE (2026-11-04) — `UserExtendedCrudTest::test_show_user_displays_edit_form`: 200 + `assertSee($user->name)`, i.e. the right record's data reaches the right page |
| QA-USER-003 | Verify create user generates temp password | Functional | Create user | UNPROVEN — 2026-11-04. The create path DOES generate a temp password (`UserCreateAction::generateTempPassword`, length from `password_min_length`, handed to `UserCreatedNotification`), but the test named for it — `CreateUserTest::test_temp_password_is_12_chars` — asserts only `assertTrue($user->must_change_password)`: **a method name claiming a property its body never checks** (finding for the owner: rename or add the length/content assertion). `SecurityAuditTest::test_temp_password_minimum_length_enforced` covers `users.update`, not generation |
| QA-USER-004 | Verify new user forced to change password on first login | Security | Force change | DONE (2026-11-04) — `AuthControllerTest::test_must_change_password_keeps_redirecting_the_web_user_until_changed`: with the flag set, `/dashboard` and `/profile` both redirect to `password.expired` |
| QA-USER-005 | Verify deactivate prevents login | Authorization | Deactivate | DONE (2026-11-04) — `AuthControllerTest::test_web_login_fails_deactivated_user`: session error + `assertGuest()` for an `is_active=false` account |
| QA-USER-006 | Verify activate restores login | Functional | Activate | UNPROVEN — 2026-11-04. Both halves exist separately — deactivate blocks login (`AuthControllerTest::test_web_login_fails_deactivated_user`) and activate flips the flag (`UserStateWebTest::test_full_lifecycle_active_to_inactive_to_active`) — but **no test logs in after reactivation**; `test_web_login_succeeds` uses a never-deactivated user. Missing the last hop |
| QA-USER-007 | Verify lock prevents login but allows unlock | Authorization | Lock | DONE (2026-11-04) — `AuthControllerTest::test_web_login_fails_locked_user` (refused, no session) + `UserStateWebTest::test_unlock_locked_user` (unlock is reachable and clears the flag). Same caveat as QA-AUTH-008: the post-unlock login is not exercised |
| QA-USER-008 | Verify soft delete hides user but preserves data | Functional | Soft delete | DONE (2026-11-04) — `UserExtendedCrudTest::test_soft_delete_deletes_without_changing_is_active` (`assertSoftDeleted`, `is_active` retained = data preserved) + `WebSessionRevocationTest::test_a_soft_deleted_users_next_web_request_is_refused` (default scope hides the row; request → login redirect + `assertGuest`). The hiding is proven at scope level, not by an absence assertion on users.index rows |
| QA-USER-009 | Verify non-admin cannot access user management | Authorization | RBAC | DONE (2026-11-04) — `GateCAuthorizationTest::test_a_user_with_no_permissions_gets_403_on_every_admin_read_route` + `::test_a_user_with_no_permissions_cannot_create_a_user` — the route/permission gate (`can:`), which is the project's authorization pattern |
| QA-USER-010 | Verify password history prevents reuse | Security | Password history | DONE (2026-11-04) — `PasswordHistoryTest::test_prevents_reuse_of_recent_passwords`: seeds `password_histories`, changes to a prior password, `assertSessionHasErrors('password')` |
| QA-USER-011 | Verify profile update with valid data succeeds | Functional | Profile | DONE (2026-11-04) — `Api/V1/ProfileTest::test_update_profile_changes_name`: 200, persisted, success message |
| QA-USER-012 | Verify profile update with invalid email rejected | Validation | Profile | UNPROVEN — 2026-11-04. `ProfileTest::test_update_profile_validates_email_unique` proves a TAKEN (well-formed) email 422s — uniqueness, not format. The `email` rule exists in `ProfileUpdateRequest` but **no test posts a malformed address** to the profile path |
| QA-USER-013 | Verify change password requires current password | Authorization | Profile | DONE (2026-11-04) — `ProfileUpdateTest::test_wrong_current_password_shows_error_on_field` (web twin of QA-AUTH-019) |
| QA-USER-014 | Verify user list respects @can authorization | Authorization | User mgmt | DONE (2026-11-04) — `GateDUiGatingTest::test_the_user_list_hides_actions_the_caller_cannot_perform`: a `users.view`-only viewer sees no create/Lock/Trash/Permanent-Delete, with the counterpart `test_the_user_list_still_offers_what_the_caller_can_perform` proving the superadmin still does. This is Blade `@can` on the LIST and is deliberately a different property from QA-USER-009's route gate |

### RBAC QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-RBAC-001 | Verify role permission changes propagate to users | Functional | Role permissions | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_the_role_change_is_audited_with_before_and_after` — edit a user's roles, confirm the `user.roles_assigned` activity row records before AND after. Manual: change a user's role, check the audit log
| QA-RBAC-002 | Verify user inherits role permissions | Functional | Role permissions | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_changing_the_role_changes_what_the_account_can_do` + `test_the_sync_writes_nothing_to_the_user_permission_pivot` — the role change moves `can()` and leaves `model_has_permissions` at 0 rows (ADR-004). Manual: give a user `staff`, confirm they gain users.view with no direct permission row
| QA-RBAC-003 | Verify cannot delete last superadmin | Security | Superadmin | DONE | DONE (2026-09-30) — `LastSuperadminGuardTest::test_the_last_superadmin_cannot_be_removed` (soft-deleted case). NOTE: this was BROKEN until Group E — deleting the account bypassed the role guard entirely; confirmed exploitable down to zero superadmins, now fixed. Manual: as a non-superadmin holding users.delete, try to delete the only superadmin — refused, account intact
| QA-RBAC-004 | Verify cannot deactivate last superadmin | Security | Superadmin | DONE | DONE (2026-09-30) — `LastSuperadminGuardTest` deactivated case + `test_the_bulk_path_cannot_remove_the_last_superadmin` + `test_the_api_cannot_deactivate_the_last_superadmin` (409 LAST_SUPERADMIN). NOTE: also BROKEN until Group E, and the bulk bar bypassed the action via a raw UPDATE. Manual: deactivate the only superadmin via row button, bulk dropdown and API — all three refused
| QA-RBAC-005 | Verify superadmin bypasses auth where explicitly allowed | Authorization | Superadmin | DONE | DONE (2026-09-30) — Sidebar/route cases in `GateDUiGatingTest` + `RbacAuthorizationMatrixTest` — a superadmin reaches every gated screen. Mechanism is `Gate::before` (AuthServiceProvider), documented in `docs/base/security/authorization.md`. Manual: sign in as superadmin, confirm /users, /roles, /permissions, /settings all open
| QA-RBAC-006 | Verify superadmin does NOT bypass every boundary | Security | Superadmin | DONE | DONE (2026-09-30) — The bypass is scoped, not total: `SuperadminVisibilityTest` (a delegated admin still cannot grant superadmin, even WITH confirm_superadmin), `LastSuperadminGuardTest` (E5 binds superadmins too), `RoleManagementTest::test_a_system_role_permissions_cannot_be_edited` (even a superadmin cannot strip `admin`'s set). Manual: as superadmin, try to demote the last superadmin — refused
| QA-RBAC-007 | Verify registration default role is 'user' | Authorization | Registration | DONE | DONE (2026-09-30) — `RbacRoleSyncTest::test_a_create_without_roles_lands_on_the_default_role` — an admin create with no `roles` key lands on the configured default, not on no role. Manual: create a user leaving roles empty, confirm they hold `user`
| QA-RBAC-008 | Verify public cannot select admin/superadmin role | Security | Registration | DONE | DONE (2026-09-30) — `RegisterTest` + `RbacPentestTest::test_roles_cannot_be_smuggled_through_the_profile_endpoint` — a self-registering account cannot select a role and cannot acquire one later via a mass-assigned field. Manual: register, then PUT /profile with roles[]=superadmin — refused

### API QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-API-001 | Verify all API responses use consistent envelope | API | API v1 | DONE (2026-10-21) — `ApiErrorEnvelopeTest` (eight handler codes, identical key set minus `debug`), `ThrottleJsonResponseTest` (limiter's own 429 carries `meta`), `CheckAccountStateMiddlewareTest` + `PasswordExpiredEnforcementTest` (middleware 403s carry `meta`), `ApiDocsUiRenderTest` (docs 401). Manual: call any endpoint with a bad payload and confirm `{message, code, meta}` |
| QA-API-002 | Verify 401 for unauthenticated API access | API | API v1 | DONE (2026-10-21) — `ApiDocumentationTest::test_every_authenticated_operation_documents_a_401` (reachability from `auth:sanctum`) + `ApiDocsUiRenderTest` (guest on `/docs/api.json`). Manual: `GET /api/v1/profile` with no token answers 401 `UNAUTHENTICATED` |
| QA-API-003 | Verify request_id in all responses | Security | API v1 | DONE (2026-10-21) — `ApiErrorEnvelopeTest::test_a_handler_error_carries_a_code_and_correlation_meta`, `AuditRequestIdTest` (`meta.request_id` equals the `X-Request-ID` header), and `ApiError::meta()` is the single builder. Manual: compare `meta.request_id` with the `X-Request-ID` header on any response |
| QA-API-004 | Verify 429 on rate-limited endpoint | API | Rate limiting | DONE (2026-10-21) — `ThrottleJsonResponseTest` (JSON 429 with `retry_after_seconds`, web twin still redirects) + the auth lockout cases. Manual: exceed the login lockout and confirm 429 `RATE_LIMITED` |
| QA-API-005 | Verify pagination metadata in collections | API | API v1 | DONE (2026-10-21) — `AuditApiTest` pins `data.pagination`'s four keys and `AuditPentestTest` reads them through the query chain; the per-module API files assert the envelope beside them; `response-contract.md` § Collection lists the three unpaginated endpoints. Manual: `GET /api/v1/users?per_page=5` returns `current_page`, `last_page`, `per_page`, `total` |

### Security QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-SEC-001 | Verify HSTS header present | Security | Headers | DONE (2026-11-04) — `SecurityHeadersTest::test_a_page_carries_the_origin_headers` asserts the exact `Strict-Transport-Security` value (`max-age=31536000; includeSubDomains`) on `/login`, not mere presence; `::test_the_json_api_carries_them_too` proves it survives redirect and JSON responses |
| QA-SEC-002 | Verify CSP header present | Security | Headers | DONE (2026-11-04) — `ContentSecurityPolicyTest::test_a_page_is_served_with_a_nonce_and_no_unsafe_inline` (parses the header, checks `default-src 'self'` + the per-request nonce, and that `'unsafe-inline'` is absent from `script-src`) |
| QA-SEC-003 | Verify frame protection (CSP `frame-ancestors 'none'`; `X-Frame-Options` is not sent) | Security | Headers | DONE (2026-11-04) — `SecurityHeadersTest::test_frame_protection_is_the_policy_not_a_second_header`: the one test asserting BOTH halves of the row — `assertHeaderMissing('X-Frame-Options')` and `frame-ancestors 'none'` in the CSP. `ContentSecurityPolicyTest` asserts `frame-ancestors` alone |
| QA-SEC-004 | Verify CSRF token on web forms | Security | CSRF | DONE (2026-11-04) — `CsrfProtectionTest::test_every_state_changing_form_carries_a_token`: a source sweep of every view, every `method="post"` block must contain `@csrf`, and the missing list is asserted empty; the same file pins the 419 per verb and the empty `except` list (Phase 13 Group B) |
| QA-SEC-005 | Verify no stack traces in production errors | Security | Error handling | DONE (2026-11-04) — `SecurityAuditTest::test_error_responses_do_not_leak_stack_traces` (debug off: `trace`/`file`/`line` null in JSON) + `::test_html_error_pages_do_not_leak_the_exception` (a real 500 whose body excludes `Stack trace`, `.php`, `vendor/`) |
| QA-SEC-006 | Verify no secrets in error responses | Security | Error handling | DONE (2026-11-04) — envelope half: `ApiErrorEnvelopeTest::test_a_handler_error_carries_a_code_and_correlation_meta` (`assertArrayNotHasKey('debug')`) with `::test_debug_mode_adds_detail_without_changing_the_shape` pinning the inverse. Secret-text half rides the SAME `SecurityAuditTest::test_html_error_pages_do_not_leak_the_exception` as QA-SEC-005 (exception class, message and source path all excluded) — one owner, cross-referenced, not a duplicate gate |
| QA-SEC-007 | Verify input validation at trust boundaries | Security | Validation | DONE (2026-11-04) — `InputSanitizationTest::test_users_index_refuses_an_unknown_sort_and_direction` (refusal reaches the session errors before any `orderBy`) + `::test_an_undeclared_query_key_never_reaches_the_query_builder` (the payload is absent from the logged SQL and bindings). The file's escaping cases belong to QA-SEC-007's output half and are the same owner |
| QA-SEC-008 | Verify password hashing (bcrypt/argon2) | Security | Password security | UNPROVEN by test, correct by framework — 2026-11-04. `SelfRegistrationPerformanceTest::test_password_hashing_happens_once_per_registration` asserts `Hash::check(...)`, which is driver-agnostic: it proves 'hashed and checkable', not 'bcrypt/argon2'. No test pins `config('hashing.driver')` or a stored-hash prefix (`$2y$`/`$argon`). There is no `config/hashing.php` in the repo, so Laravel's default `bcrypt` applies with `.env BCRYPT_ROUNDS=12` (phpunit.xml lowers it to 4). Recorded as UNPROVEN on purpose — closing it is a one-line test, and Phase 17 writes none |

### Audit QA

Automated coverage is **already closed** for the scenarios below — the "Proof"
column names the test that pins it, and the F2 pentest covers the attack-shaped
versions. What is left here is the manual pass, because the export round-trip has
one client-side behaviour no curl test reaches: the table must resolve ITSELF.

| ID | Scenario | Category | Feature | Status | Proof / Manual step |
|----|----------|----------|---------|--------|---------------------|
| QA-AUDIT-001 | Verify audit records created on mutations | Functional | Audit Trail | DONE | `ActionFirstAuditTest` — 11 cases, including "exactly one row" and rollback |
| QA-AUDIT-002 | Verify audit metadata includes IP, UA, request_id | Security | Audit Trail | DONE | `AuditRequestIdTest`, `AuditContextTest` — **and** that `ip`/`ua` are NULL outside a request, not `127.0.0.1` |
| QA-AUDIT-003 | Verify audit records are read-only | Authorization | Audit Trail | DONE | `AuditReadOnlyTest` (structural), `AuditPentestTest` probe 4 (all four verbs, both channels) |
| QA-AUDIT-004 | Verify async export end-to-end | Functional | Audit Export | **PARTIAL — needs a human** | Automated half done. **Manual:** run `php artisan queue:work`, click Export, and confirm the row flips `Preparing` → `Ready` with a Download button **without a page reload** |
| QA-AUDIT-005 | Verify export file expires after lifecycle | Security | Audit Export | DONE | `AuditExportTest` — 410 past the window, and `test_a_failed_export_leaves_no_file_behind_even_when_the_row_never_recorded_one` |
| QA-AUDIT-006 | Verify audit records written within transaction before commit | Security | Audit Trail | DONE | `ActionFirstAuditTest::test_a_rolled_back_delete_leaves_no_audit_record` |
| QA-AUDIT-007 | Verify rollback produces no audit record | Functional | Audit Trail | DONE | same file, three rollback cases |
| QA-AUDIT-008 | Verify the bell badge updates when an export finishes | Functional | Audit Export | **PARTIAL — needs a human** | Automated half done (`notification-poll` script ships and the event name is asserted). **Manual:** confirm the badge rises within ~5 s, not the 30 s interval |
| QA-AUDIT-009 | Verify a dead flag closes the module for superadmin too | Authorization | Audit Trail | DONE | `AuditPentestTest::test_a_dead_flag_closes_the_module_for_everyone_including_superadmin` |

### Logging QA

Automated coverage is **partial** here, and the reconciliation of 2026-11-04 says so
row by row rather than rounding up: the suite observes log WRITES at exactly one
failure site (`AuditExportTest`, via `Log::spy`), pins the payload/row/container for
the correlation id, and proves the audit trail carries no credential. What no test
does today is assert that a framework-reported exception, a queue `failed()` hook or
an authorization denial produces a LOG RECORD — those rows are UNPROVEN or PARTIAL,
and they are the gap list for the owner, not a Phase 17 fix.

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-LOG-001 | Verify failures produce correct log level/event name | Functional | Logging | PARTIAL — 2026-11-04. Proven at one of eight failure sites: `AuditExportTest::test_a_failed_export_retries_and_logs_every_attempt` asserts `Log::warning` with event `audit.export.attempt_failed` + context, and the pair `::test_the_last_allowed_retry_logs_retries_exhausted` / `::test_an_earlier_retry_does_not_log_retries_exhausted` discriminates level (warning vs silent). The other `Log::warning` sites are never observed against a log spy: `InactivityLockSweep.php:91`, `PasswordExpirySweep.php:85`, `CleanTemporaryStorageJob.php:61`, `ExecuteSystemBackupJob.php:95`, `QueueFailureProbe.php:82`, `HealthCheckService.php:105-231` |
| QA-LOG-002 | Verify unexpected exceptions are logged | Security | Logging | UNPROVEN — 2026-11-04. Nearest is `AuditExportTest::test_a_failed_export_retries_and_logs_every_attempt`, which throws inside `handle()` and asserts the job's OWN catch, not framework reporting. `bootstrap/app.php`'s `withExceptions` only renders envelopes (`ApiErrorEnvelopeTest` asserts the HTTP body). Nothing asserts that an unexpected exception produces a log record |
| QA-LOG-003 | Verify sensitive data (passwords/tokens/secrets) is not logged | Security | Logging | PARTIAL — 2026-11-04. Proven for the DB audit trail, not for logs: `AuditPropertyScrubTest::test_no_audit_row_carries_a_credential_key` + `::test_no_audit_row_carries_a_credential_value` scan every `activity_log.properties` key at every depth against `FORBIDDEN_KEYS` with planted plaintext, and `::test_the_scrub_test_would_catch_a_leak` proves the scanner is not vacuous. For log FILES there is no mechanism to test: `config/logging.php:102,113` install only `PsrLogMessageProcessor`, `AuthSendResetLinkAction.php:40` logs a raw email address, and the only `redact_keys` list in the tree is `config/vantage.php:73` (package payload recording, off by default, untested). Owner-facing finding: 'sensitive data is not logged' is currently a policy, not a mechanism |
| QA-LOG-004 | Verify request/correlation ID present in log entries | Security | Logging | PARTIAL — 2026-11-04. Payload half proven by `JobConventionTest::test_the_payload_carries_the_dispatch_time_request_id` + `::test_the_payload_key_is_present_even_without_a_request` and the reader by `::test_the_correlation_read_is_null_safe`; row half by `AuditRequestIdTest::test_an_http_mutation_row_carries_the_request_id_from_its_own_response_header`; container half by `CorrelationIdMiddlewareTest::test_correlation_id_available_in_container`. Missing: `AuditExportTest.php:681-689` asserts the context keys but NOT `request_id`, and no test drives a `failed()` against a Log spy — 'present in log entries' is unasserted |
| QA-LOG-005 | Verify audit records created within transaction before commit | Security | Logging | DONE (2026-11-04) — `ActionFirstAuditTest::test_a_rolled_back_delete_leaves_no_audit_record`, `::test_a_rolled_back_mutation_leaves_no_audit_record` (5-case provider: `auth.inactivity_lock`, `auth.email_verified`, `auth.logout_all`, `auth.password_expiry.sweep`, `auth.login`) and `::test_a_rolled_back_settings_save_leaves_no_value_or_audit_row` each run the action inside a transaction that then throws and assert 0 rows — which only holds because the write is inside the transaction. **Duplicate of QA-AUDIT-006**: same property, same owner, kept as a cross-reference (§ 2 one-check-one-owner), not a second gate |
| QA-LOG-006 | Verify failed transactions do not create false successful audit events | Functional | Logging | DONE (2026-11-04) — the same three rollback assertions in `ActionFirstAuditTest` prove it; **cross-reference to QA-AUDIT-007**, which owns the check. No second test was added |
| QA-LOG-007 | Verify queue failures are observable in logs | Security | Logging | PARTIAL — 2026-11-04. `JobConventionTest::test_every_job_has_a_failed_hook` asserts `method_exists($class, 'failed')` — the SHAPE only (no trait use, no event name, no log write); `QueueFailureProbeTest::test_the_probe_job_always_throws` + `::test_the_probe_command_dispatches_one_failing_job` prove the job throws and one is dispatched; `AuditExportTest.php:554,603` invoke `failed()` but assert status and file cleanup. Missing: failure → observable log record |
| QA-LOG-008 | Verify authorization/security failures are appropriately logged | Security | Logging | UNPROVEN — 2026-11-04. Denials are asserted as RESPONSES (`ApiErrorEnvelopeTest::test_an_authentication_refusal_carries_correlation_meta`, the 403/409 matrix in `AuditExportTest::test_the_retry_is_refused_once_the_budget_is_spent`), never as log lines. The guard-path `Log::warning` sites `UserDeactivateAction.php:81,91`, `UserLockAction.php:80`, `UserActivateAction.php:69` have no log assertion anywhere |

### Monitoring QA

Automated coverage is **closed** for the scenarios below — the "Proof" column names
the test that pins it (Phase 11 Groups B–E). What is left is the manual pass on a
real deployment, where the flag rows and the CSP header come from the environment
rather than from PHPUnit.

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-MON-001 | Verify `/pulse` needs `pulse.view` AND the `pulse` flag | Authorization | Pulse | DONE — `PulsePermissionGateTest`, 6 cases (holder 200, no permission 403, superadmin 200, flag off 403 for a holder, flag off 403 for superadmin, and the gate itself reading the flag while the module is kill-switched — added by the Phase 11 security pass, ADR-024 clause 2) |
| QA-MON-002 | Verify `/health` needs `health.view` AND the `health` flag | Authorization | System Health | DONE — `HealthPermissionGateTest`, same 5 cases |
| QA-MON-003 | Verify a dead flag closes the module for **superadmin** too | Authorization | Monitoring | DONE — `test_the_flag_off_refuses_superadmin_too` in both files (D-3, closed 2026-10-16) |
| QA-MON-004 | Verify `/pulse` renders under the nonce-only CSP | Functional | Pulse | DONE — `ContentSecurityPolicyTest::test_only_the_pulse_route_grants_unsafe_eval` + `::test_every_script_on_the_pulse_page_carries_the_policys_nonce` (DOM-parsed) |
| QA-MON-005 | Verify Pulse cards survive a warm cache | Functional | Pulse | DONE — `PulseQueryCacheTest` forces a serializing store and fails at `Queues.php:35` without the `array` default |
| QA-MON-006 | Verify `/health` renders with no query from Blade | Regression | System Health | DONE — `HealthUiRenderTest::test_it_renders_and_queries_nothing` (delta across a warm second render) |
| QA-MON-007 | Verify the sidebar shows each entry only with its permission AND its flag | Authorization | Monitoring | DONE — `HealthUiRenderTest`'s four sidebar cases; Pulse opens in a new tab with `rel="noopener"`, `/health` stays in-tab |
| QA-MON-008 | Verify the API health twin stays unauthenticated and shape-bounded | Security | System Health | DONE — `HealthCheckEndpointTest` (no acting user; `{status, timestamp, checks}` only — no versions, no config, no DSNs) |
| QA-MON-009 | Verify every job declares its queue, retry chain and a `failed()` that logs `request_id` | Regression | Queue | DONE — `JobConventionTest`, 6 cases, reflection over every class in `app/Jobs` |
| QA-MON-010 | Verify the flag rows exist after a deploy to an environment that predates them | Functional | Monitoring | **PARTIAL — needs a human** | Automated half done (`FeatureFlagSeeder` iterates `FeatureCatalog::slugs()`). **Manual:** on a database seeded before Phase 11, run `php artisan db:seed --class=FeatureFlagSeeder` and confirm `/health` stops returning 403 — until it runs, `/health` refuses everyone, superadmin included |
| QA-MON-011 | Verify the `pulse` flag stops the RECORDING, not only the page | Security | Pulse | DONE — `PulseRecordingKillSwitchTest` (2 cases: with the flag on the entry is ingested, with it off `Pulse::ingest()` drops it and `pulse_entries` stays empty) |
| QA-MON-012 | Verify the `vantage` flag reaches a RUNNING worker, not just the route | Security | Queue | DONE — `VantageRecordingKillSwitchTest` (2 cases: `Looping` refreshes `config('vantage.enabled')` from the flag; a synchronous job that records under an active flag writes no `vantage_jobs` row under a dead one) |
| QA-MON-013 | Verify switching a flag off revokes its permissions and hides them everywhere | Authorization | Feature flags | DONE — `FeaturePermissionRevokeTest` (10 cases: both pivots, the bulk action, no re-grant on re-enable, hidden from the matrix and the permission index, a hidden grant preserved through a save, a crafted grant refused) + `PermissionFeatureMapTest` (7 cases pinning the map) |
| QA-MON-014 | Verify the retry button has a ceiling the operator can tune | Security | Queue | DONE — `QueueRetryThrottleTest` (2 cases: the ceiling comes from `queue_retry_rate_limit_per_minute`, measured at two values either side of the seeded default) |
| QA-MON-015 | Verify the health endpoint keeps its ceiling while its cache store is down | Performance | System Health | DONE — `HealthCheckCacheFallbackTest` (4 cases, counting probe SETS: one per window with the configured store dead, one per request only when both stores are dead) |
| QA-MON-016 | Verify the flag modal warns that disabling revokes permissions and that re-enabling does not grant them back — in two sentences | Functional | Feature flags | DONE — `FeatureFlagUiRenderTest::test_the_disable_modal_says_the_permissions_are_revoked` (the count for a multi-permission flag, the name for a single-permission one, nothing for a flag that owns none, nothing on the ENABLE switch, and a 2-sentence/220-char ceiling) + `BulkActionCopyTest::test_the_disable_copy_stays_two_sentences` (same ceiling for the bulk modal) |

### Settings QA

| ID | Scenario | Category | Feature | Status |
|----|----------|----------|---------|--------|
| QA-SET-001 | Verify settings validation on save | Validation | Settings | DONE — `SystemSettingUpdateTest::test_every_numeric_bound_rejects_a_value_outside_it` walks all 40 min:/max: bounds; `SettingsPentestTest` adds 10 out-of-range/malformed payloads |
| QA-SET-002 | Verify settings change is audited | Security | Settings | DONE — causer attribution, `source` channel per web/API, and rejected-writes-leave-no-row, all asserted in `SettingsPentestTest` |
| QA-SET-003 | Verify cache invalidation on settings change | Functional | Settings | DONE — `SystemSetting::set()` busts via `DB::afterCommit`; `SET-005` records the transaction-read hazard that motivated it |
| QA-SET-004 | Verify only authorized users can change settings | Authorization | Settings | DONE — guest, no-permission, and `settings.view`-only are each refused in `SettingsPentestTest`, web and API |

## Reconciliation (2026-11-04, P17-D2)

55 rows (49 PLANNED + 6 TODO) had not been reconciled with the suite since the QA list
was written — the Authentication, User Management, Security and Logging sections. Each
was now checked against the test that would prove it, by reading the method body, not
the file name. Result: **36 DONE, 5 PARTIAL, 13 UNPROVEN, 1 N/A** across those four
four sections (2026-11-04, suite at 2668 passed / 9837 assertions). The tracker as a whole is
**77 DONE / 8 PARTIAL / 13 UNPROVEN / 1 N/A of 99 rows**. Nothing was upgraded to DONE
without a cited assertion, and nothing that failed the check was deleted or rewritten
into silence.

Two scenario texts were corrected toward the code they describe (the project's rule that
docs move toward code-with-ADR): QA-AUTH-015 said 60 minutes where `config/auth.php:110`
defaults to 15, and QA-AUTH-016 said 3/hour where `AuthServiceProvider.php:198-205` is
`Limit::perMinute` on `password_forgot_rate_limit` (default 3). QA-AUTH-017 is recorded
N/A: no admin-initiated password reset exists on either channel.

### Gap list with owners (what a follow-up phase would need)

- **QA-AUTH-006 (the only live asymmetry found)** — API login clears the failed-attempt
   state on success and unlock does too, but the web login path never calls
   `LoginThrottle::reset()`. Owner decision: make the channels agree or document the
   difference; either way it needs a regression test, and Phase 17 changes no behaviour.
- **13 UNPROVEN rows** group into three properties, each one test away from DONE:
   post-state login (AUTH-008, USER-006), column-level truthfulness of
   `last_activity_at` and the email/activation independence (AUTH-012, 022, 023, 024,
   025), and input/format assertions at the profile and reset-token boundaries
   (USER-003, USER-012, SEC-008, LOG-002, LOG-008, plus AUTH-015).
- **5 PARTIAL rows** (AUTH-006, LOG-001, LOG-003, LOG-004, LOG-007) all stop at the same
   line: the suite asserts the HTTP/DB outcome, and the log-record outcome is only
   spied in `AuditExportTest`. LOG-003 additionally has no file-log redaction mechanism
   to test at all.
- Three rows already recorded as needing a human stay as they are (QA-AUDIT-004,
   QA-AUDIT-008, QA-MON-010) — deferred runtime evidence, not missing tests.

---

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