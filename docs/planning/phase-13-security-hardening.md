# Phase 13 — Security Hardening

> Date: 2026-10-26 | Status: **IN PROGRESS** — Group A **DONE** (owner-reviewed 2026-10-27); Groups B–E not started | Branch: `feature/phase-13-security-hardening`
> Purpose: close the remaining browser-facing and input-facing attack surface, and turn
> the controls that already shipped into a suite that fails when one of them regresses.
> Dependency chain: **A → B → C → D → E**. A and B are verification-heavy because the
> controls exist; C and D1 carry the only real construction in the phase.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/security/` — a task that wants to contradict it needs an
> ADR in `docs/planning/decisions.md`, not a code change.

---

## 1. Scope

Fourteen tasks in five groups:

| Group | Subject | Nature |
|---|---|---|
| A | CSP & security headers + nonce injection | **Verify + extend tests** — middleware and nonce plumbing shipped with Phase 10 |
| B | CSRF & state-mutation protection | **Verify + extend tests** — strict CSRF already ships |
| C | CORS & API hardening | **Build** — no `config/cors.php` exists |
| D | Input sanitization, validation, parameter tampering | **Audit + targeted hardening** — blanket stripping is rejected (D-4) |
| E | Information disclosure, rate limiting, suite run | **Verify + measure** — masking and composite keys already ship |

Not in scope: WAF, dependency CVE sweep, secret rotation, penetration-test tooling,
`Content-Security-Policy-Report-Only` rollout, SRI for CDN assets, i18n of security copy.

---

## 2. What already exists (measured 2026-10-26, before writing any task)

This phase is mostly an **audit-and-close**, not greenfield. Measured, not assumed:

| Control | Evidence | State |
|---|---|---|
| Security headers middleware | `App\Http\Middleware\AddSecurityHeaders`, appended **globally** in `bootstrap/app.php` | Present |
| CSP with per-request nonce | `AddSecurityHeaders::policy()` — `default-src 'self'`, `script-src 'self' 'nonce-…'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'` | Present, every response |
| HSTS / nosniff / referrer / permissions | Same class, on **every** response incl. JSON and the streamed CSV | Present |
| Nonce at the call site | `@nonce` Blade directive + shared `$cspNonce` + `Vite::useCspNonce()` (`AppServiceProvider`) | Present — 12 views use it |
| Unescaped output | 5 `{!! !!}` sites: 3 sort icons (`pages/users/index`, constant markup), 2 vendor Pulse emitters | Audited, allowlisted by test |
| Header tests | `tests/Feature/Security/SecurityHeadersTest.php` (5), `ContentSecurityPolicyTest.php` (10) | Present |
| Strict CSRF under test | `App\Http\Middleware\VerifyCsrfToken::runningUnitTests()` returns `false` | Present |
| CSRF form probe | `SecurityAuditTest::test_web_state_changing_forms_require_csrf` | Present — one form |
| CSRF meta tag | `layouts/app`, `layouts/auth`, `layouts/guest` | Present |
| CORS config | **absent** — `config/cors.php` does not exist; `HandleCors` is in Laravel's global stack but with no configured paths, so no CORS headers are emitted | **Gap (Group C)** |
| FormRequest validation | Every write route; no inline `$request->validate()` | Present, arch-enforced |
| Mass-assignment shielding | `#[Fillable([...])]` on `User`; `$guarded = ['*']` on `Activity`; no `$guarded = []` anywhere | Present |
| Sort/filter whitelisting | `SORTABLE` const + `in_array(..., true)` in `UserIndexAction`, `RoleIndexAction`, `PermissionIndexAction` | Present — three actions |
| Production error masking | `bootstrap/app.php` `renderable()` — envelope always, `debug` key added only when `APP_DEBUG=true` | Present |
| Error-leak probe | `SecurityAuditTest::test_error_responses_do_not_leak_stack_traces` (JSON only) | Partial — no HTML assertion |
| Composite throttle keys | `AuthServiceProvider` — `login` (`email+ip`), `login-ip`, `forgot-password` (`email+ip`), `resend-verification` (`user+ip`), `email-verification` | Present |
| Existing security tests | `tests/Feature/Security/` — 5 files (`SecurityAuditTest`, `SecurityHeadersTest`, `ContentSecurityPolicyTest`, `SettingsPentestTest`, `NotificationPentestTest`) | Present |

Consequence: **do not re-build a row marked Present.** A task whose subject exists is a
verification + test-extension task and is worded that way.

---

## 3. Decisions that override the brief

Recorded rather than silently applied — the brief disagrees with shipped behaviour in
five places.

