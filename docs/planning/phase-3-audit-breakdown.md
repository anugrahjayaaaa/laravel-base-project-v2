# Phase 3 Audit & Breakdown — Authentication Foundation

> Audit date: 2026-09-18 | Branch: feature/phase-3-authentication | Status: IN PROGRESS
> Purpose: break Phase 3 into small executable tasks, flag queue-eligible items, resolve Actions/Services question.

---

## 1. Current State Snapshot

### Already implemented (code exists, not yet reflected in task tracker)

|| File | What it does | Phase |
||------|-------------|-------|
|| `app/Auth/LoginThrottle.php` | RateLimiter + failed_login_attempts DB escalation; progressive lockout (5→15→25→… min); `isLocked`, `recordFailed`, `reset`, `lockedFor`, `clearIfExpired` | AUTH-007 / AUTH-008 |
|| `app/Models/FailedLoginAttempt.php` | Model for `failed_login_attempts` table; `nextLockoutMinutes()` progressive calc | AUTH-007 |
|| `database/migrations/…_create_failed_login_attempts_table.php` | Table migration | AUTH-007 |
|| `app/Http/Middleware/EnsurePasswordChangeRequired.php` | Blocks access when `must_change_password` or `password_expires_at` past; exempts password.change/verification/email.resend/logout; 403 JSON or 302 redirect | Phase 3 middleware |
|| `app/Actions/Auth/ChangePassword.php` | Shared password-change logic: validate current, history check, hash, expiration, revoke tokens, activity log | PWD-002/AUTH-014 |
|| `app/Http/Controllers/Api/V1/Auth/PasswordChangeController.php` | API password change → delegates to `ChangePassword` action | AUTH-014 |
|| `app/Http/Controllers/Api/V1/Auth/PasswordForgotController.php` | Forgot-password: `Password::sendResetLink`, always-same-response (anti-enumeration), activity log | AUTH-012 |
|| `app/Http/Controllers/Api/V1/Auth/PasswordResetController.php` | Reset-password: `Password::reset`, maps status, activity log | AUTH-013 |
|| `app/Http/Controllers/Api/V1/Auth/UnlockController.php` | Admin unlock: sets `is_locked=false`, resets throttle, activity log | (Phase 4/5 overlap) |
|| `app/Http/Controllers/Api/V1/Auth/LoginController.php` | API login: email/username lookup, throttle, audit, Sanctum token | AUTH-005 |
|| `app/Http/Controllers/Api/V1/Auth/LogoutController.php` | API logout: delete current token + audit | AUTH-009 |
|| `app/Http/Controllers/Api/V1/Auth/LogoutAllController.php` | API logout-all: delete all tokens + audit | AUTH-010 |
|| `app/Http/Controllers/Api/V1/Auth/VerifyEmailController.php` | API verify email: mark verified | AUTH-011a |
|| `app/Http/Controllers/Api/V1/Auth/ResendVerificationController.php` | API resend verification email | AUTH-011b |
|| `app/Http/Controllers/Web/Auth/WebAuthController.php` | Web auth: view rendering + login/logout logic + forgot/reset password + audit trail | UI-AUTH-002 |
|| `app/Http/Requests/Auth/LoginRequest.php` | Login form request: identifier + password validation | AUTH-004 |
|| `resources/views/layouts/auth.blade.php` | Auth layout: centered card, no sidebar/header | UI-AUTH-001 |
|| `resources/views/pages/auth/*.blade.php` | Auth views: login, forgot-password, reset-password, verify-email, verified | UI-AUTH-001,003,005,007 |
|| `routes/web.php` | Web routes: auth views via `Route::controller(WebAuthController::class)` | UI-AUTH-008 |

### Route registrations — all controllers now exist

All API controllers implemented. View rendering handled by WebAuthController (not inline web.php closures).

