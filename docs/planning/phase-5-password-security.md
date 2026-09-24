# Phase 5 — Password & Security Lifecycle

> Date: 2026-09-24 | Branch: feature/phase-5-password-security | Status: PLANNED
> Purpose: hyper-detailed task breakdown for Phase 5, UI/views-first (A → B → C strict sequential).
> Scope: Password policy UI, password history, password expiration, inactivity lock, login rate-limit finalization.
> Dependency chain: Group A → Group B → Group C. A cannot skip to C.

---

## Existing Foundation (audit 2026-09-24)

### Already in place
- `password_histories` table migration — `0001_01_01_000003_create_password_histories_table.php`
- `ChangePassword` action — `app/Actions/V1/Auth/ChangePassword.php` (history check + token revocation + audit wired)
- User columns: `password_expires_at`, `last_activity_at`, `must_change_password`, `is_active`, `is_locked`
- `EnsurePasswordChangeRequired` middleware — enforces `must_change_password` + `password_expires_at`
- `LoginThrottle` + `FailedLoginAttempt` — full rate-limit + progressive lockout (Phase 3 done)
- `RATE-001` / `RATE-002` — 4 rate limiters wired (login 5/min, forgot 3/min, reset 3/min, resend 5/hour)
- `PWD-005` admin reset password — DONE
- `PWD-006` temp password + force change — DONE
- SystemSetting model + seeder + `getInt/GetBool/GetString/getAll` — runtime config pattern exists
- User create view already has password field with policy hint (partial — needs full IM8 compliance)

### Phase 5 gap — what's missing
| Gap | Status | Notes |
|-----|--------|-------|
| PWD-001 IM8 password policy | PLANNED | No formal policy object; no UI hints on create/edit/reset |
| PWD-002 Password validation rule | PLANNED | No custom validation rule; no strength meter |
| PWD-003 Password history enforcement | PLANNED | Table exists, action has check, but no SystemSetting toggle + UI messaging |
| PWD-004 Password expiration | PLANNED | Column exists, middleware checks, but no SystemSetting policy + no expiry warning UI |
| AUTH-015 Login rate limiting | PLANNED | Already DONE in Phase 3 — just needs tracker reconciliation |
| INACT-001 Inactivity tracking | PLANNED | Column exists, but no tracking job + no lock enforcement |

---

## Group A — Password Policy & Validation UI

> Goal: user-facing password strength indicator + IM8-compliant validation on every password entry point.
> Depends: Phase 4 (user create/edit views exist). Blocks: Group B (history needs policy rules to reject reuse).

| ID | Task | Depends | Est. | Notes |
|----|------|---------|------|-------|
| P5-A1 | IM8 policy definition — `App\Support\PasswordPolicy` (min length, upper, lower, digit, symbol, not-username) | — | small | Single source of truth for rules + messages; reads from SystemSetting (configurable) |
| P5-A2 | SystemSetting keys: `password_min_length`, `password_require_upper`, `password_require_lower`, `password_require_digit`, `password_require_symbol`, `password_reject_username` | P5-A1 | small | Add to SystemSettingSeeder with IM8 defaults; admin-editable via `/settings` |
| P5-A3 | Custom validation rule `PasswordStrengthRule` — uses PasswordPolicy + translator | P5-A1 | medium | Reusable across create-user, reset-password, change-password FormRequests |
| P5-A4 | Password strength indicator JS — `resources/js/helpers/password-strength.js` | P5-A1 | small | Real-time bar (weak/medium/strong) + rule checklist; no lib, vanilla JS |
| P5-A5 | View: add strength indicator to `users/create.blade.php` (admin create user) | P5-A4 | small | Card under password field; `data-policy` attribute drives JS |
| P5-A6 | View: add strength indicator to `auth/reset-password.blade.php` | P5-A4 | small | Same component, shared partial `partials/password-strength.blade.php` |
| P5-A7 | View: add strength indicator to `profile/edit.blade.php` (change password section) | P5-A4 | small | Same partial include |
| P5-A8 | Wire `PasswordStrengthRule` into `CreateUserRequest` + `PasswordResetRequest` + `ChangePasswordRequest` | P5-A3 | small | Replace/append existing `password` validation rules |
| P5-A9 | Tests: PasswordPolicy unit + PasswordStrengthRule integration + view render assertions | P5-A1..A8 | medium | Pest: policy logic, rule passes/fails, indicator HTML present |