| # | Brief says | Decision | Why |
|---|---|---|---|
| **D-1** | Send `X-Frame-Options: SAMEORIGIN` | **Do not send it.** `frame-ancestors 'none'` already answers it | Two headers for one decision means two sources of truth. `none` is stricter than `SAMEORIGIN`; adding the weaker header would either disagree with the policy or imply framing is allowed. Pinned by `SecurityHeadersTest::test_a_page_carries_the_origin_headers` and `docs/base/security/web-security.md` line 13 |
| **D-2** | `'unsafe-eval'` globally so Alpine/Livewire keeps working | **Keep it scoped to `pulse.*` and `vantage.*` by route name** | Alpine/Livewire only run on those two vendor dashboards. A global `'unsafe-eval'` widens the XSS surface of every page and breaks `ContentSecurityPolicyTest::test_only_the_vendor_dashboards_grant_unsafe_eval` — which exists precisely to stop this "fix" |
| **D-3** | New `csp_nonce()` helper | **Not added.** `@nonce` + `{{ $cspNonce }}` is the existing spelling | A second accessor for one value is a second thing to keep in sync. The directive consumes the shared variable the middleware writes; `Vite::useCspNonce()` reads the same value |
| **D-4** | Blanket `strip_tags()` / `trim()` trait before validation | **No blanket trait.** D1 is an output-escaping audit plus targeted sanitization at the sinks that need it | Output escaping (`{{ }}`) is the XSS primitive and it already holds. Stripping input before validation mangles legitimate names (`O'Brien & Sons`), destines the change to be un-testable against stored history, and does not stop the real injection classes. Phase 10's CSV-formula finding is the precedent: `strip_tags()` drops markup, not a leading `=` |
| **D-5** | CORS "allowed origins from env, credentials support" | **Ship the config, default-deny**: `allowed_origins` empty unless `CORS_ALLOWED_ORIGINS` is set; `supports_credentials` only with explicit origins; `paths` limited to `api/*` | A permissive default is a new cross-origin surface with no consumer today. `*` + credentials is also invalid per the CORS spec. This is an operator switch that starts closed, not a policy change |

---

## 4. Group A — CSP & security headers (views & middleware)

> Depends on: nothing. Blocks: B (both concern what the browser is allowed to do).