| Route | Controller | Status |
|-------|----------|--------|
| `POST /api/v1/auth/login` | `App\Http\Controllers\Api\V1\Auth\LoginController` | **DONE** |
| `POST /api/v1/auth/logout` | `App\Http\Controllers\Api\V1\Auth\LogoutController` | **DONE** |
| `POST /api/v1/auth/logout-all` | `App\Http\Controllers\Api\V1\Auth\LogoutAllController` | **DONE** |
| `GET /api/v1/auth/email/verify/{id}/{hash}` | `App\Http\Controllers\Api\V1\Auth\VerifyEmailController` | **DONE** |
| `POST /api/v1/auth/email/resend` | `App\Http\Controllers\Api\V1\Auth\ResendVerificationController` | **DONE** |
|| `GET /login` | `App\Http\Controllers\Web\Auth\WebAuthController@login` | **DONE** |
|| `POST /login` | `App\Http\Controllers\Web\Auth\WebAuthController@handleLogin` | **DONE** |
|| `GET /forgot-password` | `App\Http\Controllers\Web\Auth\WebAuthController@forgotPassword` | **DONE** |
|| `POST /forgot-password` | `App\Http\Controllers\Web\Auth\WebAuthController@sendResetLink` | **DONE** |
|| `GET /reset-password` | `App\Http\Controllers\Web\Auth\WebAuthController@resetPassword` | **DONE** |
|| `POST /reset-password` | `App\Http\Controllers\Web\Auth\WebAuthController@resetPasswordSubmit` | **DONE** |
|| `GET /verify-email` | `App\Http\Controllers\Web\Auth\WebAuthController@verifyEmail` | **DONE** |
|| `GET /email/verify/{id}/{hash}` | `App\Http\Controllers\Web\Auth\WebAuthController@verified` | **DONE** |
|| `POST /logout` | `App\Http\Controllers\Web\Auth\WebAuthController@logout` | **DONE** |

### Remaining (not yet implemented)

| ID | Task | Status |
|----|------|--------|
| UI-A-005 | Connect UI views to API logic (AdminLTE form POST → controller redirect) | **DONE** |

### Middleware wired

- `password.change.required` alias → `EnsurePasswordChangeRequired` registered in `bootstrap/app.php`
- `throttle:login` wired on web POST /login + API POST /auth/login (shared key via LoginThrottle::key)
- `throttle:forgot-password` wired on web POST /forgot-password, POST /reset-password + API POST /auth/password/forgot, POST /auth/password/reset (shared key)
- `throttle:resend-verification` on API POST /auth/email/resend

### Audit trail status

| Event | API | Web |
|-------|-----|-----|
| `auth.login` | LoginController ✅ | WebAuthController ✅ (channel=web) |
| `auth.logout` | LogoutController ✅ | WebAuthController ✅ |
| `auth.logout_all` | LogoutAllController ✅ | — |
| `auth.password_reset_requested` | PasswordForgotController ✅ | WebAuthController ✅ |
| `auth.password_reset_completed` | PasswordResetController ✅ | WebAuthController ✅ |
| `auth.verification_resent` | ResendVerificationController ✅ | — |

Both channels log audit at the mutation site per the audit pattern
(Controller logs directly for thin operations, no model observers).
Web audit uses `$this->audit()` from base Controller (same as API).

### Route grouping: public vs auth

**Web routes (`routes/web.php`):**

||| Group | Routes | Middleware |
|||-------|--------|-----------|
||| Public | `/`, `/login` (GET), `/forgot-password` (GET), `/reset-password` (GET), `/verify-email` (GET), `/email/verify/{id}/{hash}` (GET) | none |
||| Public (guest) | POST `/login`, POST `/forgot-password`, POST `/reset-password` | `throttle:login`, `throttle:forgot-password` |
||| Auth | `/dashboard`, POST `/logout` | `auth:sanctum`, `auth` |

**API routes (`routes/api.php`):**

|| Group | Routes | Middleware |
||-------|--------|-----------|
|| Public (guest) | POST `/auth/login`, POST `/auth/password/forgot`, POST `/auth/password/reset`, GET `/auth/email/verify/{id}/{hash}` | `throttle:login`, `throttle:forgot-password`, `signed` |
|| Protected | POST `/auth/logout`, POST `/auth/logout-all`, POST `/auth/email/resend`, POST `/auth/password/change` | `auth:sanctum`, `password.change.required` |

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
- Naming: `VerbNoun` (e.g. `ChangePassword`, `SendResetLink`, `RevokeUserTokens`)
- Extract when **either** the operation is non-trivial (>~10 lines of logic beyond
  simple delegation) **OR** the logic is shared across ≥2 controllers.
- Do NOT wrap a single trivial model call in an Action — inline it in the controller.
- Mutations that perform writes log audit within the action itself (the action has
  full context: causer, subject, properties). This keeps audit co-located with the
  mutation.
