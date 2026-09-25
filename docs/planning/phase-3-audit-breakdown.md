# Phase 3 Audit & Breakdown — Authentication Foundation

> Audit date: 2026-09-18 | Branch: feature/phase-3-authentication | Status: PHASE 3F COMPLIANT
> Purpose: break Phase 3 into small executable tasks, flag queue-eligible items, resolve Actions/Services question.

---

## Phase 3A — Login Flow (DONE)

| ID | Task | Status |
|----|------|--------|
| AUTH-004 | LoginFormRequest (identifier+password, anti-enumeration) | **DONE** |
| AUTH-005 | LoginController → AuthenticateUserAction | **DONE** |
| AUTH-006 | Login + email verification tests (57 tests, 149 assertions) | **DONE** |
| AUTH-007 | Failed login tracking (LoginThrottle + FailedLoginAttempt) | **DONE** |
| AUTH-008 | Temporary lock enforcement | **DONE** |
| AUTH-009 | Logout (current device) | **DONE** |
| AUTH-010 | Logout-all-devices | **DONE** |

**Architecture:** Login flow uses `AuthenticateUserAction` (throttle + findUser + account state). Controllers are thin orchestrators — API returns JSON, Web returns redirect. Audit logged by controller with channel.

**Action Classes implemented:**
- `AuthenticateUserAction` — login flow
- `SendPasswordResetLinkAction` — forgot password
- `ResetPasswordAction` — password reset
- `VerifyEmailAction` — email verification
- `UnlockUserAction` — admin unlock

**Traits removed:** `AuthenticatesUsers`, `HandlesUserLookup`, `HandlesLockCheck`, `HandlesPasswordResetFlow` — logic moved to Actions.

---

## Phase 3B — Logout Flow (DONE)

| ID | Task | Status |
|----|------|--------|
| AUTH-009 | Logout (current device) | **DONE** |
| AUTH-010 | Logout-all-devices | **DONE** |

**Implementation:**
- `LogoutController` → delete current Sanctum token + audit `auth.logout`
- `LogoutAllController` → delete all tokens + audit `auth.logout_all`
- Web logout via `AuthControllergout` → `Auth::logout()` + session invalidate + audit
- **Remaining UI note:** logout-all remains available through the shared auth flow; no separate admin device-selection view is required for the current Phase 3 scope.

---

## Phase 3C — Email Verification (COMPLIANT)

| ID | Task | Status |
|----|------|--------|
| AUTH-011a | VerifyEmailController → VerifyEmailAction | **COMPLIANT** — action handles markEmailAsVerified; controller adds mode check + audit |
| AUTH-011b | ResendVerificationController → ResendVerificationAction | **COMPLIANT** — action handles send notification + rate limit; controller adds mode check + audit |

**Implementation (verified):**
- `AuthenticateUserAction::checkEmailVerification()` — single source of truth; blocks all modes except `disabled`; returns `UNVERIFIED_EMAIL` error
- `VerifyEmailAction` — `markEmailAsVerified()` + check already verified
- `ResendVerificationAction` — rate limited (3600s window), generic success, user enumeration safe
- `AuthControllergin` — catch UNVERIFIED_EMAIL → redirect verify-email page
- `AuthControllerrifyEmail` — verify email via signed link → redirect verify-email page + flash
- `AuthControllersendVerification` — resend via form email input → action send email
- `Api/V1/Auth/LoginController` — catch UNVERIFIED_EMAIL → JSON 403
- `verified` middleware on protected routes (web + API)

---

---

## 1. Current State Snapshot

### Already implemented (code exists, not yet reflected in task tracker)