**Deliverables:**
- `app/Support/PasswordPolicy.php` — policy definition + `validate($password, $username): array` (errors)
- `app/Rules/PasswordStrengthRule.php` — Laravel validation rule
- `resources/views/partials/password-strength.blade.php` — shared indicator partial
- `resources/js/helpers/password-strength.js` — vanilla JS strength calculator
- 3 views updated: `users/create`, `auth/reset-password`, `profile/edit`
- SystemSettingSeeder: 6 new keys with IM8 defaults
- Tests: `tests/Feature/PasswordPolicyTest.php`, `tests/Unit/PasswordPolicyTest.php`

**Gate:** A verified + tested. Proceed to Group B.

---

## Group B — Password History Enforcement

> Goal: prevent reuse of last N passwords; admin-configurable via SystemSetting; clear UI messaging.
> Depends: Group A (password policy rules). Blocks: Group C (expiration UI needs history toggle in same settings page).

| ID | Task | Depends | Est. | Notes |
|----|------|---------|------|-------|
| P5-B1 | SystemSetting keys: `password_history_count` (default 5), `password_history_enabled` (bool, default true) | P5-A2 | small | Add to seeder |
| P5-B2 | `PasswordHistory` model — `app/Models/PasswordHistory.php` | — | tiny | CREATE — model does not exist yet; migration table exists but no Eloquent model |
| P5-B3 | `RecordPasswordHistory` action — hash + store on password change | P5-A2 | small | Called by `ChangePassword` + `ResetPassword` + `CreateUser` actions |
| P5-B4 | Update `ChangePassword` action — call `RecordPasswordHistory` + enforce history check before allowing change | P5-B3 | small | Already has history check partially — verify + align |
| P5-B5 | Update `ResetPasswordAction` — record history after successful reset | P5-B3 | small | Ensures reset also feeds history |
| P5-B6 | Update `CreateUserAction` — record initial password hash in history | P5-B3 | small | Prevents immediate reuse of temp password |
| P5-B7 | View: add "password cannot be one of last N" hint to reset-password + profile change password | P5-B5 | small | i18n via `__('messages.password_history_hint')` |
| P5-B8 | View: add `password_history_enabled` + `password_history_count` fields to `/settings` (security section) | P5-B1 | small | SystemSetting form; sidebar + sections layout |
| P5-B9 | Tests: history enforcement (reuse blocked, after N changes allowed), settings toggle | P5-B4..B8 | medium | Pest: change password → reuse old → assert validation error |

**Deliverables:**
- `app/Actions/V1/Auth/RecordPasswordHistory.php` — hash + store
- `app/Models/PasswordHistory.php` — model (if not exists)
- `ChangePassword` + `ResetPasswordAction` + `CreateUserAction` updated
- 2 views updated: `auth/reset-password`, `profile/edit`
- `settings/index.blade.php` — new "Password History" section
- SystemSettingSeeder: 2 new keys
- Tests: `tests/Feature/PasswordHistoryTest.php`

**Gate:** B verified + tested. Proceed to Group C.

---

## Group C — Password Expiration & Inactivity Lock

> Goal: password expiry warning UI + enforcement; inactivity lock after N days; expired-password change screen.
> Depends: Group B (settings page structure exists). Final group of Phase 5.

| ID | Task | Depends | Est. | Notes |
|----|------|---------|------|-------|
| P5-C1 | SystemSetting keys: `password_expiry_days` (default 90), `password_expiry_enabled` (bool), `password_expiry_warn_days` (default 14), `inactivity_lock_days` (default 30), `inactivity_lock_enabled` (bool) | P5-B1 | small | Add to seeder |
| P5-C2 | `PasswordExpiry` service — `app/Services/PasswordExpiry.php`: `isExpired(User)`, `daysUntilExpiry(User)`, `shouldWarn(User)` | P5-C1 | small | Pure logic, testable |
| P5-C3 | `InactivityLock` service — `app/Services/InactivityLock.php`: `isInactive(User)`, `shouldLock(User)`, `lock(User)` | P5-C1 | small | Pure logic, testable |
| P5-C4 | Update `EnsurePasswordChangeRequired` middleware — call `PasswordExpiry::isExpired` + `InactivityLock::isInactive` | P5-C2,C3 | small | Already exists; extend to cover both |
| P5-C5 | View: `auth/password-expired.blade.php` — forced change screen (like verify-email layout) | P5-C4 | small | AdminLTE auth layout; form POST to `password.change` |
| P5-C6 | View: expiry warning banner — `partials/password-expiry-warning.blade.php` | P5-C2 | small | Dismissible callout on dashboard when `shouldWarn()` true |
| P5-C7 | View: add "Password Expiration" + "Inactivity Lock" sections to `/settings` (security tab) | P5-C1 | small | Number inputs + toggles; matches settings page pattern |
| P5-C8 | Job: `app/Jobs/PasswordExpirySweep.php` — daily sweep, set `must_change_password` for expired users | P5-C2 | small | Scheduled via `routes/console.php` or `app/Console/Kernel` |
| P5-C9 | Job: `app/Jobs/InactivityLockSweep.php` — daily sweep, `is_locked = true` + session revocation for inactive users | P5-C3 | small | Same scheduling |
| P5-C10 | Tests: expiry logic, inactivity logic, middleware redirect, job sweep, warning banner render | P5-C2..C9 | medium | Pest feature + unit |

