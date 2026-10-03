# Phase 9 — Notifications & Mail Management System

> Date: 2026-10-03 | Branch: feature/phase-9-notifications-mail | Status: SPEC — nothing built
> Scope: Mail/SMTP configuration, email templates, per-user channel preferences (In-App, Mail, Database), transactional + system notification delivery.
> Dependency chain: A → B → C → D → E. Group A is verified rendering-complete before B starts.

**No Git commit without owner approval.** This document is the deliverable of
this phase; code follows only after it is agreed.

---

## Headline finding — read before planning any work

**This brief was written against an assumed-greenfield state. Unlike Phase 8,
that assumption is closer to true here — but not entirely.** Audited against the
code, not the brief.

### What already exists

| Asset | Evidence |
|---|---|
| `Notifiable` trait on User | `app/Models/User.php:30` |
| 3 mail notifications | `RegisterNotification`, `UserCreatedNotification`, `ChangeEmailVerificationNotification` |
| 2 markdown mail templates | `resources/views/vendor/notifications/{register,user-created}.blade.php` |
| Mail configured via `config/mail.php` | driver `smtp`, `127.0.0.1:1025` |
| Queue for mail | `UserCreatedNotification implements ShouldQueue` |

### What does not exist — verified, not assumed

| Missing | Verified by |
|---|---|
| `notifications` DB table | `Schema::hasTable('notifications')` → **false**. No migration matches `notification`. |
| Database channel | Laravel's `database` driver needs that table. **The "Database/In-App" channel in Group A2/C2 cannot work until a migration lands.** |
| `notifications.*` permissions | `PermissionCatalog` has `SETTINGS`, `FEATURES`, `USERS`, `ROLES`, `PERMISSIONS`. No `NOTIFICATIONS` const. |
| `notifications` feature flag | `config/pennant.php` has 8 entries; `notifications` is not one. |
| Any `/notifications` route | No match in `routes/web.php` or `routes/api.php`. |
| Any notification controller/action/request | No file under `app/` matching those names. |
| Mail settings persistence | `config/mail.php` is env-driven only; no `SystemSetting` keys for SMTP. |

### Consequences — decisions required before Group B