||| File | What it does | Phase |
|||------|-------------|-------|
||| `app/Auth/LoginThrottle.php` | RateLimiter + failed_login_attempts DB escalation; progressive lockout (5→15→25→… min); `isLocked`, `recordFailed`, `reset`, `lockedFor`, `clearIfExpired` | AUTH-007 / AUTH-008 |
||| `app/Models/FailedLoginAttempt.php` | Model for `failed_login_attempts` table; `nextLockoutMinutes()` progressive calc | AUTH-007 |
||| `database/migrations/…_create_failed_login_attempts_table.php` | Table migration | AUTH-007 |
||| `app/Http/Middleware/EnsurePasswordChangeRequired.php` | Blocks access when `must_change_password` or `password_expires_at` past; exempts password.change/verification/email.resend/logout; 403 JSON or 302 redirect | Phase 3 middleware |
||| `app/Actions/Auth/AuthenticateUserAction.php` | Login flow: throttle check + user lookup + account state check | AUTH-005 |
||| `app/Actions/Auth/SendPasswordResetLinkAction.php` | Forgot password: lock check + send link + audit | AUTH-012 |
||| `app/Actions/Auth/ResetPasswordAction.php` | Reset password: lock check + Password::reset + audit | AUTH-013 |
||| `app/Actions/Auth/VerifyEmailAction.php` | Email verification: mark verified + check already verified | AUTH-011a |
||| `app/Actions/Auth/UnlockUserAction.php` | Admin unlock: is_locked=false + throttle reset | (Phase 4/5 overlap) |
||| `app/Actions/Auth/ChangePasswordAction.php` | Shared password-change logic: validate current, history check, hash, expiration, revoke tokens, activity log | PWD-002/AUTH-014 |
||| `app/Http/Controllers/Api/V1/Auth/LoginController.php` | API login → AuthenticateUserAction, JSON response | AUTH-005 |
||| `app/Http/Controllers/Api/V1/Auth/PasswordForgotController.php` | Forgot-password → SendPasswordResetLinkAction | AUTH-012 |
||| `app/Http/Controllers/Api/V1/Auth/PasswordResetController.php` | Reset-password → ResetPasswordAction | AUTH-013 |
||| `app/Http/Controllers/Api/V1/Auth/VerifyEmailController.php` | API verify email → VerifyEmailAction | AUTH-011a |
||| `app/Http/Controllers/Api/V1/Auth/UnlockController.php` | Admin unlock → UnlockUserAction | (Phase 4/5 overlap) |
||| `app/Http/Controllers/Api/V1/Auth/LogoutController.php` | API logout: delete current token + audit | AUTH-009 |
||| `app/Http/Controllers/Api/V1/Auth/LogoutAllController.php` | API logout-all: delete all tokens + audit | AUTH-010 |
||| `app/Http/Controllers/Web/V1/Auth/AuthControllerp` | Web auth: view rendering + login/logout logic + forgot/reset password + audit trail | UI-AUTH-002 |
||| `app/Http/Requests/Auth/LoginRequest.php` | Login form request: identifier + password validation | AUTH-004 |
||| `resources/views/layouts/auth.blade.php` | Auth layout: centered card, no sidebar/header | UI-AUTH-001 |
| `resources/views/pages/auth/*.blade.php` | Auth views: login, forgot-password, reset-password, verify-email | UI-AUTH-001,003,005,007 |
||| `routes/web.php` | Web routes: auth views via Route::controller(AuthControllerlass) | UI-AUTH-008 |
||| Traits removed: AuthenticatesUsers, HandlesUserLookup, HandlesLockCheck, HandlesPasswordResetFlow | Logic moved to Actions | — |

### Route registrations — all controllers now exist

All API controllers implemented. View rendering handled by AuthControllerot inline web.php closures).

| Route | Controller | Status |
|-------|----------|--------|
| `POST /api/v1/auth/login` | `App\Http\Controllers\Api\V1\Auth\LoginController` | **DONE** |
| `POST /api/v1/auth/logout` | `App\Http\Controllers\Api\V1\Auth\LogoutController` | **DONE** |
| `POST /api/v1/auth/logout-all` | `App\Http\Controllers\Api\V1\Auth\LogoutAllController` | **DONE** |
| `GET /api/v1/auth/email/verify/{id}/{hash}` | `App\Http\Controllers\Api\V1\Auth\VerifyEmailController` | **DONE** |
| `POST /api/v1/auth/email/resend` | `App\Http\Controllers\Api\V1\Auth\ResendVerificationController` | **DONE** |
||| `GET /login` | `App\Http\Controllers\Web\V1\Auth\AuthControllerowLogin` | **DONE** |
||| `POST /login` | `App\Http\Controllers\Web\V1\Auth\AuthControllergin` | **DONE** |
||| `GET /forgot-password` | `App\Http\Controllers\Web\V1\Auth\AuthControllerowForgotPassword` | **DONE** |
||| `POST /forgot-password` | `App\Http\Controllers\Web\V1\Auth\AuthControllerndPasswordResetLink` | **DONE** |
||| `GET /reset-password` | `App\Http\Controllers\Web\V1\Auth\AuthControllerowResetPassword` | **DONE** |
||| `POST /reset-password` | `App\Http\Controllers\Web\V1\Auth\AuthControllersetUserPassword` | **DONE** |
| `GET /verify-email` | `App\Http\Controllers\Web\V1\Auth\AuthControllerowVerifyEmail` | **DONE** |
| `GET /email/verify/{id}/{hash}` | `App\Http\Controllers\Web\V1\Auth\AuthControllerrifyEmail` | **DONE** |
| `POST /email/resend` | `App\Http\Controllers\Web\V1\Auth\AuthControllersendVerification` | **DONE** |
||| `POST /logout` | `App\Http\Controllers\Web\V1\Auth\AuthControllergout` | **DONE** |

