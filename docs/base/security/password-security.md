# Password Security

> Last updated: 2026-09-24 | Phase 5 Group A ✅ DONE, Group B/C PLANNED

## Implementation Status (Phase 5)

### Group A — Password Policy & Validation UI ✅ DONE

| Component | Location | Status |
|-----------|----------|--------|
| Policy definition | `app/Support/PasswordPolicy.php` | ✅ |
| Validation rule | `app/Rules/PasswordStrengthRule.php` | ✅ |
| Real-time strength JS | `resources/js/helpers/password-strength.js` | ✅ |
| Strength indicator partial | `resources/views/layouts/partials/password-strength.blade.php` | ✅ |
| Settings integration | `database/seeders/SystemSettingSeeder.php` | ✅ |
| Tests (21 total) | `tests/Unit/PasswordPolicyTest.php`, `tests/Feature/PasswordPolicyTest.php` | ✅ |
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

### Group B — Password History Enforcement
PLANNED — see `docs/planning/phase-5-password-security.md`

### Group C — Password Expiration & Inactivity Lock
PLANNED — see `docs/planning/phase-5-password-security.md`

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

## Password Expiration

Configuration:
```
security.password_expiration.enabled  (boolean)
security.password_expiration.days       (number of days)
```

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