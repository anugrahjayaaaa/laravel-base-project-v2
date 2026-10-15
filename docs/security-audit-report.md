# Security Penetration Test Report
## User Management System & Authentication Layer

**Date:** 2026-09-24
**Scope:** User Management System, Authentication Layer (Web + API)
**Methodology:** Automated security tests + manual code review
**Tests Executed:** 23
**Result at the time:** 13 passed | 9 failed (vulnerabilities) | 1 error

> **Re-verified 2026-10-15 — all seven findings classified below as
> vulnerabilities are REMEDIATED**, each pinned by a regression test that fails
> the moment the control is removed. The pentest assertions were inverted rather
> than deleted: `test_regular_user_can_crud_other_users_via_api` became
> `..._cannot_crud_...`. This document therefore keeps recording what was true on
> 2026-09-24, and each detail section ends with what is in the code today.
>
> The "9 failed" above is as-reported. The matrix and the detail sections cover
> the SEVEN findings that were classified and written up as vulnerabilities.
> In three cases (#2, #3, #6) the shipped fix is not the shape the remediation
> code below proposed — those blocks say so explicitly.

---

## VULNERABILITY MATRIX

| # | Threat Category | Target Endpoint / Parameter | Severity | Status (2026-10-15) | Proof of control |
|---|-----------------|-----------------------------|----------|---------------------|------------------|
| 1 | **IDOR / BOLA** | `GET/PUT/DELETE /api/v1/users/{id}` | CRITICAL | **FIXED** | `SecurityAuditTest::test_regular_user_cannot_crud_other_users_via_api` |
| 2 | **Session Invalidation** | `POST /users/{id}/deactivate`, `/lock`, `/destroy` | HIGH | **FIXED** | `SecurityAuditTest::test_api_token_revoked_when_user_deactivated` (+ locked, soft-deleted and web-session variants) |
| 3 | **Information Disclosure** | All error responses | MEDIUM | **FIXED (API)** | `SecurityAuditTest::test_error_responses_do_not_leak_stack_traces` |
| 4 | **XSS Stored** | `PUT /api/v1/users/{id}` → `name` | MEDIUM | **FIXED** | `SecurityAuditTest::test_stored_xss_payload_in_user_name`, `CsvInjectionPentestTest::test_a_formula_in_a_display_name_is_inert_in_the_csv` |
| 5 | **Rate Limiting Bypass** | `POST /api/v1/login`, `POST /password/forgot` | MEDIUM | **FIXED** | `SecurityAuditTest::test_api_login_rate_limits_after_5_failed_attempts`, `...::test_forgot_password_rate_limited_per_identifier` |
| 6 | **Password Reuse** | `PUT /api/v1/users/{id}` → `password` | LOW | **FIXED** | `PasswordHistoryTest::test_prevents_reuse_of_recent_passwords` |
| 7 | **CSRF Protection** | Web state-changing forms | LOW | **FIXED** | `SecurityAuditTest::test_web_state_changing_forms_require_csrf` |

---

## DETAILED FINDINGS

The `Root Cause` and `Remediation Code` text under each finding is the record as
it stood on 2026-09-24 and is deliberately left unedited; what shipped instead,
where it differs, is the **Remediated (2026-10-15)** block that closes each
section. Sections 6 and 7 did not exist in the original report — the matrix row
was all they got — and were written on re-verification.

### 1. IDOR / BOLA — CRITICAL

**Root Cause:** `UserController` has zero authorization checks. No policy, no gate, no `can:` middleware on any CRUD endpoint.

```php
// app/Http/Controllers/Api/V1/User/UserController.php
// NO authorization on any method:
public function show(User $user): JsonResponse  // ← Any authed user can read any user
public function update(UpdateUserRequest $request, User $user): JsonResponse  // ← Any authed user can update any user
public function destroy(Request $request, User $user): JsonResponse  // ← Any authed user can delete any user
```

**Remediation Code:**

```php
// Option A: Add policy middleware to routes
Route::resource('users', UserController::class)
    ->middleware('can:users,user')
    ->only(['index', 'show', 'update', 'destroy']);

// Option B: Add authorize() calls in controller
public function show(User $user): JsonResponse
{
    $this->authorize('view', $user);
    // ...
}

// Policy (app/Policies/UserPolicy.php) — add view/update/delete methods:
public function view(User $user, User $target): Response
{
    return $user->can('users.view')
        ? Response::allow()
        : Response::deny('No permission to view users.');
}
```

**Remediated (2026-10-15).** Not one of the two options sketched above, but the
same control applied at two layers. Every users API route carries `can:` gates —
`routes/api.php` (`users.view` on index/show, then `users.create`,
`users.update`, `users.delete`, `users.force_delete`, `users.restore`, and the
state routes `users.activate` / `deactivate` / `lock` / `unlock`), all inside
`feature:users`. `UserPolicy::view` / `update` / `delete` resolve to the same
permissions, so the route gate and the policy cannot disagree. The write paths
this report did not enumerate are closed too: `UpdateUserRequest::authorize()`
requires `users.update` (or self, and only without touching roles), the
email-change endpoint is self-or-`users.update`, and `PUT /users/{id}` marks
`password` `prohibited` — an admin update cannot set one at all.

### 2. Session Invalidation — HIGH

**Root Cause:** `UserDeactivateAction::invalidateSessions()` deletes tokens, but `Sanctum::actingAs()` in tests bypasses token validation. In production, the token IS deleted, but there's a race condition: a request in-flight between the state change and token deletion could still succeed.

**Remediation Code:**

```php
// app/Http/Middleware/CheckAccountState.php — add token revocation check
// BEFORE the request proceeds:
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if (! $user) return $next($request);

    // Re-fetch user from DB to get latest state
    $freshUser = User::find($user->id);
    if (! $freshUser || ! $freshUser->is_active || $freshUser->is_locked || $freshUser->trashed()) {
        // Revoke ALL tokens for this user immediately
        if ($freshUser) {
            $freshUser->tokens()->delete();
            DB::table('sessions')->where('user_id', $freshUser->id)->delete();
        }
        // Return 401 for API, redirect for web
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Account disabled.', 'code' => 'ACCOUNT_DISABLED'], 401);
        }
        auth()->logout();
        return redirect()->route('login')->with('error', 'Account deactivated or locked.');
    }
    return $next($request);
}
```

**Remediated (2026-10-15).** Atomicity instead of a second check.
`UserDeactivateAction`, `UserLockAction` and `UserDeleteAction` delete the
Sanctum tokens and the session rows INSIDE the same `DB::transaction` that
writes the state change — the delete action also nulls `remember_token`. The
race this finding names is closed a second time on the read side:
`CheckAccountState` re-fetches the user on every request and revokes tokens and
sessions before answering 403 (API) or redirecting to login (web), so a request
that slips past the transaction still cannot ride a dead account.
`UserForceDeleteAction` deliberately does not re-invalidate: the row was already
trashed — already invalidated — and the middleware rejects trashed users.
Pinned by `SecurityAuditTest::test_api_token_revoked_when_user_deactivated`,
`..._when_user_locked`, `..._when_user_soft_deleted` and
`...::test_web_session_invalidation_on_deactivation_via_checkaccountstate`.

### 3. Information Disclosure — MEDIUM

**Root Cause:** `APP_DEBUG=true` in `.env` causes Laravel to include stack traces in error responses.

**Remediation:**

```bash
# .env — production
APP_DEBUG=false
```

```php
// app/Exceptions/Handler.php — also suppress traces in JSON responses
public function render($request, Throwable $e)
{
    if ($request->is('api/*') && ! config('app.debug')) {
        return response()->json([
            'message' => 'Server error.',
            'code' => 'SERVER_ERROR',
        ], 500);
    }
    return parent::render($request, $e);
}
```

**Remediated (2026-10-15), narrower than proposed and on purpose.** There is no
`app/Exceptions/Handler.php`; the equivalent lives in `bootstrap/app.php`
`withExceptions()`, which answers EVERY throwable on `api/*` — validation 422,
auth 401/403, 404, 429 and a generic 500 — with `{message, code}`. No `trace`,
`file` or `line` key exists in the payload, which is what
`SecurityAuditTest::test_error_responses_do_not_leak_stack_traces` asserts.

**Residual, carried consciously:** the suppression is conditioned on
`config('app.debug')`. A deployment that ships `APP_DEBUG=true` reopens this
finding — the control is the environment value, not the code. Web HTML errors
use the framework's debug page for the same reason.

### 4. XSS Stored — MEDIUM

**Root Cause:** User `name` field accepts HTML without sanitization. Stored as-is in DB.

**Remediation:**

```php
// app/Http/Requests/User/UpdateUserRequest.php — add sanitization
public function validated($key = null, $default = null): array
{
    $validated = parent::validated();
    if (isset($validated['name'])) {
        $validated['name'] = strip_tags($validated['name']);
    }
    return $validated;
}
```

**Remediated (2026-10-15), in three layers rather than one.**

1. `strip_tags()` on `name` at the WRITE layer — `UserUpdateAction` and
   `UserCreateAction`; the self-service profile goes through the same action.
2. The CSV this system exports neutralizes formula cells at the source
   (`GenerateAuditExport::cell()`, CWE-1236): a leading `=`, `+`, `-` or `@`
   after whitespace is prefixed with `'`, so a display name is inert in Excel.
3. `AddSecurityHeaders` ships the "defense-in-depth" the recommendation
   asked for, and more than it asked: every HTML response carries a Content-
   Security-Policy whose `script-src` is a per-request nonce with no
   `'unsafe-inline'`, so an injected `<script>` does not run even if a `{!! !!}`
   appears somewhere later. Recorded in `docs/base/security/web-security.md`,
   with the view-side rules in `docs/base/ui/design-system.md`.

### 5. Rate Limiting Bypass — MEDIUM

**Root Cause:** `LoginThrottle::recordFailed()` calls `RateLimiter::hit()` which increments the counter. The 5th hit triggers the rate limit on the SAME request that increments. So the 5th attempt gets 429 instead of being allowed and then rate-limited.

**Remediation:**

```php
// app/Auth/LoginThrottle.php — check limit BEFORE recording the hit
public function recordFailed(string $identifier, string $ip, ?User $user = null): int
{
    $key = $this->key('login', $identifier, $ip);
    $maxAttempts = SystemSetting::getInt('login_max_attempts', 5);

    // Check if already at limit BEFORE hitting
    if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
        $lockedSeconds = $this->lockedFor($identifier, $ip);
        return ['error' => ['message' => 'Too many attempts.', 'status' => 429], 'lockedSeconds' => $lockedSeconds];
    }

    RateLimiter::hit($key, 60);
    // ... rest of DB persistence
}
```

**Remediated (2026-10-15), in the order the recommendation asks for.**
`AuthAuthenticateAction::checkThrottle()` consults `LoginThrottle::isLocked()`
(`RateLimiter::tooManyAttempts`) BEFORE authentication runs, and `recordFailed()`
hits only after a failed attempt — so attempts 1–5 answer 401 and the sixth
answers 429, which is what
`SecurityAuditTest::test_api_login_rate_limits_after_5_failed_attempts` pins.
Forgot-password is throttled on both channels (`throttle:forgot-password` on the
web and the API route) and the limiter keys on the submitted identifier AND the
IP, so spraying identifiers does not reset the counter —
`...::test_forgot_password_rate_limited_per_identifier` asserts three accepts
then a 429. Web login carries `throttle:login`. The API login route has no
`throttle:` middleware: its ceiling is the in-action `LoginThrottle`, which the
same test proves effective.

### 6. Password Reuse — LOW

Not written up in detail on 2026-09-24; the matrix row was the whole finding.
`password_histories` existed then and nothing read it.

**Remediated (2026-10-15) — the feature now exists end to end.**
`AuthChangePasswordAction` rejects a password matching any of the last N (N is
the `password_history_count` setting) and `AuthRecordPasswordHistoryAction`
records and prunes. Both keys (`password_history_enabled`,
`password_history_count`) are seeded, editable in the settings UI and validated
at the request layer. The original vector is closed at the boundary regardless:
`password` is `prohibited` on `UpdateUserRequest`, so
`PUT /api/v1/users/{id}` cannot set one at all. Pinned by
`PasswordHistoryTest::test_prevents_reuse_of_recent_passwords` and
`test_allows_reuse_after_n_changes`, with 15 further cases in
`PasswordHistoryPentestTest` (including the API guard).

### 7. CSRF Protection — LOW

Not written up in detail on 2026-09-24 either, and the "UNCERTAIN" verdict was
the finding: a web POST returned 302 instead of 419, which looked like an
artifact.

**Remediated (2026-10-15), and the artifact is named.** Laravel's own
`VerifyCsrfToken::runningUnitTests()` bypasses the check whenever the framework
believes a test is running — with `SESSION_DRIVER=array` that is every test
here, which is exactly why the check appeared not to fire. The project's
middleware overrides `runningUnitTests()` to `false` and is appended to the web
group, so a missing token answers 419 in tests precisely as it does in
production. Pinned by
`SecurityAuditTest::test_web_state_changing_forms_require_csrf` and
`FeatureFlagPentestTest`.

**Housekeeping (cleared 2026-10-15):** the per-request debug `error_log` line this
middleware carried is gone. The class now holds only its two properties and the
`runningUnitTests()` override, with a docblock stating why that override must
stay — so the next reader removes it knowing what it was for.

---

## SECURE AREAS (Tests Passed)

| Control | Status | Evidence |
|---------|--------|----------|
| Anti-enumeration on login | SECURE | Same error message for existing/non-existing users |
| Resend verification rate limit | SECURE | 429 after 5 requests per hour |
| Unauthenticated API access blocked | SECURE | 401 on all protected routes |
| Mass assignment protection | SECURE | `is_active`, `is_locked`, `must_change_password` ignored (not in `$fillable`) |
| Signed URL tampering rejection | SECURE | Tampered URL returns 403 |
| XSS reflected prevention | SECURE | JSON responses escape `<img onerror>` |
| Password minimum length | SECURE | 422 for passwords < 8 chars |
| must_change_password enforcement | SECURE | 403 when flag is true |
| SQL injection prevention (sort) | SECURE | Validation rejects invalid sort values |

Controls that did not exist on 2026-09-24 and are therefore not part of the 13
passes above — all shipped later, each with its own regression test:

| Control | Shipped | Evidence |
|---------|---------|----------|
| Content-Security-Policy on HTML responses | 2026-10-15 (`5dfe21f`) | `ContentSecurityPolicyTest` — nonce-only `script-src`, every inline script carries the header's nonce, no inline handler survives |
| CSV formula injection neutralized (CWE-1236) | 2026-10-14 (`3a163c4`) | `CsvInjectionPentestTest` — `=`, `+`, `-`, `@` cells are inert in the export |
| Export retry race closed (atomic claim) | 2026-10-14 (`13e677e`) | `AuditExportTest` — the loser of the conditional UPDATE gets 409 and dispatches nothing |

---

## EXECUTIVE SUMMARY

**Re-verified 2026-10-15: 0 open findings.** All seven vulnerabilities the
2026-09-24 run classified are remediated, and each is pinned by a regression
test that asserts the OPPOSITE of the original claim — the pentest assertions
were inverted (`..._can_crud_other_users_via_api` → `..._cannot_crud_...`),
not deleted, so removing a control fails the suite instead of leaving a hole
nobody notices. Three shipped fixes differ in shape from the remediation code
this report proposed (#2, #3, #6); each block above says what actually runs.

**Residual risk, carried consciously:**

1. **#3 depends on the environment, not the code.** Stack-trace suppression on
   `api/*` applies while `APP_DEBUG` is false; a deployment shipping
   `APP_DEBUG=true` reopens the finding.
2. **#7 depends on one override staying put.**
   `VerifyCsrfToken::runningUnitTests()` returning `false` is what makes the CSRF
   check hold under test. Delete it and the suite stays green while the check
   silently stops being exercised — which is why the class now says so in a
   docblock, and the test pins it.

**Later reviews, outside this report:** the 2026-10-14 pentest of the
audit-trail module found CSV formula injection and an export retry race (both
fixed), plus the missing CSP — the "defense-in-depth" #4 recommended, shipped
2026-10-15.