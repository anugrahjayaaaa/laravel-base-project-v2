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
| New registration (administrative copy) | mail, database | Every holder of `users.create` | `UserRegisteredNotification` |
| Roles granted / revoked | mail, database | The affected user, **and** `roles.assign` holders when an administrator did it | `RolesChangedNotification` |
| Configuration changed — role, feature flag, system setting, mail transport, channels, user profile | mail, database | The holders of the permission that performs the change | `ConfigurationChangedNotification` |

`UserCreatedNotification` carries a temporary password, which is why it is
personal only: it is handed to the account holder, never to administrators. The
permission that created the account did not receive a copy of the credential.

**Four of these ignore the mail switch** — verification, email-change
confirmation, account state, and the temporary password. Their absence does not
make a notification quieter, it makes a flow uncompletable: a locked account
cannot open the inbox that would carry the news, and the other three carry the
only link or the only credential the user will ever be sent. `NotificationChannel::for(essential: true)`
is what says so, and the channels page states the exception rather than letting
a switch imply an effect it does not have.

### The rule, for events not yet dispatched

An administrative event goes to the holders of the permission that PERFORMS it —
`users.lock` for a lock, `roles.assign_permissions` for a role change — never
`users.view` and never a generic `*.manage` (which does not exist in this
catalogue). A personal event goes to the affected user only. An ordinary user is
never an administrative recipient, and an administrator is not opted out of their
own account's alerts.

`NotificationAudience` resolves this, `NotificationAccountStateAction` and
`NotificationAdminEventAction` dispatch it. The map declares two events no
dispatcher reaches yet — `permission.changed` and `user.deleted`. Those are
**declared and unreachable**; every other mapped event ships. An undeclared event
fails toward the narrower audience, so a typo cannot broadcast to every
administrator.

The trigger permissions are not `notifications.*`. A `notifications.admin_target`
would gate the rule behind the mechanism it serves.

**Audience membership is the Gate's own definition.** A holder is anyone the Gate
would let perform the action: through a role **or** through a permission attached
to the person directly, since Spatie grants both ways and `can()` honours both. A
resolver reading only one of them disagrees with the gate it exists to serve —
the operator qualified to undo an action is not told it happened.

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

Every notification class implements `ShouldQueue`, so delivery is asynchronous
when a worker is running and synchronous when one is not.

Every notification also declares `public $afterCommit = true`. Without it the
framework queues the moment `send()` is reached, and every queue connection here
runs `after_commit => false` — so a worker can deliver before the row that
produced the notification exists, and a rollback still delivers. The property is
what the framework reads to defer the job to the **outermost** commit, so it
holds however deeply a caller nests the action; `UserCreateAction`,
`UserUpdateAction` and `UserRequestEmailChangeAction` additionally send after
their own transaction closes, which is not redundant with the property.

`Illuminate\Bus\Queueable` was removed from these classes along with it: it
declares `$afterCommit` with a null default, and PHP will not let a class
redeclare a trait property with a different default. Nothing in these classes
used any other member of it.

### Scaling

- **Not implemented.** No notification queue or connection is named (no
  `$queue` / `viaQueue`), and Horizon is not installed. Workers run on the
  default connection; see `queue.md` for what exists.
- Dispatch to a dedicated queue is the natural next step if notification volume
  ever competes with other work — it is a per-class `$queue`, not a new
  connection.

### Failure Handling

- Failed jobs go to the `failed_jobs` table (dead-letter pattern, see `queue.md`).
- Retry policy is the framework default. No notification class declares
  `$tries`, `$timeout` or `$backoff`, so a permanently undeliverable address
  retries on the connection's own schedule and then sits in `failed_jobs`.
- Critical notifications are **not** routed around the queue. What protects them
  is that the four account-critical ones ignore the mail switch (see
  Notification Categories), not a synchronous fallback.

## Template

Mail/notification templates:
- Laravel `Notification` classes, not Mailables. The inbox needs `toArray()` and
  the mail needs `toMail()` from one class, because the same event goes to two
  audiences.
- Markdown templates live in `resources/views/vendor/notifications/`
  (`register`, `user-created`). Everything else builds its `MailMessage`
  inline.
