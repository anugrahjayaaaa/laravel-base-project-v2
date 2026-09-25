# Settings

## Overview

Settings are a core feature.

The runtime settings UI currently uses these canonical keys:

```text
inactivity_lock_enabled
inactivity_lock_days
inactivity_lock_grace_enabled
inactivity_lock_grace_days
password_expiry_enabled
password_expiry_days
password_expiry_warn_days
password_security_sweep_time
password_security_sweep_timezone
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
| `inactivity_lock_enabled` | boolean | Enable/disable inactivity enforcement |
| `inactivity_lock_days` | integer, min:1, max:365 | Inactivity threshold before lock |
| `inactivity_lock_grace_enabled` | boolean | Enable/disable grace period for never-logged-in users |
| `inactivity_lock_grace_days` | integer, min:0, max:365 | Grace period for never-logged-in users (`last_activity_at = NULL`) |
| `security.password_history.count` | integer, min:1, max:24 |
| `password_expiry_enabled` | boolean | Enable/disable password expiry |
| `password_expiry_days` | integer, min:0, max:365 | Expiry period; `0` disables persistence of future expiry |
| `password_expiry_warn_days` | integer, min:1, max:90 | Warning window |
| `password_security_sweep_time` | `H:i` | Local sweep time |
| `password_security_sweep_timezone` | active IANA timezone | Sweep timezone; empty uses application timezone |
| `registration.default_role` | string, exists:roles,name |
| `registration.enabled` | boolean |

## Security

- Settings are validated, authorized, and audited on change.
- Technical infrastructure settings must NOT be exposed through normal Settings.
- Operational values administrators need to change are exposed through Settings.
- Settings changes invalidate cache immediately.

### Inactivity Policy

The inactivity policy is configurable via settings:

- `inactivity_lock_enabled` — enable/disable inactivity enforcement
- `inactivity_lock_days` — the inactivity threshold
- `inactivity_lock_grace_enabled` — enable/disable the never-logged-in grace period
- `inactivity_lock_grace_days` — grace period for users whose `last_activity_at` is null

The inactivity process runs as a scheduled background task. When an account is
locked due to inactivity, all of the user's active sessions and tokens are revoked.

## Dependencies

Settings are part of Phase 8 in the implementation roadmap. Features that depend on settings:
- Inactivity policy (Phase 5)
- Failed login protection (Phase 5)
- Password history/expiration (Phase 5)
- Registration (Phase 3)
- Feature availability (Phase 7)