Status: **DONE** — implemented 2026-10-26, owner-reviewed and closed 2026-10-27. Most of
this group shipped with Phase 10; the tasks closed the remaining coverage gaps rather
than the headers themselves.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P13-A1 | **Verify `AddSecurityHeaders` against the four-header table.** Confirm HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` and the CSP directives are on HTML, JSON and the streamed CSV; confirm the registration is global and single (a second registration overwrites the nonce after render). No new middleware is created. Update `docs/base/security/web-security.md` only if a value drifted | P0 | — | DONE — verified: appended once, globally, in `bootstrap/app.php`; the four origin-header values match `web-security.md` exactly, and the CSP is now sent on every response (inert off-document, uniform by choice). No doc drift |
| P13-A2 | **Unescaped-output audit + nonce sweep.** Every `{!! … !!}` in `resources/views` is enumerated and each is either (a) machine-generated markup whose producer escapes, or (b) removed in favour of `{{ }}`. Confirm every inline `<script>` on app pages carries `@nonce` and none relies on a policy bypass | P0 | P13-A1 | DONE — `$editBtn` removed (string-built `<a>` → escaped Blade anchor); 5 sites remain, all constant/vendor markup. No executable inline `<script>` lacks `@nonce` (the Scramble dev-tools block is `type="application/json"`) |
| P13-A3 | **Extend `tests/Feature/Security/SecurityHeadersTest.php`.** Add: the nonce in the CSP header equals the nonce on the rendered script tags; a page with an injected `<script>` has no `'unsafe-inline'`; HSTS present on a redirect and on JSON; the `{!! !!}` allowlist is a declared list, so a new one fails until it is justified | P0 | P13-A2 | DONE — `Permissions-Policy` folded into the shared exact-value set (now pinned on JSON too), `X-Frame-Options` absence pinned, `{!! !!}` allowlist added. Nonce↔script matching and no-`unsafe-inline` were already pinned by `ContentSecurityPolicyTest`, not duplicated |

**Gate A:** every response carries the four origin headers, HTML carries a CSP whose
nonce matches the document, `'unsafe-eval'` appears on exactly the two vendor route
groups, and each `{!! !!}` site is either justified in the test's allowlist or gone.

**Measured (2026-10-26):** `php artisan test --filter=Security` → **94 passed / 453
assertions**, `--filter=SecurityHeadersTest` → 6 passed / 34 assertions,
`--filter=UserCrudWebTest` → 13 passed, full suite **2208 passed / 7805 assertions**,
`vendor/bin/pint --test` clean. Blade comments are stripped before the `{!! !!}`
scan, so prose about the directive does not have to be allowlisted.

---

## 5. Group B — CSRF & state-mutation protection

> Depends on: A. Blocks: C (both are request-origin decisions).

Status: **PLANNED**. `VerifyCsrfToken` already refuses to skip under test, which is the
load-bearing half.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P13-B1 | **Verify the strict-CSRF alignment.** `bootstrap/app.php` appends `VerifyCsrfToken` to the `web` group; `runningUnitTests()` returns `false`; a `TokenMismatchException` renders `419` with `ApiError` `CSRF_TOKEN_MISMATCH` for JSON and the 419 page for HTML. Confirm both branches, and confirm `api/*` (Sanctum bearer) is not inside the CSRF group. No change to the framework's middleware order | P0 | P13-A1 | PLANNED |
| P13-B2 | **Blade form & AJAX audit.** `@csrf` present in every `<form method="POST">` (method-spoofed `PUT`/`PATCH`/`DELETE` included); the three layouts carry `<meta name="csrf-token">`; every `fetch`/`XHR` mutation in `resources/js` reads that meta tag or a hidden input, including `<x-ui.confirm-action>`'s payload and the bulk-action bars. A route scan asserts no state-mutating web route lacks a form that posts a token | P0 | P13-B1 | PLANNED |
| P13-B3 | **`tests/Feature/Security/CsrfProtectionTest.php`.** Web POST/PUT/PATCH/DELETE without a token → 419; with a valid token → the action's own status; JSON request without a token → 419 with the code; the meta tag equals `csrf_token()`; the exception list in `VerifyCsrfToken::except` stays empty. Replaces nothing — `SecurityAuditTest`'s single-form probe stays as the smoke test | P0 | P13-B2 | PLANNED |

**Gate B:** every state-mutating web route answers 419 without a token and works with
one, JSON gets a machine-readable 419, and the `except` array is still empty.

---

## 6. Group C — CORS & API hardening

> Depends on: A, B. Blocks: D (a CORS misconfiguration is an input-boundary defect).

Status: **PLANNED**. This is the one group with real construction: no `config/cors.php`
exists today, so the app emits no CORS headers at all.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P13-C1 | **`config/cors.php`, default-deny.** `paths` = `api/*` only (never `*`); `allowed_origins` from `CORS_ALLOWED_ORIGINS` split on comma, empty by default; `allowed_methods` explicit (`GET, POST, PUT, PATCH, DELETE, OPTIONS`); `allowed_headers` includes `Authorization`, `Content-Type`, `Accept`, `X-CSRF-TOKEN`, `X-Requested-With`, `X-Correlation-ID`; `exposed_headers` = `X-Request-ID`; `max_age` 3600; `supports_credentials` true only when at least one origin is named. Add both keys to `.env.example` with a comment. `HandleCors` is already in the global stack — verify, do not register it twice | P0 | P13-B1 | PLANNED |
| P13-C2 | **`tests/Feature/Security/CorsPolicyTest.php`.** `OPTIONS /api/v1/…` from a configured origin → `Access-Control-Allow-Origin` echoes it and `Vary: Origin`; from an unlisted origin → no allow-origin header; `credentials` → `Access-Control-Allow-Credentials: true` and never `*`; the web routes (`/login`, `/users`) emit no CORS header; `X-Correlation-ID` is accepted and `X-Request-ID` is exposed | P0 | P13-C1 | PLANNED |

**Gate C:** an unlisted origin gets no CORS grant on any route, a listed one gets exactly
the configured grant, and no response ever pairs `*` with credentials.

---

## 7. Group D — Input sanitization, validation & parameter tampering

> Depends on: C. Blocks: E.

Status: **PLANNED**. Validation and mass-assignment shielding already ship; D1 is the
decision that a blanket input filter is the wrong fix (D-4) and a targeted audit is the
right one.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P13-D1 | **Output-escaping audit + targeted sink hardening.** Enumerate every place user-controlled text reaches a non-Blade sink: audit CSV export (already handled — `CsvInjectionPentestTest`), notification bodies, mail subjects, `data-*` attributes consumed by JS, JSON written into a `<script>` block. Each gets either an escaping call at the sink or a test that proves the sink escapes. **No `SanitizesInput` trait** (D-4); `strip_tags` stays where a specific action already needs it (`UserCreateAction`, `UserUpdateAction`, `GenerateAuditExport`) | P0 | P13-C1 | PLANNED |
| P13-D2 | **Model & query shielding sweep.** Re-read every `#[Fillable]` / `$fillable`: no `$guarded = []`; sensitive attributes (`is_locked`, `must_change_password`, `password_expires_at`, `roles`, permission names) are writable only by the action that owns the transition, never by a generic update FormRequest. Extend the sort/filter whitelist check to **every** index action — `User`/`Role`/`Permission` have it; audit, notifications, exports must be proven, and an unknown `sort`/`direction` answers 422 (not a silent fallback) where a FormRequest exists | P0 | P13-D1 | PLANNED |
| P13-D3 | **`tests/Feature/Security/InputSanitizationTest.php`.** Stored XSS payload in `name`/`username` renders escaped on the index and the show page; a `javascript:` URL in a notification payload does not become an anchor; `sort=name;DROP TABLE users` and `direction=sideways` are refused with 422 on each index; `is_locked` and role names submitted in a profile/user update payload are ignored; an unknown filter key is not forwarded to the query builder | P0 | P13-D2 | PLANNED |

**Gate D:** no user-controlled value reaches a sink without escaping, no sensitive column
is mass-assignable through a generic FormRequest, and every index refuses a sort column
outside its whitelist.

---

## 8. Group E — Information disclosure, rate limiting & verification suite

> Depends on: A–D.

Status: **PLANNED**. Masking and composite keys ship; the tasks measure and pin them.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P13-E1 | **Verify production error masking on both branches.** With `APP_DEBUG=false`: a JSON 500 carries `{message, code, meta}` and no `trace`/`file`/`line`; an HTML 500 renders the error page and no stack trace; a 404/419/429 keeps the same envelope. The exception handler's `debug` key is the only difference when debug is on. Extend `SecurityAuditTest::test_error_responses_do_not_leak_stack_traces` with the HTML branch it does not cover | P0 | P13-D2 | PLANNED |
| P13-E2 | **Verify the composite throttle keys.** Table each sensitive endpoint → limiter → key → rate: `login` (`email+ip`, 5/min), `login-ip` (10/min), `forgot-password` (`email+ip`, 3/min), `register`, `reset-password`, `resend-verification` (`user+ip`), `email-verification`. Prove 429 from two different IPs with one email still shares one bucket, and that a second email from the same IP gets its own. Record the ladder in `docs/base/security/rate-limiting.md` if a route is missing from it | P0 | P13-E1 | PLANNED |
| P13-E3 | **Suite run + trackers.** `php artisan test --filter=Security`, then the full `php artisan test` and `vendor/bin/pint --test`. Update § *Test Status* in `progress.md` with the measured counts, and flip only the tasks whose gates passed. No task reaches DONE on a green filter alone | P0 | P13-A3, P13-B3, P13-C2, P13-D3, P13-E2 | PLANNED |

**Gate E:** debug off and on differ by exactly one key; every sensitive auth/reset/
verification route has a documented composite key and a 429 test; the full suite is green
and the counts are recorded.

---

## 9. Test coverage added by this phase

| File | Group | Cases |
|---|---|---|
| `tests/Feature/Security/SecurityHeadersTest.php` (extend) | A | nonce↔document match, no `'unsafe-inline'`, HSTS on redirect/JSON, `{!! !!}` allowlist |
| `tests/Feature/Security/CsrfProtectionTest.php` (new) | B | 419 per verb, JSON 419 code, meta↔token, empty `except` |
| `tests/Feature/Security/CorsPolicyTest.php` (new) | C | preflight allowed/denied, `Vary: Origin`, credentials never `*`, web routes clean |
| `tests/Feature/Security/InputSanitizationTest.php` (new) | D | stored/reflected XSS, JSON-in-script sink, sort/direction refusal, mass-assignment, unknown filter |
| `tests/Feature/Security/SecurityAuditTest.php` (extend) | E | HTML error masking, composite-key 429s |

Rule: a security test asserts the **refusal**, not just the happy path. A test that only
proves a permitted request succeeds does not prove the control exists.

---

## 10. Definition of done

- Every group gate met, measured, and written into this document.
- `vendor/bin/pint --test` clean; full `php artisan test` green; `--filter=Security` green.
- `docs/base/security/*` matches the code. If a control changed shape, the doc changed in
  the same commit.
- `task-tracker.md` rows updated from `PLANNED` through `IN_PROGRESS` → `REVIEW` → `DONE`;
  `progress.md` § *Test Status* carries the measured count.
- No commit, no push, no PR until the owner asks.
