# Settings

## Overview

Settings are a core feature. Phase 8 (2026-10-03) closed: the two-column
dashboard page, the web + API read/update paths, validation, the permission and
feature-flag gates, the audit trail, and cache invalidation all ship and are
covered by tests.

## Canonical keys

37 keys, seeded by `SystemSettingSeeder` and validated by
`App\Http\Requests\V1\System\SystemSettingRequest`. This list was regenerated
from `SystemSetting::getAll()`; an earlier version of this file listed nine keys
and named three others (`security.*`, `registration.*`) that no code has ever
read.

```text
allow_email_change                      email_verification_expire_minutes
allow_username_change                   email_verification_mode
email_change_cooldown_days              email_verification_rate_limit
email_verification_token_expire_minutes
inactivity_lock_days                    lockout_base_minutes
inactivity_lock_enabled                 lockout_increment_minutes
inactivity_lock_grace_days              login_max_attempts
inactivity_lock_grace_enabled           login_rate_limit_per_minute
password_expiry_days                    password_forgot_rate_limit
password_expiry_enabled                 password_history_count
password_expiry_warn_days               password_history_enabled
password_min_length                     password_reject_username
password_require_digit                  password_require_lower
password_require_symbol                 password_require_upper
password_reset_expire_minutes           password_reset_rate_limit
password_reset_token_expire_minutes     password_security_sweep_time
password_security_sweep_timezone        password_uncompromised
registration_default_role               registration_enabled
registration_rate_limit_per_minute      username_change_cooldown_days
```

## Groups

Groups are seeder comment sections, not a stored taxonomy — `system_settings`
has no category column.

| Group | Keys |
|-------|------|
| Login rate limiting & lockout | `login_max_attempts`, `lockout_base_minutes`, `lockout_increment_minutes`, `login_rate_limit_per_minute` |
| Password reset / verification rate limits | `password_forgot_rate_limit`, `password_reset_rate_limit`, `password_reset_token_expire_minutes`, `email_verification_rate_limit`, `email_verification_token_expire_minutes` |
| Password policy & lifecycle | `password_min_length`, `password_require_*`, `password_reject_username`, `password_uncompromised`, `password_history_*`, `password_expiry_*`, `password_security_sweep_*`, `inactivity_lock_*` |
| Email verification | `email_verification_expire_minutes`, `email_verification_mode` |
| Identity / account rules | `allow_username_change`, `username_change_cooldown_days`, `allow_email_change`, `email_change_cooldown_days` |
| Self-registration | `registration_enabled`, `registration_default_role`, `registration_rate_limit_per_minute` |

## Two-Layer Configuration

| Layer | Content | Manageable via UI? |
|-------|---------|-------------------|
| Technical (config files) | Infrastructure settings (Redis, DB, cache, queue drivers) | No |
| Operational (Settings DB) | Application settings (security, registration, token lifetimes) | Yes |

- Technical infrastructure settings remain in configuration files.
- Operational application settings may be managed from the dashboard.

## Change Management

Settings changes must have:
- **Validation** — every bound is declared in `SystemSettingRequest::rules()`
- **Authorization** — `settings.view` to read, `settings.manage` to write
- **Audit** — `system_setting.updated`, written inside the transaction
- **Cache invalidation** — via `DB::afterCommit`, so a rollback cannot leave a stale cache

## Validation

Bounds below are read from `SystemSettingRequest::rules()` — the single source
of truth. `P8-E1a` proves every numeric bound rejects a value outside it.

