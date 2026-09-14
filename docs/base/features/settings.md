# Settings

## Overview

Settings are a core feature.

Use structured names:
```
security.inactivity.enabled
security.inactivity.days
security.inactivity.grace_days
security.login.failed_attempts.enabled
security.login.failed_attempts.max_attempts
security.login.failed_attempts.lock_duration_minutes
security.password_history.enabled
security.password_history.count
security.password_expiration.enabled
security.password_expiration.days
registration.enabled
registration.default_role
```

## Groups

|| Group | Examples |
||-------|----------|
|| `security` | Inactivity, failed login, password history/expiration |
|| `registration` | Enabled, default role |
|| `mail` | SMTP config, from address |
|| `localization` | Default locale, available locales |
|| `system` | App name, timezone, pagination limits |

## Two-Layer Configuration

|| Layer | Content | Manageable via UI? |
||-------|---------|-------------------|
|| Technical (config files) | Infrastructure settings (Redis, DB, cache, queue drivers) | No |
|| Operational (Settings DB) | Application settings (security, registration, mail templates) | Yes |

- Technical infrastructure settings remain in configuration files.
- Operational application settings may be managed from the dashboard.

## Change Management

Settings changes must have:
- **Validation** — ensure values are within allowed ranges
- **Authorization** — require `settings.manage` permission
- **Audit** — log setting changes with before/after values
- **Cache invalidation** — invalidate cached settings after change

## Validation

|| Setting | Validation |
||---------|-----------|
|| `security.inactivity.enabled` | boolean | Enable/disable inactivity enforcement |
|| `security.inactivity.days` | integer, min:0, max:365 | Inactivity threshold before lock |
|| `security.inactivity.grace_days` | integer, min:0, max:365 | Grace period applied to `last_activity_at` for the inactivity query, ensuring never-logged-in users (`last_activity_at = NULL`) are handled. |
|| `security.password_history.count` | integer, min:1, max:24 |
|| `security.password_expiration.days` | integer, min:1, max:365 |
|| `registration.default_role` | string, exists:roles,name |
|| `registration.enabled` | boolean |

## Security

- Settings are validated, authorized, and audited on change.
- Technical infrastructure settings must NOT be exposed through normal Settings.
- Operational values administrators need to change are exposed through Settings.
- Settings changes invalidate cache immediately.

### Inactivity Policy

The inactivity policy is configurable via settings:

- `security.inactivity.enabled` — enable/disable inactivity enforcement
- `security.inactivity.days` — the inactivity threshold
- `security.inactivity.grace_days` — grace period applied to `last_activity_at` for
  the inactivity query, ensuring never-logged-in users (`last_activity_at = NULL`)
  are included in the lockout query.

The inactivity process runs as a scheduled background task. When an account is
locked due to inactivity, all of the user's active sessions and tokens are revoked.

## Dependencies

Settings are part of Phase 8 in the implementation roadmap. Features that depend on settings:
- Inactivity policy (Phase 5)
- Failed login protection (Phase 5)
- Password history/expiration (Phase 5)
- Registration (Phase 3)
- Feature availability (Phase 7)