### Remaining (not yet implemented)

| ID | Task | Status |
|----|------|--------|
| UI-A-005 | Connect UI views to API logic (AdminLTE form POST → controller redirect) | **DONE** |

### Middleware wired

- `password.change.required` alias → `EnsurePasswordChangeRequired` registered in `bootstrap/app.php`
- `throttle:login` wired on web POST /login + API POST /auth/login (shared key via LoginThrottle::key)
- `throttle:forgot-password` wired on web POST /forgot-password, POST /reset-password + API POST /auth/password/forgot, POST /auth/password/reset (shared key)
- `throttle:resend-verification` on API POST /auth/email/resend
- `verified` middleware on protected routes (web + API) — blocks unverified users from /dashboard and API protected endpoints

### Audit trail status

| Event | API | Web |
|-------|-----|-----|
| `auth.login` | LoginController ✅ | AuthController(channel=web) |
| `auth.logout` | LogoutController ✅ | AuthController|
| `auth.logout_all` | LogoutAllController ✅ | AuthController|
| `auth.password_reset_requested` | PasswordForgotController ✅ | AuthController|
| `auth.password_reset_completed` | PasswordResetController ✅ | AuthController|
| `auth.email_verified` | VerifyEmailController ✅ | AuthControllerrifyEmail ✅ |
| `auth.verification_resent` | ResendVerificationController ✅ | AuthControllersendVerification ✅ (channel=web) |

Both channels log audit at the mutation site per the audit pattern
(Controller logs directly for thin operations, no model observers).
Web audit uses `$this->audit()` from base Controller (same as API).

### Route grouping: public vs auth

**Web routes (`routes/web.php`):**

|||| Group | Routes | Middleware |
||||-------|--------|-----------|
|| Public | `/`, `/login` (GET), `/forgot-password` (GET), `/reset-password` (GET), `/verify-email` (GET), `/email/verify/{id}/{hash}` (GET) | none |
|||| Public (guest) | POST `/login`, POST `/forgot-password`, POST `/reset-password` | `throttle:login`, `throttle:forgot-password` |
|||| Auth | `/dashboard`, POST `/logout` | `auth:sanctum`, `verified`, `auth` |

**API routes (`routes/api.php`):**

|| Group | Routes | Middleware |
||-------|--------|-----------|
|| Public (guest) | POST `/auth/login`, POST `/auth/password/forgot`, POST `/auth/password/reset`, GET `/auth/email/verify/{id}/{hash}` | `throttle:login`, `throttle:forgot-password`, `signed` |
|| Protected | POST `/auth/logout`, POST `/auth/logout-all`, POST `/auth/email/resend`, POST `/auth/password/change` | `auth:sanctum`, `verified`, `password.change.required` |

### Brute force throttle

||| Endpoint | Throttle | Limit |
|||----------|----------|-------|
||| POST `/api/v1/auth/login` | `throttle:login` | 5 attempts/min (per IP + identifier) |
||| POST `/api/v1/auth/password/forgot` | `throttle:forgot-password` | 3 attempts/min (per IP + email) |
||| POST `/api/v1/auth/password/reset` | `throttle:forgot-password` | 3 attempts/min (per IP + token) |
||| POST `/api/v1/auth/email/resend` | `throttle:resend-verification` | 5 attempts/hour (per user) |
||| POST `/login` (web) | `throttle:login` | Same as API — shared rate limiter |
||| POST `/forgot-password` (web) | `throttle:forgot-password` | Same as API — shared rate limiter |
||| POST `/reset-password` (web) | `throttle:forgot-password` | Same as API — shared rate limiter |

**Shared throttle keys:** `LoginThrottle::key()` generates a consistent key from identifier + IP, so web and API share the same throttle bucket for the same user. This prevents bypassing API throttle via web form.

