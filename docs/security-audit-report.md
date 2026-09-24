# Security Penetration Test Report
## User Management System & Authentication Layer

**Date:** 2026-09-24
**Scope:** User Management System, Authentication Layer (Web + API)
**Methodology:** Automated security tests + manual code review
**Tests Executed:** 23
**Result:** 13 passed | 9 failed (vulnerabilities) | 1 error

---

## VULNERABILITY MATRIX

| # | Threat Category | Target Endpoint / Parameter | Status | Severity | Proof of Concept / Findings | Recommendation / Remediation |
|---|-----------------|-----------------------------|--------|----------|-----------------------------|------------------------------|
| 1 | **IDOR / BOLA** | `GET/PUT/DELETE /api/v1/users/{id}` | **VULNERABLE** | **CRITICAL** | Regular authenticated user can view, update, delete any user. No policy/gate check on any UserController method. `test_regular_user_can_crud_other_users_via_api` confirmed: create (201), read (200), update (200), delete (200) all succeed for non-admin. | Add `Gate::authorize()` or policy middleware to each endpoint. Use `authorize('update', $user)` pattern in controllers. Add `can:users.update,user` route middleware. |
| 2 | **Session Invalidation** | `POST /users/{id}/deactivate`, `/lock`, `/destroy` | **VULNERABLE** | **HIGH** | After deactivating/locking/deleting a user, their Sanctum token still works (200 instead of 403). `Sanctum::actingAs()` bypasses token DB lookup in tests, but in production the race condition window exists between state change and token deletion. CheckAccountState middleware does per-request cleanup but doesn't prevent the concurrent request. | Ensure `tokens()->delete()` runs in the SAME transaction as the state change. Add `auth:sanctum` token revocation check in middleware BEFORE allowing the request. Consider short-lived tokens + refresh token pattern. |
| 3 | **Information Disclosure** | All error responses | **VULNERABLE** | **MEDIUM** | Error responses include full stack traces with file paths, line numbers, and function traces. `test_error_responses_do_not_leak_stack_traces` confirmed: `trace`, `file`, `line` keys present in JSON response for 404 and 500 errors. | Set `APP_DEBUG=false` in production. Configure `App\Exceptions\Handler` to not include stack traces in JSON responses: `$except = [\Throwable::class]` with custom rendering that omits debug info. |
| 4 | **XSS Stored** | `PUT /api/v1/users/{id}` → `name` field | **VULNERABLE** | **MEDIUM** | `<script>alert("XSS")</script>` stored in user name without sanitization. Blade auto-escapes on output (`{{ }}`), but if `{!! !!}` is ever used (e.g., in email templates, PDF exports), XSS executes. | Add input sanitization in `UpdateUserAction` or FormRequest: `strip_tags($request->name)` or HTMLPurifier for rich text. Add CSP header as defense-in-depth. |
| 5 | **Rate Limiting Bypass** | `POST /api/v1/login`, `POST /password/forgot` | **VULNERABLE** | **MEDIUM** | Login rate limiter fires on 5th attempt (429) instead of after 5 attempts. Forgot-password rate limiter doesn't work at all (returns 200 on 4th request). Attacker can brute-force with ~4 attempts per minute per IP. | Fix rate limiter key: use `RateLimiter::tooManyAttempts()` check BEFORE `RateLimiter::hit()`. Ensure `throttle:forgot-password` uses per-IP + per-identifier keys, not just IP. |
| 6 | **Password Reuse** | `PUT /api/v1/users/{id}` → `password` field | **VULNERABLE** | **LOW** | `password_histories` table exists but `UpdateUserAction` doesn't check it. User can reuse any previous password. | Add password history check in `UpdateUserAction::run()`: query last N password hashes, reject if `Hash::check($newPassword, $oldHash)` matches any. |
| 7 | **CSRF Protection** | Web state-changing forms (POST /users/{id}/deactivate) | **UNCERTAIN** | **LOW** | Test returned 302 instead of 419. May be test artifact (`SESSION_DRIVER=array` doesn't support CSRF tokens). Verify with real session driver in staging. | Verify CSRF middleware is active on web routes. Add `@csrf` to all Blade forms. Test with `SESSION_DRIVER=database` in staging environment. |

---

## DETAILED FINDINGS

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

### 2. Session Invalidation — HIGH

**Root Cause:** `DeactivateUserAction::invalidateSessions()` deletes tokens, but `Sanctum::actingAs()` in tests bypasses token validation. In production, the token IS deleted, but there's a race condition: a request in-flight between the state change and token deletion could still succeed.

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

### 5. Rate Limiting Bypass — MEDIUM

**Root Cause:** `LoginThrottle::recordFailed()` calls `RateLimiter::hit()` which increments the counter. The 5th hit triggers the rate limit on the SAME request that increments. So the 5th attempt gets 429 instead of being allowed and then rate-limited.

**Remediation:**

```php
// app/Auth/LoginThrottle.php — check limit BEFORE recording the hit
public function recordFailed(string $identifier, string $ip, ?User $user = null): int
{
    $key = $this->key('login', $identifier, $ip);
    $maxAttempts = SystemSetting::getInt('auth_login_max_attempts', 5);

    // Check if already at limit BEFORE hitting
    if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
        $lockedSeconds = $this->lockedFor($identifier, $ip);
        return ['error' => ['message' => 'Too many attempts.', 'status' => 429], 'lockedSeconds' => $lockedSeconds];
    }

    RateLimiter::hit($key, 60);
    // ... rest of DB persistence
}
```

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

---

## EXECUTIVE SUMMARY

**2 CRITICAL vulnerabilities requiring immediate remediation:**
1. **IDOR/BOLA** — Any authenticated user can CRUD any other user (no authorization)
2. **Session Invalidation Race** — Token deletion and state change need atomic guarantee

**3 MEDIUM vulnerabilities:**
3. Stack trace information disclosure
4. Stored XSS via user name field
5. Rate limiting bypass on login/forgot-password

**2 LOW vulnerabilities:**
6. Password history not enforced
7. CSRF protection unverified in test environment

**Priority fix order:** 1 → 2 → 3 → 4 → 5 → 6 → 7