# Password Security

> Last updated: 2026-09-25 | Phase 5 Group A ✅ DONE, Group B ✅ DONE, Group C ✅ DONE

## Implementation Status (Phase 5)

### Group A — Password Policy & Validation UI ✅ DONE

| Component | Location | Status |
|-----------|----------|--------|
| Policy definition | `app/Support/PasswordPolicy.php` | ✅ |
| Validation rule | `app/Rules/PasswordStrengthRule.php` | ✅ |

### Group B — Password History Enforcement ✅ DONE

| Component | Location | Status |
|-----------|----------|--------|
| History table | `database/migrations/0001_01_01_000003_create_password_histories_table.php` | ✅ |
| Model | `app/Models/PasswordHistory.php` | ✅ |
| Record action | `app/Actions/V1/Auth/RecordPasswordHistoryAction.php` | ✅ |
| ChangePasswordAction integration | `app/Actions/V1/Auth/ChangePasswordAction.php` | ✅ |
| ResetPasswordAction integration | `app/Actions/V1/Auth/ResetPasswordAction.php` | ✅ |
| CreateUserAction integration | `app/Actions/V1/User/CreateUserAction.php` | ✅ |
| View: reset-password hint | `resources/views/pages/auth/reset-password.blade.php` | ✅ |
| View: profile hint | `resources/views/pages/profile/edit.blade.php` | ✅ |
| View: settings fields | `resources/views/pages/settings/index.blade.php` | ✅ |
| Tests | `tests/Feature/PasswordHistoryTest.php` | ✅ |

### Settings

- `password_history_enabled` (boolean, default: true) — toggle enforcement
- `password_history_count` (integer, default: 5, min: 0, max: 24) — retention limit

### Enforcement

- New password hash checked against last N entries in `password_histories`
- Validation error: "You cannot reuse one of your last N passwords."
- Entries beyond limit pruned automatically
- Bypass if `password_history_enabled` = false
| Real-time strength JS | `resources/js/helpers/password-strength.js` | ✅ |
| Strength indicator partial | `resources/views/layouts/partials/password-strength.blade.php` | ✅ |
| Settings integration | `database/seeders/SystemSettingSeeder.php` | ✅ |
| Tests (13 unit + 8 feature) | `tests/Unit/PasswordPolicyTest.php`, `tests/Feature/PasswordPolicyTest.php` | ✅ |
| Pentest | clean (1 LOW: homoglyph bypass) | ✅ |

**Rules enforced (IM8):**
- Min length (default 12, configurable via `password_min_length`)
- At least one uppercase (`password_require_upper`)
- At least one lowercase (`password_require_lower`)
- At least one digit (`password_require_digit`)
- At least one symbol (`password_require_symbol`)
- Must not contain username (`password_reject_username`)

**Views with strength indicator:**
- `auth/reset-password.blade.php` — new password field
- `pages/profile/edit.blade.php` — change password section
- `pages/users/create.blade.php` — ❌ skipped (auto-generated temp password)

**FormRequests using `PasswordStrengthRule`:**
- `PasswordChangeRequest`
- `PasswordResetRequest`
- `ProfileUpdateRequest`

### Group B — Password History ✅ DONE

Group C — Password Expiration & Inactivity Lock ✅ DONE

| Component | Location | Status |
|-----------|----------|--------|
| PasswordExpiry service | `app/Services/PasswordExpiry.php` | ✅ |
| InactivityLock service | `app/Services/InactivityLock.php` | ✅ |
| Expiry sweep job | `app/Jobs/PasswordExpirySweep.php` | ✅ |
| Inactivity sweep job | `app/Jobs/InactivityLockSweep.php` | ✅ |
| Middleware extension | `app/Http/Middleware/EnsurePasswordChangeRequired.php` | ✅ |
| Expired password view | `resources/views/pages/auth/password-expired.blade.php` | ✅ |
| Warning banner partial | `resources/views/partials/password-expiry-warning.blade.php` | ✅ |
| Settings UI | `resources/views/pages/settings/index.blade.php` | ✅ |
| Tests (26 focused) | `tests/Feature/PasswordExpiryTest.php`, `tests/Feature/InactivityLockTest.php`, `tests/Feature/PasswordLifecycleUiTest.php` | ✅ |

### Settings

- `password_expiry_enabled` (boolean, default: true) — toggle enforcement
- `password_expiry_days` (integer, default: 90, min: 0, max: 365) — expiry period
- `password_expiry_warn_days` (integer, default: 14, min: 1, max: 90) — warning window
- `inactivity_lock_enabled` (boolean, default: true) — toggle enforcement
- `inactivity_lock_grace_enabled` (boolean, default: true) — toggle grace period for never-logged-in users
- `inactivity_lock_grace_days` (integer, default: 30, min: 0, max: 365) — grace period for users with `last_activity_at = NULL`
- `inactivity_lock_grace_enabled` controls whether `inactivity_lock_grace_days` is applied. When disabled, the field is ignored and never-logged-in users use only `inactivity_lock_days`.
- `password_security_sweep_time` (time, default: `00:00`) — local sweep time
- `password_security_sweep_timezone` (IANA timezone, default: application timezone) — sweep timezone

### Enforcement

- Daily sweep sets `must_change_password = true` for expired accounts
- Middleware redirects to the password-expired screen for expired passwords
- Inactivity sweep and request-time enforcement lock accounts + revoke sessions/tokens
- SYSTEM audit entries are recorded for sweep and middleware-triggered locks
- Dismissible warning banner on authenticated pages when within warning window
- Bypass if respective `*_enabled` setting = false

---

## Policy

Use the agreed IM8 password policy as the single source of truth for:
- Registration
- Administrative user creation
- Password change
- Password reset
- Initial temporary password replacement
- Admin-triggered password reset

## Password History

Configuration:
```
security.password_history.enabled   (boolean via Settings)
security.password_history.count     (configurable count)
```

Default conceptual behavior:
- New password cannot match the configured number of previous password hashes.
- Once a password falls outside the configured history window, it may be reused.
- Never store plaintext passwords. Store hashes only.

### Runtime keys

The implemented settings contract uses flat runtime keys such as
`inactivity_lock_days`, `inactivity_lock_grace_enabled`,
`inactivity_lock_grace_days`, `password_expiry_days`, and
`password_expiry_warn_days`. Structured names remain an architectural option,
not the current database contract.

Runtime SystemSetting keys:
- `password_expiry_enabled`
- `password_expiry_days`
- `password_expiry_warn_days`
- `inactivity_lock_enabled`
- `inactivity_lock_days`
- `inactivity_lock_grace_enabled`
- `inactivity_lock_grace_days`
- `password_security_sweep_time`
- `password_security_sweep_timezone`

When expiration applies:
```
authenticate
    ↓
password expired?
    ↓ yes
must change password
    ↓
restricted application access
```

Enforced via middleware/application-boundary enforcement.

## Password Reset Scenarios

### Administrative Password Reset

```
Admin → Send reset password request → User receives secure reset link → User chooses new password
```
- Administrators must NOT receive or see the user's new password.

### Initial Administrative User Creation

```
Admin creates user → System generates temporary password → User receives credentials + email verification link → User MUST change password before normal access
```

## Password Change Revocation

- Password change must **revoke existing sessions and bearer tokens**
  for the affected user.
- See `session-security.md` for session/token revocation rules and
  the `client_type` distinction (web vs mobile).
- After password change, the user must re-authenticate.

## ADR References

- ADR-007: Password history strategy