1. **The feature flag does not exist.** `config/pennant.php:52-61` documents
   `translations` and `activity_logs` as `pending` flags that "record intent
   only". Adding a THIRD such flag is a category error — it makes the settings
   page grow switches that do nothing. `notifications` gets a flag **in the same
   commit that adds the routes it gates**, per the `PermissionCatalog:74` rule
   already stated for permissions ("Add each group in the same commit that adds
   the page it guards").
2. **Mail settings storage is undecided.** `config/mail.php` reads env. Two
   options:
   - **(a) `SystemSetting` rows** — matches how every other operational knob in
     this repo works (`SET-001`, 37 keys, cache-backed). Reuses
     `SystemSetting::set()` and its `afterCommit` cache bust for free. Needs
     ~12 new seeded keys.
   - **(b) a `mail_settings` table** — a new migration, a new model, a new cache
     layer, and a second settings system beside the one Phase 8 just finished
     reconciling.

   **Recommendation: (a).** The ladder says reuse what exists. Option (b) builds
   a parallel configuration store to hold six strings that already have a home.
3. **`config()` is cached; `SystemSetting` is not.** If SMTP lands in
   `SystemSetting`, the values must be **rebound into `config('mail')` at boot**
   exactly as `AppServiceProvider::bindTokenExpirations()` already does for token
   lifetimes (that method is the precedent, and its tests are the model).
4. **Password storage.** `mail_password` in a `SystemSetting` row would be a
   plaintext SMTP credential in a table any settings reader can query. Needs an
   explicit decision — encrypt, or a dedicated secrets table. **Not optional.**

---

## Naming divergences — decide, do not silently diverge

| Brief name | Recommendation |
|---|---|
| Route `/settings/mail` (D2) | **Use `/notifications`** for the index and channels. The brief names `/settings/mail` in one task and `pages/notifications/*` in another; one module, one URL. Phase 8 already set the precedent that `/settings` is `settings.view`-gated and owned by `SystemSettingController`. |
| `UpdateMailSettingsRequest` under `Requests\V1\Notification\` | **Keep.** Matches `Requests\V1\System\` for the settings module; namespace follows the module, not the resource. |
| Event `mail_settings.updated` | **Keep.** Singular-to-plural: Phase 8 established `system_setting.updated` (singular matches the subject type). Here the subject is a settings row, so **`mail_setting.updated`**. |
| `EnsureFeatureIsEnabled:notifications` (D2) | **The middleware is `feature:notifications`** — Pennant ships the alias. `EnsureFeatureIsEnabled` is what `routes/web.php` uses for flags; do not invent a new class. |
| `TestMailSendAction` | **Keep**, but the transport check is `Mail::mailer()->getSymfonyTransport()` — Laravel already owns it. No custom SMTP handshake. |

---

## Group A — Views, UI & Layout

> Dependency: none. Verified rendering-complete before Group B.

| ID | Task | Status |
|---|---|---|
| **P9-A1** | `pages/notifications/index.blade.php` — two-column, `col-lg-8` SMTP form, `col-lg-4` Send Test Mail card. Wrapper `card border-0 shadow-sm mb-4`, footer `bg-body-tertiary border-top py-3`. **No query in Blade** — controller passes every variable. | PLANNED |
| **P9-A2** | `pages/notifications/channels.blade.php` — per-channel toggles. **Checkbox pairs use hidden `value="0"` + checkbox `value="1"`** (convention §4d), never `nullable`. | PLANNED |
| **P9-A3** | `NotificationUiRenderTest` — renders clean on dummy data, asserts **zero queries from inside the view**, and asserts the forbidden-class list (`bg-white`, `bg-light`, `filter_var` in Blade). Model on `SettingsUiRenderTest`. | PLANNED |

A1/A2 are `@can`-gated like the settings page: `notifications.view` alone must
render **read-only**, with the whole form inside the `@can` pair — gating the
button alone leaves editable fields that silently discard input.

---

## Group B — Mail Configuration & Sending

> Dependency: Group A complete. **Blocked on decision (2) storage and (4) password.**

| ID | Task | Notes |
|---|---|---|
| **P9-B1** | `UpdateMailSettingsRequest` extends `BaseFormRequest`; `authorize()` → `notifications.manage`. Rules: host, port (int 1–65535), encryption `in:smtp,tls,ssl,none`, username, password (nullable, never echoed back), from address + name. | `Rule::in` over encryption, not a free string. |
| **P9-B2** | `MailSettingUpdateAction` — `DB::transaction`, `SystemSetting::set()` per key (cache bust is free), audit **inside** the transaction, then rebind `config('mail')` **after** commit. | After-commit, not inside: binding a config from values a rollback discards would leave the process enforcing config the database never accepted — the exact trap `bindTokenExpirations()` documents. |
| **P9-B3** | `TestMailSendAction` — send to a validated address via the configured transport, catch transport failure, return a user-facing error + `Log::error`. | Never let an SMTP exception 500 the page. |
| **P9-B4** | `Web\V1\NotificationController` (`index`/`update`/`sendTestMail`) + `Api\V1` twin sharing the actions. | API `PUT`, not `POST` — matches `api.v1.settings.update`. |

---

## Group C — Channels & In-App

> Dependency: A + B. **Blocked on the `notifications` table migration.**

| ID | Task | Notes |
|---|---|---|
| **P9-C1** | `NotificationChannelPreferenceUpdateAction` | Per-user preference rows. Needs a migration too. |
| **P9-C2** | In-app manager: `unreadCount`, `markAsRead`, `markAllAsRead` | Laravel's `DatabaseNotification` does this natively. **Do not hand-roll** — wire the `database` channel and use `unreadNotifications()`. |

**ponytail:** the whole in-app engine is Laravel's `database` driver plus three
model methods. Any custom table or manager is re-implementing shipped
functionality.

---

## Group D — Security, RBAC, Flags, Audit

> Dependency: A + B + C.

| ID | Task | Notes |
|---|---|---|
| **P9-D1** | `notifications.view` / `.manage` / `.send_test` on web **and** API routes | Add the `NOTIFICATIONS` const to `PermissionCatalog` **in the same commit**. `notifications.send_test` is a third permission — sending mail to an arbitrary address is a genuine abuse vector, not a subset of manage. |
| **P9-D2** | `feature:notifications` middleware; flag in `config/pennant.php` + `FeatureFlagSeeder` + sidebar `@feature()` | All three places, or it is not gated. Drop the `pending` marker — this one is real. |
| **P9-D3** | Audit `mail_setting.updated` + `test_mail.sent` inside the action, via `$setting->audit()` | Never the controller. |

---

## Group E — Tests & Wrap-up

| ID | Task |
|---|---|
| **P9-E1** | `NotificationSettingsTest` — update, test-mail delivery, RBAC 403 per permission, feature-flag 404, audit row per channel. |
| **P9-E2** | Full suite green + reconcile `progress.md`, `feature-tracker.md`, `task-tracker.md`, `phase-9-*.md`. |

---

## Open decisions — need Jaya before Group B

1. **Mail settings storage**: `SystemSetting` rows (recommended) vs a new table.
2. **`mail_password`**: encrypt at rest, or a dedicated secrets table? A
   plaintext SMTP credential readable by anyone with `notifications.view` is not
   shippable.
3. **Route naming**: `/notifications` (recommended) vs the brief's `/settings/mail`.
4. **Scope of C1**: are channel preferences per-user or global-admin-only for
   this phase?

## Out of scope (deferred, stated not forgotten)

- Email template editing UI (the brief's "template email" has no task; `A1` only
  configures SMTP).
- Horizon / queue dashboard for mail failures.
- Bounce, complaint and unsubscribe handling.