- `toArray()`'s `subject` + `lines` shape is a contract with the inbox view and
  the bell dropdown. Escaping happens in the view, because the payload is
  persisted and re-rendered later.
- Localization is **not implemented**: there is no `lang/` directory and every
  string in the notification classes is literal English. Deferred to a final
  project-wide phase (`docs/planning/progress.md`). Do not add `__()` calls
  against a source that does not exist yet.

### Copy rules

The rules below are not style preferences; they come from where these strings are
read. `NotificationCopyTest` asserts the mechanical ones across all seven classes
and both audiences, so the defects it was written for cannot return unnoticed.

| Rule | Why |
|---|---|
| **The body never repeats the subject.** | The subject says what changed; the lines say who did it, what moved, and what to do. A body that restates its own title makes a notification with something to say look empty. |
| **The subject stands alone.** | The bell dropdown shows the subject and nothing else, and truncates it. "Account was locked" names no account; "Locked account: jane" does, and survives truncation because the kind of thing comes first. |
| **Sentence case, no terminal punctuation on the subject.** | Titles in a list, not headlines. Lines are full sentences and keep their full stops. |
| **Address the reader, and name the actor once.** | Personal copy is second person; administrative copy names the account in the subject and the operator at most once between the two. |
| **Say where the next step happens when the notification cannot perform it.** | These payloads carry no link and the inbox has no controls, so a reader who has to go elsewhere is told where — in the mail, or on the page that owns the setting. |
| **Present tense, active voice.** | A notification reports what is true now. "Your account is locked" is shorter and truer than "your account has been locked", and a passive subject spends its first four words on grammar. |

One audience can produce two rows for one event — a self-registration writes both
`RegisterNotification` and `UserRegisteredNotification` — so those two word
different questions deliberately: one carries the action ("Verify your email to
activate your account"), the other the status ("Registration received").

Sentence case itself is **not** asserted by the test: a subject can legitimately
carry a person's name ("Ana Silva changed the roles of jane"), and no mechanical
check separates that from Title Case without a name list. The convention lives in
the template constants; the assertions cover what a machine can judge.

## Settings Integration

Every transport value above is an operational setting, managed in the UI at
`/notifications` and editable through the API at `/api/v1/notifications`.

This reverses an earlier statement in this document, which said transport
settings "remain technical (config/env only)". That was true while the mail
page was a stub with nowhere to save; it is no longer true, and a base doc that
contradicts the implementation sends the next reader looking for a mechanism
that does not exist.

`mail_password` is the one setting that is a credential rather than
configuration. It is `encrypt()`ed at rest, is never part of a controller's view
data (`$hasPassword` is a bool), and is **excluded from `SystemSetting::getAll()`**
— that array renders the settings module's read-only table and answers
`GET /api/v1/settings`, so returning it would hand the ciphertext to every
`settings.view` holder, a wider audience than the `notifications.view` gate the
credential was built for. Read it with `SystemSetting::getString()`, which is
what `bindMailConfig()` does.

## The Unread Count

One number, one source. The bell (header composer) and the inbox both read it
through `UnreadNotificationCount`, and both are invalidated on delivery
(`NotificationSent`) and on both mark-read paths. The inbox used to count live on
every visit, which meant a row written between the header's read and the page's
left one screen showing 3 and the other showing 4 with nothing on screen to
explain the difference.

Two cache entries back it — the count, and the five rows the dropdown shows — and
they are cleared **together**. Clearing only the count is the worst version of
that bug: the badge updates instantly and the list beside it keeps showing the
previous five, so a delivered notification looks like it failed.

It also means the inbox needs no queue worker to become visible: the badge
updates when the delivery happens, not when somebody clicks.

## Retention

The `notifications` table has no ceiling on it, so a daily scheduled sweep
(`notification-retention`) deletes **read** notifications older than 90 days.
Unread rows are kept until they are read: an unread notification is state the
user has not acted on, and deleting it removes a badge nobody was shown.

A direct `DELETE` rather than `model:prune`, which would need a `Prunable`
model and an override of `Notifiable::notifications()` — the relation every
delivery, read and count goes through — to reach a table the framework already
queries correctly.

## Dependency

Notifications/Queue is Phase 9 in the implementation roadmap.
Depends on: Settings (Phase 8 — done), Queue (Phase 1 infrastructure).