- Already has: `ChangePassword`

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
| Login (authenticate + issue token + update last_activity) | **Controller directly** (`LoginController`), using `LoginThrottle` as injected dependency | Thin (~5 lines with Sanctum); `LoginThrottle` encapsulates throttle/lock logic. No separate Action. |
| Logout (revoke current token) | **Controller directly** (`LogoutController`) | One-liner: `$request->user()->currentAccessToken()->delete()`. |
| Logout-all (revoke all tokens) | **Controller directly** (`LogoutAllController`) | One-liner: `$request->user()->tokens()->delete()`. |
| Verify email | **Controller directly** (`VerifyEmailController`) | Thin dispatch; Laravel's signed middleware handles most. |
| Resend verification | **Controller directly** (`ResendVerificationController`) | Thin: `$request->user()->sendEmailVerificationNotification()`. |
| Password change | **Action** (`ChangePassword`) — already done | Complex: history check, revocation, audit. Shared pattern. |
| Forgot/reset password | **Controller directly** — already done | Uses Laravel `Password` facade; thin wrapper. |
| Unlock user | **Controller directly** — already done | Uses `LoginThrottle` dependency. |

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
| AUTH-011a | **VerifyEmailController** — verify email via signed link; Laravel's built-in `VerifyEmailController` pattern or custom; on success: `email_verified_at` set, redirect/response | AUTH-006 | small | Route already wired: `GET /api/v1/auth/email/verify/{id}/{hash}` with `signed` middleware |
| AUTH-011b | **ResendVerificationController** — resend verification email; throttle; activity log | AUTH-006 | small | Route already wired: `POST /api/v1/auth/email/resend` |
| — | **Verification tests** — valid link, expired link, already verified, resend | AUTH-011a, AUTH-011b | small | |

**[QUEUE] Group C queue-eligible:** Resend verification email can be queued via Notification on-demand queue. Controlled by `QUEUE_CONNECTION`: if `sync` → sends immediately; if `database`/`redis` → queued. Implementation: `$user->notify(new VerifyEmailNotification())->onQueue()` — but only if the notification supports it. Jaya to decide if this is worth the complexity for Phase 3 or defer to Phase 9 (Notifications).

### Group D — Web UI Auth Pages (AdminLTE)

| ID | Task | Depends On | Est. | Notes |
|----|------|-----------|------|-------|
|| UI-AUTH-001 | **Login page** — `resources/views/auth/login.blade.php` using AdminLTE auth layout; identifier + password + submit + forgot-password link; `@error` blocks; i18n-ready (static text for now) | UI-001 (done) | medium | Matches existing AdminLTE auth layout patterns from Phase 1 |
|| UI-AUTH-002 | **WebAuthController** — `App\Http\Controllers\Web\Auth\WebAuthController`; handles all auth view rendering (login, forgot, reset, verify, verified); routes via `Route::controller()` in web.php | UI-AUTH-001 | small | Controller-based view handling per project convention; NO inline closures in web.php |
|| UI-AUTH-003 | **Forgot password page** — `resources/views/auth/forgot-password.blade.php`; email input + submit | UI-AUTH-001 | small | |
|| UI-AUTH-004 | **Web ForgotPasswordController** — POST `/forgot-password`; uses `Password::sendResetLink`; back with success/error | UI-AUTH-003 | small | |
|| UI-AUTH-005 | **Reset password page** — `resources/views/auth/reset-password.blade.php`; email + token + password + confirm + submit | UI-AUTH-001 | small | |
|| UI-AUTH-006 | **Web ResetPasswordController** — POST `/reset-password`; uses `Password::reset`; redirect on success | UI-AUTH-005 | small | |
|| UI-AUTH-007 | **Email verification notice page** — `resources/views/auth/verified.blade.php` or `resources/views/auth/verify-email.blade.php`; "check your email" message + resend link | UI-AUTH-001 | small | |
|| UI-AUTH-008 | **Web auth routes** — `routes/web.php`: explicit routes via `Route::controller(WebAuthController::class)`; scaffolded per project convention | UI-AUTH-002..007 | small | Explicit routes preferred (matches project convention §14) |
|| UI-AUTH-009 | **Web auth tests** — login success/fail, logout, forgot password flow, protected route redirect | UI-AUTH-002..008 | medium | |

**Note on web vs API auth:** Web uses Laravel's session-based auth (default `web` guard). API uses Sanctum tokens. These are separate flows. Web login creates a session cookie; API login returns a token. Both check the same User model fields (`is_active`, `is_locked`, etc.).

### Group E — Failed Login + Lock (already largely built)

| ID | Task | Depends On | Status | Notes |
|----|------|-----------|--------|-------|
| AUTH-007 | Failed login tracking (LoginThrottle + FailedLoginAttempt model + migration) | — | **DONE** (code exists) | Update task tracker to DONE |
| AUTH-008 | Temporary lock enforcement (integrated into LoginController via LoginThrottle) | AUTH-007 | **DONE** (LoginThrottle complete) | Update task tracker to DONE; lock enforcement fires when LoginController calls `recordFailed()` + checks `isLocked()` |

**Correction:** AUTH-007 and AUTH-008 are listed as Phase 5 in the task tracker JSON, but the code is already built and the roadmap lists them under Phase 3. **Reconcile: mark AUTH-007 + AUTH-008 as DONE and attribute to Phase 3** (or keep Phase 5 label if Jaya prefers the phase split — but the code is here now, so the tracker should reflect reality).

