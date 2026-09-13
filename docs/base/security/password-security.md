# Password Security

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

## ADR References

- ADR-007: Password history strategy