### User model

- Implements `MustVerifyEmail` ✓
- `HasApiTokens` (Sanctum) ✓
- Soft deletes ✓
- Auth fields: `is_active`, `is_locked`, `must_change_password`, `password_expires_at`, `last_activity_at` ✓

---

## 2. Actions vs Services — Resolution

### Verdict: NOT the same. Keep both. Different responsibility boundary.

**`app/Actions/` — single-operation classes**
- One public method (`run()` or `__invoke()`)
- Naming: `VerbNoun` (e.g. `ChangePasswordAction`, `SendResetLink`, `RevokeUserTokens`)
- Extract when **either** the operation is non-trivial (>~10 lines of logic beyond
  simple delegation) **OR** the logic is shared across ≥2 controllers.
- Do NOT wrap a single trivial model call in an Action — inline it in the controller.
- Mutations that perform writes log audit within the action itself (the action has
  full context: causer, subject, properties). This keeps audit co-located with the
  mutation.
- Already has: `ChangePasswordAction`

**`app/Services/` — cohesive domain objects + external integrations**
- Naming: `NounService` (e.g. `HealthCheckService`, `AuditService`, `EmailService`)
- Multiple related methods, cohesive responsibility; may hold state/configuration and
  coordinate multiple subsystems.
- External service integrations (SMS gateway, payment provider, third-party HTTP API)
  belong here, not in Actions.
- Do NOT create a Service class solely because a Services folder exists, or because
  the operation could be a static method. A single-method Service with no state is
  usually an Action or an inline call.
- Already has: `HealthCheckService`

### Decision rule

| Question | If yes → | If no → |
|----------|----------|---------|
| One focused operation with one entry point? | Action | Service / inline |
| Logic non-trivial (>~10 lines) or shared ≥2 callers? | Action | Inline in controller |
| Integrates with external service (HTTP API, gateway, provider)? | Service | Action / inline |
| Holds state or coordinates multiple subsystems? | Service | Action |

### For Phase 3 auth operations

| Operation | Where it lives | Reason |
|-----------|---------------|--------|
|| Login (authenticate + issue token + update last_activity) | **AuthenticateUserAction** | Unified action: throttle + findUser + accountState + emailVerification (UNVERIFIED_EMAIL for all modes except disabled). Strict — no Sanctum token or session for unverified users. |
|| Verify email | **VerifyEmailAction** — Web + API delegate | Shared action: markEmailAsVerified; controller adds mode check + audit. |
|| Resend verification | **ResendVerificationAction** — Web + API delegate | Shared action: rate limit + send notification; controller adds mode check + audit. |
|| Password change | **Action** (`ChangePasswordAction`) — already done | Complex: history check, revocation, audit. Shared pattern. |
|| Forgot/reset password | **Controller directly** — already done | Uses Laravel `Password` facade; thin wrapper. |
|| Unlock user | **Controller directly** — already done | Uses `LoginThrottle` dependency. |

---

## 3. Task Breakdown — Phase 3

Phase 3 splits into 5 groups. Each group is a self-contained batch that can be implemented, tested, and committed independently. Queue-eligible items are flagged `[QUEUE]`.

### Group A — Login Flow (API)

| ID | Task | Depends On | Est. | Notes |
|----|------|-----------|------|-------|
| AUTH-004 | **LoginFormRequest** — validate `identifier` (email|username) + `password` present; authorize() = true (guest) | — | small | Form Request; anti-enumeration: don't reveal whether identifier exists |
| AUTH-005 | **LoginController** — attempt auth; check `is_active`, `is_locked`, `email_verified_at`; on success: `LoginThrottle::reset()`, update `last_activity_at`, issue Sanctum token (plainTextToken), return token + user summary; on failure: `LoginThrottle::recordFailed()`, return generic error + lock time if locked | AUTH-004, LoginThrottle | medium | Uses `LoginThrottle` (already built). Must verify email per `MustVerifyEmail` — unverified users get 403 with clear message? Or allow login but restrict? Decision needed. |
| AUTH-006 | **Login tests** — success path, inactive user, locked user, wrong password, unverified email, rate-limited | AUTH-005 | medium | Pest/feature tests |