**Deliverables:**
- `app/Services/PasswordExpiry.php`
- `app/Services/InactivityLock.php`
- `app/Jobs/PasswordExpirySweep.php`
- `app/Jobs/InactivityLockSweep.php`
- `resources/views/pages/auth/password-expired.blade.php`
- `resources/views/partials/password-expiry-warning.blade.php`
- `EnsurePasswordChangeRequired` middleware updated
- `settings/index.blade.php` — 2 new sections
- `routes/console.php` or `app/Console/Kernel.php` — daily schedule
- SystemSettingSeeder: 5 new keys
- Tests: `tests/Feature/PasswordExpiryTest.php`, `tests/Feature/InactivityLockTest.php`

**Gate:** C verified + tested. Phase 5 complete.

---

## Cross-cutting concerns

### i18n
- All new view strings go in `lang/en/messages.php` + `lang/id/messages.php` (parity enforced by TranslationTest)
- Keys: `password_policy_title`, `password_policy_weak`, `password_policy_medium`, `password_policy_strong`, `password_history_hint`, `password_expired_title`, `password_expired_body`, `password_expiry_warn_banner`, `inactivity_locked_title`, `inactivity_locked_body`

### Audit
- Password history recording: audit at mutation site (Action self-logs)
- Expiry/inactivity lock: audit in Job (non-HTTP mutation → direct log)
- Follows controller-first pattern; no observers

### Settings page layout
- New sections follow existing sidebar + sections pattern (sticky nav)
- Two new sidebar items: "Password History" (Group B), "Password Expiration" + "Inactivity Lock" (Group C)
- Number inputs with `min` + `max` validation
- Boolean toggles use hidden-input trick (convention §4d)

### JS
- `password-strength.js` — vanilla, no lib
- Reads `data-policy` attribute on input → renders bar + checklist
- Shared partial `password-strength.blade.php` wraps the indicator HTML
- Included via `@include` in all 3 password views

---

## Architecture Rules (Phase 5)

1. Thin controllers — all logic in Action/Service classes.
2. Custom Form Requests — `PasswordStrengthRule` used in all 3 password requests.
3. Settings-driven — policy values from SystemSetting, not config files.
4. Service layer for pure logic — `PasswordExpiry`, `InactivityLock` are testable without HTTP.
5. Jobs for sweeps — daily scheduled, idempotent.
6. No new middleware unless extending existing.
7. i18n parity — every string in both locales.

---

## Task tracker reconciliation

After Phase 5, update `docs/planning/task-tracker.md`:
- `PWD-001` → DONE (P5-A1..A9)
- `PWD-002` → DONE (P5-A3, A8)
- `PWD-003` → DONE (P5-B1..B9)
- `PWD-004` → DONE (P5-C1..C10)
- `AUTH-015` → DONE (reconcile: already wired in Phase 3, RATE-001/002 cover it)
- `INACT-001` → DONE (P5-C3, C9, C10)

New tasks to add:
- P5-A1..A9, P5-B1..B9, P5-C1..C10 — all start PLANNED, move to DONE as completed.

---

## Execution order summary

```
Group A (Password Policy UI)     — A1 → A2 → A3 → A4 → A5 → A6 → A7 → A8 → A9
                                         ↓ (A9 gate)
Group B (Password History)        — B1 → B2 → B3 → B4 → B5 → B6 → B7 → B8 → B9
                                         ↓ (B9 gate)
Group C (Expiry + Inactivity)     — C1 → C2 → C3 → C4 → C5 → C6 → C7 → C8 → C9 → C10
                                         ↓ (C10 gate)
                                   Phase 5 COMPLETE
```

Each group = one commit boundary. Groups sequential — A cannot skip to C.
