# Notifications & Mail

## Overview

Notifications are the user notification abstraction. Mail is the transport for email delivery.

## Notification Channels

| Channel | Use Case |
|---------|----------|
| `mail` | Email delivery |
| `database` | In-app inbox (Laravel's native notifications table) |

Slack, broadcast, SMS and push are **not** wired: nothing in the application
configures or dispatches them. They are listed here only if a module that needs
them arrives.

Delivery switches are global and admin-owned (`notification_channel_*` settings),
not per-user. Notification delivery is per-recipient by definition — a
registration event reaches administrators, a password expiry one account — so
there is no per-user preference to record.

## Notification Categories

Two disjoint audiences, and no third category.

### Shipped

| Category | Channel | Audience | Class |
|----------|---------|----------|-------|
| Email verification (self-registration) | mail | The affected user | `RegisterNotification` |
| Account created with a temporary password | mail | The affected user | `UserCreatedNotification` |
| Confirm a new email address | mail | The affected user | `ChangeEmailVerificationNotification` |
| Account locked / unlocked / activated / deactivated | mail, database | The affected user **and** every holder of the matching action permission | `AccountStateChangedNotification` |

`UserCreatedNotification` carries a temporary password, which is why it is
personal only: it is handed to the account holder, never to administrators. The
permission that created the account did not receive a copy of the credential.

### The rule, for events not yet dispatched

An administrative event goes to the holders of the permission that PERFORMS it —
`users.lock` for a lock, `roles.assign_permissions` for a role change — never
`users.view` and never a generic `*.manage` (which does not exist in this
catalogue). A personal event goes to the affected user only. An ordinary user is
never an administrative recipient, and an administrator is not opted out of their
own account's alerts.

`NotificationAudience` resolves this and `NotificationAccountStateAction`
dispatches it. The map declares events whose notification classes do not exist
yet — role changes, feature toggles, setting changes. Those are **declared and
unreachable**, and an undeclared event fails toward the narrower audience so a
typo cannot broadcast to every administrator.

The trigger permissions are not `notifications.*`. A `notifications.admin_target`
would gate the rule behind the mechanism it serves.

## Mail Configuration

Storage: `system_settings` rows, read through `SystemSetting` with the `.env`
value as each key's fallback.

| Key | Type | Notes |
|-----|------|-------|
| `mail_mailer` | string | A key of `config('mail.mailers')` |
| `mail_host` | string | |
| `mail_port` | int | 1–65535 |
| `mail_encryption` | string | `smtp` / `tls` / `ssl` / `none`. Stored as `scheme`, **not** `encryption` — `config/mail.php` declares no `encryption` key on the smtp mailer, and Symfony's transport reads `scheme`. `none` is stored as the empty value, which binds as `null` |
| `mail_username` | string | Empty for a relay that needs no authentication |
| `mail_password` | **encrypted** | `encrypt()` on write, `decrypt()` in `bindMailConfig()`. Never sent to a view — the page reports `$hasPassword` instead |
| `mail_from_address` | string | |
| `mail_from_name` | string | |

**`.env` is the fallback, not a competitor.** An install that never saves a row
behaves exactly as before; a saved row simply overrides the same value.

**Rebinding** — `AppServiceProvider::bindMailConfig()` pushes the stored values
onto `config('mail')`. It is called from the save action **after commit**, not
from `boot()`: boot runs before the settings table exists on a fresh install and
before `RefreshDatabase` migrates, and the query failure takes the whole boot
down. A long-lived queue worker keeps its bound transport until
`queue:restart` — accepted, for the same reason token lifetimes accept it.

**Access** — `/notifications`, gated on the `notifications` feature flag plus
`notifications.view` to read and `notifications.manage` to write. Sending a test
message is a third permission, `notifications.send_test`: mailing an address a
user typed is an abuse vector, not a subset of configuring a transport.

## Queue Integration

All notifications and mail sending should be queued (not synchronous):
- Use `ShouldQueue` on notification classes.
- Mail Mailable should be queued via `Mail::queue()`.
- Queue connection is `database` by default (Redis-compatible, see queue.md).

### Scaling

- Notification queue uses the `notifications` queue/connection.
- Horizon is used for monitoring queue workers, retry tracking, and failure
  inspection (see `queue.md`).
- Scale workers horizontally based on notification volume.

### Failure Handling

- Failed notifications go to the `failed_jobs` table (dead-letter pattern,
  see `queue.md`).
- Retry policy: default exponential backoff with max attempts configurable.
- Critical notifications (security alerts, password resets) should bypass
  queue fallback to synchronous delivery on queue failure.

## Template

Mail/notification templates:
- Use Laravel Mailable/Notifications classes.
- Templates in `resources/views/mail/`.
- Support localization (see i18n dual-source).

## Settings Integration

Every transport value above is an operational setting, managed in the UI at
`/notifications` and editable through the API at `/api/v1/notifications`.

This reverses an earlier statement in this document, which said transport
settings "remain technical (config/env only)". That was true while the mail
page was a stub with nowhere to save; it is no longer true, and a base doc that
contradicts the implementation sends the next reader looking for a mechanism
that does not exist.

## Dependency

Notifications/Queue is Phase 9 in the implementation roadmap.
Depends on: Settings (Phase 8 — done), Queue (Phase 1 infrastructure).