**Decision needed — AUTH-005:** `MustVerifyEmail` on User model. Laravel's default: `Auth::attempt()` succeeds even for unverified users; the `verified` middleware only blocks routes. Options:
- **A:** Allow login, but `password.change.required` middleware + route-level `verified` middleware restrict access. Unverified users can log in but hit a "verify your email" wall.
- **B:** Block login entirely in `LoginController` if `! $user->hasVerifiedEmail()` → logout immediately + error message.
- Current middleware `EnsurePasswordChangeRequired` does NOT check email verification. Need to decide and document. **Recommendation: Option A** (match Laravel default + add explicit verified middleware on protected routes) — less surprising, matches `MustVerifyEmail` semantics. But the roadmap says "enable MustVerifyEmail on User, routes + controller" which suggests Option B may have been intended. Jaya to decide.

### Group B — Logout Flow (API)

| ID | Task | Depends On | Est. | Notes |
|----|------|-----------|------|-------|
| AUTH-009 | **LogoutController** — revoke current Sanctum token; activity log `auth.logout` | AUTH-006 | tiny | `$request->user()->currentAccessToken()->delete()` |
| AUTH-010 | **LogoutAllController** — revoke ALL user tokens; activity log `auth.logout_all` | AUTH-009 | tiny | `$request->user()->tokens()->delete()` |
| — | **Logout tests** — logout, logout-all, token unusable after logout | AUTH-009, AUTH-010 | small | |

### Group C — Email Verification (API)

| ID | Task | Depends On | Est. | Notes |
|----|------|-----------|------|-------|
| AUTH-011a | **VerifyEmailController** — verify email via signed link; `VerifyEmailAction` shared; controller adds mode check + audit | AUTH-006 | small | Route already wired: `GET /api/v1/auth/email/verify/{id}/{hash}` with `signed` middleware | **COMPLIANT** |
| AUTH-011b | **ResendVerificationController** — resend via `ResendVerificationAction`; throttle; audit | AUTH-006 | small | Route already wired: `POST /api/v1/auth/email/resend` | **COMPLIANT** |
| — | **Verification tests** — signed link, rate limit, valid link, already verified, resend | AUTH-011a, AUTH-011b | small | 57 tests pass | **COMPLIANT** |

**[QUEUE] Group C queue-eligible:** Resend verification email can be queued via Notification on-demand queue. Controlled by `QUEUE_CONNECTION`: if `sync` → sends immediately; if `database`/`redis` → queued. Implementation: `$user->notify(new VerifyEmailNotification())->onQueue()` — but only if the notification supports it. Jaya to decide if this is worth the complexity for Phase 3 or defer to Phase 9 (Notifications).

### Group D — Web UI Auth Pages (AdminLTE)