| Setting | Validation |
|---------|-----------|
| `login_max_attempts` | integer, min:1, max:99 |
| `lockout_base_minutes` | integer, min:1, max:60 |
| `lockout_increment_minutes` | integer, min:1, max:120 |
| `login_rate_limit_per_minute` | integer, min:1, max:120 |
| `password_forgot_rate_limit` | integer, min:1, max:30 |
| `password_reset_rate_limit` | integer, min:1, max:30 |
| `password_reset_token_expire_minutes` | integer, min:1, max:1440 |
| `email_verification_rate_limit` | integer, min:1, max:100 |
| `email_verification_token_expire_minutes` | integer, min:1, max:1440 |
| `password_min_length` | integer, min:4, max:128 |
| `password_require_upper` / `_lower` / `_digit` / `_symbol` | boolean |
| `password_reject_username` | boolean |
| `password_uncompromised` | boolean |
| `password_history_enabled` | boolean |
| `password_history_count` | integer, min:0, max:24 |
| `password_expiry_enabled` | boolean |
| `password_expiry_days` | integer, min:0, max:365 |
| `password_expiry_warn_days` | integer, min:1, max:90 |
| `password_security_sweep_time` | `nullable`, date_format:H:i |
| `password_security_sweep_timezone` | `nullable`, timezone, must exist in `timezones` where `is_active` |
| `inactivity_lock_enabled` | boolean |
| `inactivity_lock_days` | integer, min:1, max:365 |
| `inactivity_lock_grace_enabled` | boolean |
| `inactivity_lock_grace_days` | integer, min:0, max:365 |
| `email_verification_expire_minutes` | integer, min:1, max:1440 |
| `email_verification_mode` | in:public,admin,disabled |
| `password_reset_expire_minutes` | integer, min:1, max:1440 |
| `allow_username_change` / `allow_email_change` | boolean |
| `username_change_cooldown_days` | integer, min:0, max:365 |
| `email_change_cooldown_days` | integer, min:0, max:365 |
| `registration_enabled` | boolean |
| `registration_rate_limit_per_minute` | integer, min:1, max:30 |
| `registration_default_role` | nullable, string, must be a role `RoleLookup::visibleTo($user)` returns |

`registration_default_role` is scoped to the viewer rather than merely checked
for existence. An earlier `Rule::exists('roles','name')` let a non-superadmin
post `superadmin` directly and make every self-registrant an admin.

## Security

- Settings are validated, authorized, and audited on change.
- Technical infrastructure settings must NOT be exposed through normal Settings.
- Operational values administrators need to change are exposed through Settings.
- Settings changes invalidate cache immediately.
- The action's `$updates` array is a whitelist. A key that is validated and
  rendered but missing from it is dropped silently — `SettingsPersistenceTest`
  enforces that `SystemSettingRequest` stays a subset of it.
- `SettingsPentestTest` covers this module adversarially: guest/user/view-only
  authorization, role escalation through `registration_default_role`,
  out-of-range values, unknown-key injection (`key`, `id`, `value` are model
  columns, not settings), mass assignment onto timestamps, and audit integrity.

### Inactivity Policy

The inactivity policy is configurable via settings:

- `inactivity_lock_enabled` — enable/disable inactivity enforcement
- `inactivity_lock_days` — the inactivity threshold
- `inactivity_lock_grace_enabled` — enable/disable the never-logged-in grace period
- `inactivity_lock_grace_days` — grace period for users whose `last_activity_at` is null

The inactivity process runs as a scheduled background task. When an account is
locked due to inactivity, all of the user's active sessions and tokens are revoked.

## Caching

`SystemSetting::loadSettings()` fills a request-level static on first read, so N
typed getters in one request cost exactly one query. It deliberately does NOT
populate the shared cache from inside a transaction: an uncommitted value would
be cached where every other process reads it, and a rollback cannot take it
back (measured: the cache held a password policy of 20 while the row said 12).

`SystemSetting::set()` defers its cache bust to `DB::afterCommit`, closing both
halves of that problem — a committed save busts, a rolled-back one never wrote
anything worth forgetting.

**Known invariant:** there is no `SystemSettingObserver`, so a write that
bypasses `set()` — a query-builder update, a seeder using `upsert()` — leaves
the cache stale. Latent, not live: every shipped write path goes through
`set()`.

## Performance

Measured by `tests/Feature/Settings/SettingsBenchmarkTest.php`.

| Path | Queries | Latency |
|------|---------|---------|
| `getAll()` cold | 1 | ~0.24 ms |
| `getAll()` warm (request static) | 0 | ~0 ms |
| 60 typed reads | 1 | ~0.37 ms |
| `GET /settings` (full kernel) | 3–9 | ~12 ms |
| Save (all keys) | 39 | ~21 ms |
| Save (1 key, partial) | 39 | ~21 ms |

The save cost is **constant overhead, not an N+1** — the count held at 40 across
6 to 1003 rows, because it scales with the whitelist length rather than the
table. 36 of those queries are `updateOrCreate`'s existence probe, one per key,
issued to discover rows that already exist. A single bulk `upsert` of the same
pairs costs 1 query / ~0.9 ms. No statement exceeds 0.3 ms and the probe uses
the `system_settings_key_unique` index (`EXPLAIN`: `type=const, rows=1`), so
this is a round-trip count to revisit when network latency matters, not a
defect.

## Dependencies

Phase 8 is done. Features that depend on settings:
- Inactivity policy (Phase 5)
- Failed login protection (Phase 5)
- Password history/expiration (Phase 5)
- Registration (Phase 3)
- Feature availability (Phase 7)