### Group F — Rate Limiting Config

| ID | Task | Depends On | Est. | Notes |
|----|------|-----------|------|-------|
| RATE-001 | **Rate limit definitions** — define `login`, `forgot-password`, `resend-verification` throttle configs in `AuthServiceProvider::boot()` or `AppServiceProvider::boot()` using `RateLimiter::for()` | — | small | Routes reference `throttle:login` etc. but the named limit must be defined. Currently may be falling back to default. |
| RATE-002 | **Rate limit tests** — verify login throttle kicks in after N attempts | RATE-001 | small | |

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

## 5. Task Tracker Reconciliation

Items needing status updates in `docs/planning/task-tracker.md`:

| ID | Current Status | Corrected Status | Action |
|----|---------------|-----------------|--------|
| AUTH-007 | PLANNED (Phase 5) | **DONE** (Phase 3) | Code exists: LoginThrottle + FailedLoginAttempt + migration |
| AUTH-008 | PLANNED (Phase 5) | **DONE** (Phase 3) | Code exists: LoginThrottle::isLocked/recordFailed/clearIfExpired |
| AUTH-004 | PLANNED | PLANNED | Needs LoginFormRequest |
| AUTH-005 | PLANNED | PLANNED | Needs LoginController |
| AUTH-006 | PLANNED | PLANNED | Session creation — subsumed into LoginController (Sanctum token issuance) |
| AUTH-009 | PLANNED | PLANNED | Needs LogoutController |
| AUTH-010 | PLANNED | PLANNED | Needs LogoutAllController |
| AUTH-011 | PLANNED | PLANNED (split into 011a/011b) | Needs VerifyEmailController + ResendVerificationController |
| AUTH-012 | PLANNED | **DONE** (code exists) | PasswordForgotController exists |
| AUTH-013 | PLANNED | **DONE** (code exists) | PasswordResetController exists |
| AUTH-014 | PLANNED (Phase 5) | **DONE** (Phase 5 code exists, Phase 3 middleware done) | ChangePassword action + ApiPasswordChangeController exist; phase 5 password policy tasks still pending |
| RATE-001 | PLANNED (Phase 5) | PLANNED | Needs rate limiter definitions |
| RATE-002 | PLANNED (Phase 5) | PLANNED | Needs tests |

---

## 6. Recommended Implementation Order

```
|--- UI FIRST (views + controllers) ---
1. UI-A-001..004  Auth views (login, forgot, reset, verify) + WebAuthController + web routes
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

## 7. Decisions Needed from Jaya

1. **Email verification on login:** Option A (allow login, block routes) or Option B (block login entirely)? Affects `LoginController` implementation.
2. **RATE-001 location:** `AuthServiceProvider::boot()` or `AppServiceProvider::boot()` for rate limiter definitions?
3. **Phase attribution for AUTH-007/AUTH-008:** Keep as Phase 5 in tracker (even though code is done) or move to Phase 3 DONE?
4. **Queue in Phase 3:** Defer all queue-eligible email sends to Phase 9? (Recommendation: yes.)
5. **Login identifier:** Email only, or email + username? Current `LoginThrottle` scopes by `identifier` (string), so either works. FormRequest validation rule determines what's accepted. Jaya to decide.

---

## 8. What's NOT in Phase 3 (deferred)

| Item | Deferred To | Reason |
|------|------------|--------|
| Password history enforcement (PWD-003) | Phase 5 | ChangePassword action has the check wired but `password_histories` table + policy config still needed |
| Password expiration enforcement (PWD-004) | Phase 5 | `password_expires_at` field exists; middleware checks it; full policy + expiration job deferred |
| Inactivity lock (INACT-001) | Phase 5 | `last_activity_at` field exists; tracking + lock job deferred |
| Admin user creation (USER-003) | Phase 4 | Depends on user management module |
| RBAC roles/permissions (RBAC-001..005) | Phase 6 | Spatie installed, seeder done, but management UI/API deferred |
| Feature flags enforcement (FEAT-001/002) | Phase 7 | Pennant installed, but per-module flags deferred |
| Audit trail integration (AUDIT-001..005) | Phase 10 | ActivityLog installed, `activity()` used in some controllers, but formal abstraction + UI deferred |
| API V1 resources/documentation (API-001..003) | Phase 12 | Scramble installed, but versioned API resource layer deferred |

---

*End of audit. Implementation can start with Group A (AUTH-004 → AUTH-005 → AUTH-006) once Jaya confirms the decisions in §7.*