|| ID | Task | Depends On | Est. | Status | Notes |
||----|------|-----------|------|--------|-------|
||| UI-AUTH-001 | **Login page** — `resources/views/pages/auth/login.blade.php` | UI-001 (done) | **COMPLIANT** | AdminLTE auth layout, @csrf, old(), @error, flash messages, double-click prevention |
||| UI-AUTH-002 | **AuthController— `App\Http\Controllers\Web\V1\Auth\AutAuthController-AUTH-001 | **COMPLIANT** | Thin controller, delegates to Actions, audit trail |
||| UI-AUTH-003 | **Forgot password page** — `resources/views/pages/auth/forgot-password.blade.php` | UI-AUTH-001 | **COMPLIANT** | @csrf, old(email), @error, flash success |
||| UI-AUTH-004 | **Web ForgotPasswordController** — POST `/forgot-password` | UI-AUTH-003 | **COMPLIANT** | SendPasswordResetLinkAction, try-catch mail failure |
||| UI-AUTH-005 | **Reset password page** — `resources/views/pages/auth/reset-password.blade.php` | UI-AUTH-001 | **COMPLIANT** | @csrf, old(email), @error password |
||| UI-AUTH-006 | **Web ResetPasswordController** — POST `/reset-password` | UI-AUTH-005 | **COMPLIANT** | ResetPasswordAction, audit `auth.password_reset_completed` |
||| UI-AUTH-007 | **Email verification notice** — `resources/views/pages/auth/verify-email.blade.php` | UI-AUTH-001 | **COMPLIANT** | Dual mode, @csrf, old(email), double-click prevention |
||| UI-AUTH-008 | **Web auth routes** — grouped public/guest/auth | UI-AUTH-002..007 | **COMPLIANT** | verified middleware, throttle:resend-verification |
||| UI-AUTH-009 | **Web auth tests** — 61 tests, 161 assertions | UI-AUTH-002..008 | **COMPLIANT** | Login, logout, forgot, reset, verify, resend, rate limit, mail failure |

**Note on web vs API auth:** Web uses Laravel's session-based auth (default `web` guard). API uses Sanctum tokens. These are separate flows. Web login creates a session cookie; API login returns a token. Both check the same User model fields (`is_active`, `is_locked`, etc.).

### Group E — Failed Login + Lock (COMPLIANT — tested)

| Task | Description | Status | Notes |
|------|-------------|--------|-------|
| AUTH-007 | Failed login tracking (LoginThrottle + FailedLoginAttempt model + migration + user_id) | — | **COMPLIANT** | Tracked in DB with identifier, ip_address, attempts, lock_count, locked_until, user_id (nullable) |
| AUTH-008 | Temporary lock enforcement (LoginThrottle + API 429 + Web redirect) | AUTH-007 | **COMPLIANT** | 429 + retry_after_seconds (API), redirect + flash (Web); exponential backoff 5→15→25 min |

**Audit verdict:** AUTH-007 + AUTH-008 COMPLIANT. Web + API aligned. Audit trail causer/subject never null. Tests: 62 pass (165 assertions).

### Group F — Rate Limiting Config (COMPLIANT — closed)

| ID | Task | Depends On | Est. | Notes | Status |
|----|------|-----------|------|-------|--------|
| RATE-001 | Rate limit definitions — `AuthServiceProvider::boot()` — `login` (5/min), `forgot-password` (3/min), `reset-password` (3/min), `resend-verification` (5/hour) via `config('rate_limits...')` | — | small | **COMPLIANT** | 4 RateLimiter defined, shared key via `LoginThrottle::key()` |
| RATE-002 | Rate limit tests — verify HTTP 429 after N attempts | RATE-001 | small | **COMPLIANT** | 5 tests: login (5), forgot (3), reset (3), resend web (6), resend API (6) |

**Throttle key:** `LoginThrottle::key()` = `sha1(lower(identifier) + '|' + ip)` — shared across Web + API, prevents bypass via header/casing tricks.

**Phase 3F closed — all 4 rate limiters 100% COMPLIANT across Web + API.**

**_RATE limit config location:** Laravel 11+ convention is `AuthServiceProvider::boot()` for auth-related rate limiters, or `AppServiceProvider::boot()`. Pick one and be consistent. Existing codebase: `AuthServiceProvider` exists but may be empty. Use it for auth rate limiters.

---

## 4. Queue Enable/Disable Strategy

The project already has `QUEUE_CONNECTION=database` in `.env.example` and `sync` driver available. The pattern for queue-eligible operations:

```php
// In a notification or job dispatch:
$user->notify(new VerifyEmailNotification())->onQueue();  // uses default queue connection
```

**Enable/disable is automatic via `.env`:**
- `QUEUE_CONNECTION=sync` → all `onQueue()` calls run synchronously (no queue worker needed)
- `QUEUE_CONNECTION=database` → jobs go to `jobs` table; run `php artisan queue:work` to process
- `QUEUE_CONNECTION=redis` → Redis backend

**Phase 3 queue-eligible items (flagged, not required):**

| Item | Queueable? | Recommendation |
|------|-----------|----------------|
| Verification email resend | Yes — `Notify` + `onQueue()` | **Defer to Phase 9** (Notifications). Phase 3 just needs the resend to work; queuing is a Phase 9 optimization. Don't pre-optimize. |
| Password reset email | Yes — `Password::sendResetLink` uses notifications internally | Laravel's built-in `Password::sendResetLink` already sends via the notification system. Queueability depends on the notification channel. Defer to Phase 9. |
| Audit log writes | Via `after_commit` dispatch | Phase 10 concern. Not Phase 3. |
| Login throttle | **No** — must be synchronous, real-time | RateLimiter is cache-backed, not queue. Correct as-is. |

**Ponytail verdict on queue in Phase 3:** Don't add queue wiring now. The infrastructure exists (`QUEUE_CONNECTION`, `jobs` table, `failed_jobs` table). When Phase 9 (Notifications) arrives, queue the email sends then. Phase 3 controllers should dispatch synchronously — simpler, testable, and the queue switch is a one-line env change when ready.

---

### 5. Task Tracker Reconciliation

The Phase 3 implementation breakdown is complete. The phase-level status remains `IN PROGRESS` until the tracker reconciliation is explicitly closed.

| ID | Current Status | Corrected Status | Action |
|----|---------------|-----------------|--------|
| AUTH-007 | PLANNED (Phase 5) | **DONE** (Phase 3) | Code exists: LoginThrottle + FailedLoginAttempt + migration |
| AUTH-008 | PLANNED (Phase 5) | **DONE** (Phase 3) | Code exists: LoginThrottle::isLocked/recordFailed/clearIfExpired |
| AUTH-004 | DONE | DONE | LoginFormRequest exists and is used by Web/API login |
| AUTH-005 | DONE | DONE | AuthenticateUserAction exists and is used by Web/API login |
| AUTH-006 | DONE | DONE | Sanctum token/session creation is implemented |
| AUTH-009 | DONE | DONE | LogoutController exists |
| AUTH-010 | DONE | DONE | LogoutAllController exists |
| AUTH-011 | DONE | DONE | VerifyEmailAction + ResendVerificationAction exist |
| AUTH-012 | DONE | DONE | SendPasswordResetLinkAction exists |
| AUTH-013 | DONE | DONE | ResetPasswordAction exists |
| AUTH-014 | DONE | DONE | ChangePasswordAction exists and Phase 5 lifecycle wiring is complete |
| RATE-001 | PLANNED (Phase 5) | **DONE** (Phase 3) | RateLimiter defined in AuthServiceProvider — 4 limiters: login (5/min), forgot-password (3/min), reset-password (3/min), resend-verification (5/hour) |
| RATE-002 | PLANNED (Phase 5) | **DONE** (Phase 3) | Rate limit tests — HTTP 429 verified: login (5), forgot (3), reset (3), resend web (6), resend API (6) |

---

## 6. Recommended Implementation Order

```
|--- UI FIRST (views + controllers) ---
1. UI-A-001..004  Auth views (login, forgot, reset, verify) + AuthControllerweb routes
--- UI COMPLETE; connect to API logic ---
2. UI-A-005  Wire forms to API endpoints (AdminLTE form POST → controller redirect)
--- API AUTH ---
3. AUTH-004  LoginFormRequest
4. RATE-001  Rate limiter definitions
5. AUTH-005  LoginController + last_activity_at update
6. AUTH-006  Login tests
7. AUTH-009  LogoutController
8. AUTH-010  LogoutAllController
9. —         Logout tests
10. AUTH-011a VerifyEmailController
11. AUTH-011b ResendVerificationController
12. —        Verification tests
--- API Phase 3 complete; commit ---
```

Each numbered group above is a natural commit boundary. Groups 1-10 = API auth. Groups 11-12 = Web UI auth. They can be committed separately.

---

## 7. Decisions Resolved

- RATE-001 remains in the authentication service/provider boundary and is verified.
- AUTH-007/AUTH-008 belong to Phase 3 and are reflected as DONE in the task tracker.
- Notification queue wiring remains deferred to Phase 9; synchronous delivery is the current baseline.
- Login accepts the configured email/username identifier.

---

## 8. Deferred Beyond Phase 3

| Item | Deferred To | Reason |
|------|------------|--------|
| Password history enforcement (PWD-003) | Phase 5 (now DONE) | Password history policy, settings UI, enforcement, and tests shipped |
| Password expiration enforcement (PWD-004) | Phase 5 (now DONE) | Expiry policy, forced-change screen, warning banner, sweeps, settings, and tests shipped |
| Inactivity lock (INACT-001) | Phase 5 (now DONE) | Login activity tracking, null grace policy, sweep, request-time lock/audit, and tests shipped |
| Admin user creation (USER-003) | Phase 4 | Depends on user management module |
| RBAC roles/permissions (RBAC-001..005) | Phase 6 | Spatie installed, seeder done, but management UI/API deferred |
| Feature flags enforcement (FEAT-001/002) | Phase 7 | Pennant installed, but per-module flags deferred |
| Audit trail integration (AUDIT-001..005) | Phase 10 | ActivityLog installed, `activity()` used in some controllers, but formal abstraction + UI deferred |
| API V1 resources/documentation (API-001..003) | Phase 12 | Scramble installed, but versioned API resource layer deferred |

---

*End of audit. Implementation can start with Group A (AUTH-004 → AUTH-005 → AUTH-006) once Jaya confirms the decisions in §7.*
