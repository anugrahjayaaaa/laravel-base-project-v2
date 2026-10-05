# Phase 9 — Notifications & Mail Management System

> Date: 2026-10-03 (spec) · 2026-10-03 (Group A audit + build) · 2026-10-05 (D1/D2/D4 shipped) · 2026-10-05 (Group B/E scope locked)
> Branch: feature/phase-9-notifications-mail
> Status: Group A DONE · D1/D2/D4 DONE · B, C, E PLANNED (scope locked, unblocked)
> Scope: Mail/SMTP configuration (`SystemSetting`), admin notification configuration, per-user in-app inbox, transactional + system notification delivery.
> Dependency chain: A → B → C → D → E. Group A is verified rendering-complete; D1/D2/D4 shipped ahead of B because a gate must land with what it guards.
> File note: the brief refers to `docs/planning/phase-9-notifications.md`. **This file is the single source of truth** — `phase-9-notifications-mail.md`. Do not create the second name; two plan files for one phase drift, and the tracker links this one.

**No Git commit without owner approval.**

---

## Audit log — 2026-10-03

An audit pass over this document against the code as it stands after Group A.
Every row below is a claim that was checked, not assumed. Findings that changed
the plan are folded into the group tables; documentation debt is listed in
"Audit findings".

### Corrections to the original brief

| Brief said | Code says | Resolution |
|---|---|---|
| `EnsureFeatureIsEnabled:notifications` returns **404** | The middleware returns **403** | **Use 403.** `app/Http/Middleware/EnsureFeatureIsEnabled.php:45-60` records a deliberate 2026-10-01 decision: 404 falsely claims the route does not exist, 400 blames the caller, and one status for "you may not have this" matches `can:` and `CheckAccountState`. Do not re-litigate this per module. |
| Middleware written as `EnsureFeatureIsEnabled:notifications` | The route middleware is the **`feature:notifications`** alias | Already correct below since the Phase 7 rename. Pennant's own middleware aborts **400** and resolves through `Feature::active()`, so a `disabled => true` kill switch reads as active to it — Pennant is not an option here. |
| `notifications.send_test` is a permission | Not seeded; `PermissionCatalog` has 5 groups, none of them notifications | Group A already gates the card on `@can('notifications.send_test')` ahead of the permission existing. Lands with **P9-D1**, in the same commit as the routes it guards. |
| Routes guarded by `auth` | Group A routes sit behind the full auth stack | `['auth:web,sanctum', 'verified', 'password.change.required', 'account.state']` — consistent with every other module route. |

### Verified state

| Claim | Verdict | Evidence |
|---|---|---|
| Group A UI complete | **TRUE** | 2 views, 1 controller, 2 routes, `NotificationUiRenderTest` 19 tests / 119 assertions. |
| Notification module is permission- + flag-gated | **TRUE as of 2026-10-05** | `can:notifications.view` + `feature:notifications` on both routes; composer + header bell filtered; `NotificationAccessTest` 14 tests. |
| `notifications` DB table absent | **TRUE** | `ls database/migrations/ \| grep -i notif` → empty. The `database` channel cannot work. |
| No `notifications.*` permissions | **FALSE as of 2026-10-05** | `PermissionCatalog::NOTIFICATIONS` added with P9-D1; `PermissionSeedTest` covers the new group. |
| No `notifications` feature flag | **FALSE as of 2026-10-05** | Declared in `config/pennant.php` (group `Settings`) with P9-D2; routes and menu both gated. |
| Views must not query | **HELD** | Zero-query assertion green on both pages. |
| Full suite green | **TRUE** | `1060 passed / 4070 assertions` (2026-10-05); the 5 risky are pre-existing (confirmed against a stashed baseline). |

### Audit findings

1. **The header notification bell was a dead button** — resolved 2026-10-05 for the module link (now a real `<a>`, gated flag-then-permission, `NotificationAccessTest::the_bell_is_a_link_not_a_button`). **Still open: the unread badge and the inbox target**, which is what `P9-C2` owns.
2. **All three existing notifications are `via() => ['mail']` only.** `RegisterNotification`, `UserCreatedNotification`, `ChangeEmailVerificationNotification`. Nothing routes to `database` today, so "In-App" is a UI promise with no producer behind it.
3. **No audience-targeting helper exists.** The Target Audience Rule (administrative notifications go to permission holders; ordinary users receive ONLY personal transactional/security alerts) has no code to hang on. **P9-C3** adds it, and it is a real implementation task, not a doc line.
4. **Mail settings storage: DECIDED 2026-10-05 — `SystemSetting` rows.** See
   "Locked decisions" below. Unblocks Group B. The choice was option (a) in
   "Consequences"; option (b), a parallel `mail_settings` table, is rejected.
5. **`Permission::featureOf()` does not exist.** The project conventions skill claims permission groups derive their feature flag through it. No such method exists anywhere under `app/`. Out of Phase 9 scope, but the skill is wrong and someone will follow it. (P9-D4 wires the one link that does exist — `AppMenuComposer`'s explicit `feature` key — rather than inventing the method.)
6. **`docs/base/features/notifications.md` contradicts the plan.** It advertises `slack` / `broadcast` / `vonage` / `push` channels nothing in the app uses or configures, and states transport settings "remain technical (config/env only)" — which Group B's premise reverses. **P9-E3** reconciles it.

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
| Mail configured via `config/mail.php` | driver `smtp`; 8 mailers the UI can read as `<select>` options |
| Queue for mail | `UserCreatedNotification implements ShouldQueue` |
| Group A UI | `pages/notifications/{index,channels}.blade.php`, `NotificationController`, 2 routes, `NotificationUiRenderTest` (19 tests / 119 assertions) |

### What does not exist — verified, not assumed

| Missing | Verified by |
|---|---|
| `notifications` DB table | `ls database/migrations/ \| grep -i notif` → **empty**. Laravel's `database` driver needs that table, so **the Database/In-App channel in Group C cannot work until a migration lands.** |
| Database channel | All three notification classes declare `via() => ['mail']`. Nothing routes to `database` today. |
| ~~`notifications.*` permissions~~ | **RESOLVED 2026-10-05** — `PermissionCatalog::NOTIFICATIONS` exists. |
| ~~`notifications` feature flag~~ | **RESOLVED 2026-10-05** — declared in `config/pennant.php`. |
| Any write endpoint | `NotificationController` has `index` + `channels` only — no `update`, no `sendTestMail`. |
| A working inbox UI | The bell is now a real link (`header.blade.php`), but `/notifications/inbox` does not exist — C5. |
| Mail settings persistence | `config/mail.php` is env-driven; no `SystemSetting` keys for SMTP. |

### Consequences — RESOLVED 2026-10-05 (see "Locked decisions")

1. **The feature flag does not exist.** `config/pennant.php:52-61` documents
   `translations` and `activity_logs` as `pending` flags that "record intent
   only". Adding a THIRD such flag is a category error — it makes the settings
   page grow switches that do nothing. `notifications` gets a flag **in the same
   commit that adds the routes it gates**, per the `PermissionCatalog:74` rule
   already stated for permissions ("Add each group in the same commit that adds
   the page it guards").
2. **Mail settings storage — DECIDED: (a) `SystemSetting` rows.**
   - **(a) `SystemSetting` rows** — CHOSEN. Matches how every other operational
     knob in this repo works (`SET-001`, 37 keys, cache-backed). Reuses
     `SystemSetting::set()` and its `afterCommit` cache bust for free.
   - **(b) a `mail_settings` table** — rejected. A new migration, a new model, a
     new cache layer, and a second settings system beside the one Phase 8 just
     finished reconciling, to hold six strings that already have a home.
3. **`config()` is cached; `SystemSetting` is not.** If SMTP lands in
   `SystemSetting`, the values must be **rebound into `config('mail')` at boot**
   exactly as `AppServiceProvider::bindTokenExpirations()` already does for token
   lifetimes (that method is the precedent, and its tests are the model).
4. **Password storage — DECIDED: encrypt at rest with `encrypt()`.** Laravel's
   `encrypt()` is AES-256-CBC keyed by `APP_KEY`, so a stored credential is
   useless without the application key. No dedicated secrets table — a second
   store for one value re-creates the thing `SystemSetting` already solves.
   Group A made the *UI* half safe (`$hasPassword` instead of the value, asserted
   by `the_stored_smtp_password_is_never_rendered`); this covers the storage half.
   **Divergence to resolve in B1:** `SystemSetting::getString()` returns the raw
   stored string, so a consumer of `mail_password` must `decrypt()` explicitly.
   The read path must NOT route `mail_password` through `getString()` blind.

---

## Locked decisions — 2026-10-05 (owner-signed, unblocks B and C)

These replace the "Open decisions" section at the foot of this document. Each one
names the code it binds to, because a decision that does not match the codebase
is a plan for a different app.

### D-1. SMTP settings live in `SystemSetting` rows

Seven keys, seeded in `SystemSettingSeeder` beside the existing 37:

| Key | Type | Notes |
|---|---|---|
| `mail_mailer` | string | config key of `config('mail.mailers')`, e.g. `smtp` |
| `mail_host` | string | |
| `mail_port` | int | 1–65535 |
| `mail_encryption` | string | see the `scheme` divergence below |
| `mail_username` | string | |
| `mail_password` | **encrypted** | `encrypt()` at write, `decrypt()` at read |
| `mail_from_address` | string | |
| `mail_from_name` | string | |

**Read path** — `SystemSetting::getString('mail_host', config('mail.mailers.smtp.host'))`.
The `.env` value is the DEFAULT argument, so an unconfigured install keeps
working unchanged and a database row simply overrides it. This is the same shape
`AppServiceProvider::bindTokenExpirations()` already uses for token lifetimes.

**Rebinding** — a new `AppServiceProvider::bindMailConfig()` sets
`config('mail.default')`, `config('mail.mailers.smtp.{host,port,username,password,scheme}')`
and `config('mail.from.{address,name}')` from those rows. Called from two places,
both required:

1. `boot()` — so every process (web, queue worker, console) enforces the stored
   transport rather than `.env`. Wrapped in the same `try/catch (Throwable)` as
   `bindTokenExpirations()`: boot runs during `migrate` and `config:cache`, before
   the settings table necessarily exists.
2. **After commit** in `NotificationMailSettingUpdateAction` — so the admin who saved sees it
   enforced in the same request. Never inside the transaction: binding config
   from values a rollback discards leaves the process enforcing what the database
   never accepted. That trap is exactly what `bindTokenExpirations()` documents.

**Invalidation** — `SystemSetting::set()` already registers
`DB::afterCommit(fn () => static::bustCache())` and clears the request-level static
first. `bustCache()` is therefore **not** called again by the action; doing so is
harmless but redundant, and the reason the spec's "bust after commit" requirement
is already satisfied by the model. Multi-instance correctness is inherited from
whatever cache driver is configured — see the ceiling in `ponytail` below.

**⚠ Divergence 1 — `config/mail.php` has no `encryption` key.** The `smtp` mailer
declares `'scheme' => env('MAIL_SCHEME')` (`config/mail.php:42`) and nothing else;
`encryption` does not exist anywhere in the file. The form offers
`smtp|tls|ssl|none`. **B1 must write to `config('mail.mailers.smtp.scheme')`, and
the `in:` rule must accept the values Symfony actually reads** (`null`, `smtp`,
`tls`, `ssl`) — `none` is not a transport scheme and binding it silently produces
a plaintext socket. Decide at B1: either drop `none` from the select or map it to
`null`. Do not ship a stored value that `config/mail.php` never reads.

**⚠ Divergence 2 — there is no `SystemSetting::get()`.** The spec's
`SystemSetting::get('key', config('fallback'))` does not exist. The model ships
`getBool()`, `getInt()`, `getString()` and `getAll()` (`app/Models/SystemSetting.php:71-117`).
Use `getString($key, $fallback)` / `getInt($key, $fallback)` — the signature and the
intent are identical, only the name differs. **Do not add a `get()` alias**; one
accessor per cast type is the existing shape and a second name for it is the drift
this repo keeps paying for elsewhere.

### D-2. Admin configuration and the user inbox are different surfaces

| Surface | URL | Gate |
|---|---|---|
| Admin mail configuration | `/notifications` | `feature:notifications` + `notifications.view` |
| Admin channel switches | `/notifications/channels` | same |
| **User inbox** | `/notifications/inbox` | **`feature:notifications` only — any authenticated user** |

The inbox reads `Auth::user()->unreadNotifications()`: a user's OWN rows. There
is no permission for it, because there is nothing to authorize — a user cannot
read another user's notifications through it. It sits behind the flag because it
is part of the module and must vanish with it, and behind the full auth stack
because it is a page at all.

**The bell follows this.** Its target moves from `/notifications` to
`/notifications/inbox` at C2, and the permission check in
`AppServiceProvider` (`$notificationsVisible`) is **dropped at the same time** —
the current check exists only because the bell points at a permission-gated page.
Leaving it would hide the bell from every ordinary user, which is the whole
audience of an inbox.

**Menu consequence:** the inbox gets NO sidebar entry. It is reached from the bell
and from `/notifications`; a nav item for a page every user already has a bell for
is a third link to the same place.

### D-3. Target Audience Rule (P9-C3) — two disjoint audiences

| Audience | Recipients | Examples |
|---|---|---|
| Administrative / system | holders of `roles.manage`, `users.manage`, `settings.manage` | new user registered, account locked, feature flag flipped, role changed |
| Personal transactional / security | the affected user ONLY | password expiring, must-change-password, email-change verification, order/payment/resi for that account |

**No overlap, and no third category.** An ordinary user is never an
administrative recipient; an admin is not opted out of their own personal alerts
(they hold the admin permissions, but a password-expiry notice about their own
account is personal regardless). The test for this rule is a test asserting an
ordinary user receives nothing from an administrative dispatch — a rule with no
such test is a comment, not a rule.

`roles.manage` / `users.manage` / `settings.manage` are the **trigger** permissions
for recipient resolution, NOT new `notifications.*` permissions. Creating
`notifications.admin_target` would put the audience rule behind the very gate it
is meant to serve.

---

## Naming divergences — decide, do not silently diverge

| Brief name | Recommendation |
|---|---|
| Route `/settings/mail` (D2) | **Use `/notifications`** for the index and channels. The brief names `/settings/mail` in one task and `pages/notifications/*` in another; one module, one URL. Phase 8 already set the precedent that `/settings` is `settings.view`-gated and owned by `SystemSettingController`. |
| `UpdateMailSettingsRequest` under `Requests\V1\Notification\` | **Keep.** Matches `Requests\V1\System\` for the settings module; namespace follows the module, not the resource. |
| Event `mail_settings.updated` | **Keep.** Singular-to-plural: Phase 8 established `system_setting.updated` (singular matches the subject type). Here the subject is a settings row, so **`mail_setting.updated`**. |
| `EnsureFeatureIsEnabled:notifications` (D2) | **The middleware is `feature:notifications`** — Pennant ships the alias. `EnsureFeatureIsEnabled` is what `routes/web.php` uses for flags; do not invent a new class. |
| `NotificationTestMailSendAction` | **Keep**, but the transport check is `Mail::mailer()->getSymfonyTransport()` — Laravel already owns it. No custom SMTP handshake. |

---

## Group A — Views, UI & Layout — **DONE**

> Dependency: none. Verified rendering-complete.

| ID | Task | Status |
|---|---|---|
| **P9-A1** | `pages/notifications/index.blade.php` — two-column, `col-lg-8` SMTP form, `col-lg-4` Send Test Mail card. Wrapper `card border-0 shadow-sm mb-4`, footer `card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2`. No query in Blade. | DONE |
| **P9-A2** | `pages/notifications/channels.blade.php` — per-channel toggles, hidden `value="0"` + checkbox `value="1"`, hidden placed directly BEFORE. | DONE |
| **P9-A3** | `NotificationUiRenderTest` — 19 tests / 119 assertions: zero queries from inside the views, forbidden classes, `filter_var` ban, checkbox pairs, both permission branches, sibling-page link in both branches and its card-header action styling. | DONE |
| **P9-A4** | `NotificationController` stub (`index`/`channels`) + 2 routes. Every variable assembled in the controller, never in Blade. | DONE |
| **P9-A5** | Group A audit — findings below. | DONE |

A1/A2 are `@can`-gated like the settings page: `notifications.view` alone renders
**read-only**, with the whole form inside the `@can` pair — gating the button alone
leaves editable fields that silently discard input.

### Group A re-audit — 2026-10-05 (every claim re-checked against the code)

Group A was audited a second time after D1/D2/D4 landed, because those commits
changed things Group A's own findings describe. Method: grep / `route:list` / test
run per claim, not a re-read of this document.

| Claim | Verdict | Evidence |
|---|---|---|
| A1 two-column `col-lg-8` + `col-lg-4` | **HELD** | one occurrence each |
| A1 wrapper + footer classes | **HELD** | wrapper 4×, footer 2×, every `card-body` is `p-4` |
| A1 no query in Blade | **HELD** | 0 hits for `SystemSetting::` / `DB::` / `config(` / `Notification::` |
| A2 hidden companion directly BEFORE each checkbox | **HELD** | verified in markup and by `every_channel_switch_has_a_hidden_companion` |
| A3 test count | **HELD, count corrected** | 17/98 → **19/119** after the sibling-link and action-styling tests |
| A4 controller is `index`/`channels` only | **HELD** | asserted by the method-list test |
| A4 route middleware stack | **HELD** | `auth:web,sanctum` → `verified` → `password.change.required` → `account.state` → `feature:notifications` → `can:notifications.view` |
| A5 "routes carry no `->can()`" | **STALE — fixed above** | both gates landed in D1/D2 |

**One real gap found and closed:** the channels page breadcrumbs back to the mail
page, but the mail page had no way back — reaching Channels required the sidebar,
which is the least navigation available to a read-only viewer. Both card headers
on `index` now carry the sibling link (the pattern `users/index` uses for
`users/create`), asserted on both permission branches by
`the_mail_page_links_to_the_channels_page_in_both_branches` — including that the
read-only badge is not displaced by the link that was added beside it. The link
was then restyled to the design system's card-header action (`btn-primary`,
`design-system.md` §Index Page) and pinned right — on the button itself where it
alone shares the header, on the wrapper where the read-only badge shares the
row.

### Group A audit findings

- **Checkbox pairs verified by mutation, not by reading.** Deleting a hidden companion turns `every_channel_switch_has_a_hidden_companion` red; restoring it turns the test green again. The guard bites.
- **The zero-query assertion alone is NOT sufficient.** Injecting `SystemSetting::getAll()` into Blade left the runtime query count **green**, because `getAll()` is cache-backed — only the source-scan guard caught it. Both guards are load-bearing; neither is redundant, and neither should be removed as "the other covers it".
- **`card-body p-4` was violated deliberately and then reverted.** The channels table body was first written `p-0` for an edge-to-edge table. Every other table card (features, users, permissions) uses `p-4`, so the one-off was reverted rather than shipped as a new variant.
- **`@can('notifications.send_test')` is a narrowing gate.** It sits inside the manage branch, so `send_test` alone renders neither the card nor the form. Tested in both directions. Sending to an arbitrary address is a real abuse vector, hence a third permission rather than a subset of manage.
- **The permission fixture is `Gate::before`, not seeded roles** — deliberate while `notifications.*` is unseeded. It returns `null` for every other ability so Spatie still decides those; returning `false` would deny every gate in the app. **Replace with real seeded roles in P9-D1** — the test class docblock says so.
- **The routes carried no `->can()` and no `feature:notifications` when Group A shipped**, on purpose: a gate against an unseeded permission is a 403 on every page. **Both have since landed** (P9-D1 `can:notifications.view`, P9-D2 `feature:notifications`); the finding is kept as the record of why the gate was deferred, not as a statement about the current code.
- **The stored SMTP password never reaches view data.** `$hasPassword` (bool) replaces it, asserted by `the_stored_smtp_password_is_never_rendered`, which also fails if a future fixture quietly adds the credential back.

---

## Group B — Mail Configuration & Sending — **DONE**

> Dependency: Group A complete. Storage and password are now **decided**
> (D-1), so nothing blocks this group. `SystemSetting` + `encrypt()` only — no
> migration, no new settings store, no secrets table.

| ID | Task | Notes |
|---|---|---|
| **P9-B1** | **DONE** — `UpdateMailSettingsRequest` extends `BaseFormRequest`; `authorize()` → `notifications.manage`. Rules: mailer `Rule::in(array_keys(config('mail.mailers')))`, host, port (int 1–65535), encryption `Rule::in(['smtp','tls','ssl','none'])`, username, password (nullable), from address (`email:rfc`) + name. | `Rule::in` over encryption, not a free string. **The `none`-vs-`null` question is resolved**: `none` is accepted by the form and stored as the empty value, which `bindMailConfig()` binds as `null`. `none` is not a transport scheme, so storing the literal string would be a row nothing reads (D-1 divergence 1). |
| **P9-B2** | **DONE** — `NotificationMailSettingUpdateAction`: `DB::transaction`, `SystemSetting::set()` per key, `encrypt()` on the password, audit **inside** the transaction, then `bindMailConfig()` **after** commit. Supports `$partial` for the API (absent key = unchanged) and non-partial for the web form. | After-commit, not inside: binding config from values a rollback discards leaves the process enforcing what the database never accepted — the trap `bindTokenExpirations()` documents. Cache bust is **free** — `set()` already does `afterCommit(bustCache)`; no second call. The password is written **only when a new one was submitted**: the field renders blank, so an empty submit must not overwrite a working credential. |
| **P9-B3** | **DONE** — `NotificationTestMailSendAction`: `Mail::raw()` through the configured transport, catches every transport failure, returns `{ok, message}`, logs the exception with the host and mailer, audits `test_mail.sent` on success and `test_mail.failed` on failure. | Never let an SMTP exception 500 the page. `Mail::raw`, not a Notification: there is no notification class for "is the transport working", and the probe must not depend on the delivery system it tests. The failure message carries no host, username or server banner — the operator gets the log, the page gets the actionable half. |
| **P9-B4** | **DONE** — `Web\V1\NotificationController` (`index`/`channels`/`update`/`updateChannels`/`sendTestMail`) + `Api\V1\NotificationController` sharing every action. API returns booleans for the channel switches, `has_password` instead of the credential, and 502 on a transport failure (the request was understood; the mail server is what failed). | API `PUT`, not `POST` — matches `api.v1.settings.update`. |
| **P9-B5** | **DONE** — the controller builds `$updateUrl` / `$sendTestUrl` / the channels URL from `route()`. Write routes carry `->can('notifications.manage')`; the test route carries `->can('notifications.send_test')`. | The views needed **no** change — the URLs were already the seam Group A left. This is what stops the form POSTing to a 404. |
| **P9-B6** | **DONE, with a correction** — `bindMailConfig()` is called from the ACTION after commit, **not** from `boot()`, matching `bindTokenExpirations()`'s own reasoning: boot runs before the settings table exists and the query failure would take the boot down. 8 `SystemSettingSeeder` keys added. | See "Group B correction" below — the typed-getter call with a null default bound nothing at all. |
| **P9-B7** | **DONE** — 3 `notification_channel_*` keys seeded, plus `NotificationChannelUpdateAction` and `UpdateNotificationChannelsRequest`. `in_app` is seeded **off** until Group C ships the badge that gives it a meaning. | Same seeder, same store as the SMTP keys. A switch that reports success and controls nothing is the same category error as a `pending` feature flag. |

### Group B correction — a null default bound nothing at all

Found while writing the Group B tests, and it would have shipped as "the feature
does not work on most installs".

`bindMailConfig()` reads each setting with the runtime config as its fallback:

```php
// BROKEN — this is what was written first
'username' => SystemSetting::getString('mail_username', config('mail.mailers.smtp.username')),
```

`config('mail.mailers.smtp.username')` is **`null`** whenever `MAIL_USERNAME` is
unset — which is most installs, and every install using a relay that needs no
authentication. `SystemSetting::getString(string $key, string $default)` rejects a
null default with a `TypeError`. The exception landed in `bindMailConfig()`'s
`catch (Throwable)`, so **not one key was bound**: an admin saved a host, the save
returned 200, the row was written, and the transport kept using the `.env`
values. The catch hid it completely.

Fixed by casting every fallback before it reaches a typed getter, and by making
the catch log instead of swallowing — `bindTokenExpirations()` may swallow because
its failure means "no table yet", which is expected during `migrate`; this one
would be a real bug, so it has to be visible.

The regression is asserted by
`NotificationSettingsTest::an_install_without_a_configured_username_still_binds`,
with the null-default form reintroduced to confirm the test goes red.

**The general lesson, recorded because it is not specific to mail:** a `catch`
that swallows a `TypeError` around a loop of writes is not a safety net, it is a
way of losing all of them at once. Where a partial failure is acceptable, each
step catches for itself.

---

**ponytail:** the read path is `SystemSetting::getString($key, config('fallback'))`
in one helper. The whole rebinding is ~20 lines of `config()->set()` in a method
that already has a precedent in the same file. Do not build a mail-settings
service, a config repository, or a settings DTO — the model, the seeder and
`bindTokenExpirations()` are all already here.

**Ceiling, named rather than hidden:** a long-lived queue worker started before a
transport change keeps enforcing the transport it bound at boot until it is
restarted — `queue:restart` after saving. This is the `ponytail` ceiling already
documented on `bindTokenExpirations()` for token lifetimes, accepted for the same
reason: a value that changes perhaps quarterly is not worth a query per send.

---

## Group C — In-App Engine, Bell Icon & User Inbox — **PLANNED**

> Dependency: A + B. **Blocked only on the `notifications` table migration** —
> nothing in this group can work without it, since `DatabaseNotification` is the
> whole design.

| ID | Task | Notes |
|---|---|---|
| **P9-C1** | `notifications` migration (Laravel's native schema: `uuid`, `type`, `notifiable`, `data`, `read_at`, timestamps) + the write action for the channels form. | **No per-user preference table** (owner decision 1). The migration is for Laravel's `database` channel, not for preferences; the channel switches are global admin state and live in `SystemSetting` (B7). |
| **P9-C2** | In-app engine + header bell: unread badge, preview dropdown, and the target moves to `/notifications/inbox`. Drop the permission check from `$notificationsVisible` in the same change (D-2). | `DatabaseNotification` does unread-count / mark-as-read / mark-all-as-read **natively** — do not hand-roll. The count belongs on the existing `layouts.app` composer, **not** a per-page lookup: an inline `unreadNotifications()` in the partial queries on every admin page. |
| **P9-C3** | **Target Audience Rule** — administrative/system notifications → holders of `roles.manage` / `users.manage` / `settings.manage`; personal transactional/security → the affected user only (D-3). | No targeting helper exists in `app/` today, so this is real implementation. Resolve audiences through the existing `RoleLookup` machinery, not a fresh permission query. **Needs its own test asserting an ordinary user is never an administrative recipient** — otherwise the rule is a comment. |
| **P9-C4** | Sidebar menu item per admin page, gated on flag then permission | **DONE** — `AppMenuComposer` System group carries one flat entry per page (`notifications.index`, `notifications.channels`), each with flag `notifications` then `notifications.view`, each `active` pattern EXACT (`notifications.*` would highlight both on either page). `NotificationAccessTest` (14 tests) asserts both directions, the bell, and one-highlight-per-page. |
| **P9-C5** | `/notifications/inbox` route + controller + view: full history, `markAsRead` / `markAllAsRead`. Gate = `feature:notifications` + auth only, **no permission** (D-2). | Self-scoped: `$user->unreadNotifications()` and `$user->readNotifications()` only. A notification of another user's is not reachable by id — resolve through the relation, never `findOrFail($id)`. |
| **P9-C6** | Route the `database` channel through the existing notification classes: `via()` becomes `['database', 'mail']`. | Without this the "In-App" switch controls nothing (audit finding 2). All three classes are `['mail']` only today. |

**ponytail:** the whole in-app engine is Laravel's `database` driver plus three
model methods. Any custom table or manager re-implements shipped functionality.
The badge is a count on an existing composer, not a component library.

---

## Group D — Security Enforcement, Feature Flags, RBAC & Audit — **D1, D2, D4 DONE · D3 PLANNED**

> D1/D2/D4 landed together on 2026-10-05 as one change: a `can:` against an
> unseeded permission and a `feature:` against an undeclared slug are both dead
> gates, and the plan's own rule is to ship each with what it guards.

> Dependency: A + B + C. D1/D2/D4 ship early: a permission and a flag nothing
> gates is a row that lies in the UI, so the gate lands with the code it guards.

| ID | Task | Notes |
|---|---|---|
| **P9-D1** | **DONE** — `notifications.view` / `.manage` / `.send_test` in `PermissionCatalog`, `can:notifications.view` on both web routes in the same change. | `.send_test` is a third permission — mail to an arbitrary address is a real abuse vector, not a subset of `manage`. The API routes land with B4. |
| **P9-D6** | **OPEN** — replace `NotificationUiRenderTest`'s `Gate::before` fixture with real seeded roles, asserting the precondition (`assertFalse($viewer->can(...))`). | Still owed from D1: the fixture was a deliberate stopgap while the permissions were unseeded. Now they exist, so the override tests the override. |
| **P9-D2** | **DONE** — flag declared in `config/pennant.php` (group `Settings`, no `pending` marker), `feature:notifications` on both admin routes, `FeatureFlagSeeder` writes the row, and the sidebar entry is filtered in `AppMenuComposer` (flag first, then permission). **A disabled flag returns 403, not 404** — see Corrections. | All three places, or it is not gated. The middleware already existed; this added the flag, not a class. **Not `@feature()` in Blade** — this repo filters the menu in the composer, so a `@feature()` wrap would be a second mechanism answering a question the composer already answers. |
| **P9-D5** | `feature:notifications` on `/notifications/inbox` too. | The inbox is part of the module and must vanish with it — but with **no** permission (D-2). Lands with C5. |
| **P9-D4** | **DONE** — sidebar entry + header bell, both gated flag-then-permission; `NotificationAccessTest` (14 tests) proves the menu never shows a link the route refuses, in both directions, for admin, superadmin and plain user. |
| **P9-D3** | Action-first audit: `mail_setting.updated` + `test_mail.sent` inside the action, inside the transaction. | Never the controller. Naming matches `system_setting.updated` and `feature.toggled` — singular subject, dotted event. |

### Owner decisions — 2026-10-05

1. **No per-user channel preferences.** Notif delivery is per-recipient by
   definition (registration event → admins; password expiry → that account;
   task/payment/resi/order → the affected user). A global toggle set per admin
   describes a screen nobody needs, so the "who may update the configuration"
   gate is `notifications.manage` and nothing else. **The channels page becomes
   an admin-set of global delivery switches**, not a per-user preference screen —
   `P9-C1`'s migration shrinks to the switches' storage, and no preference
   table is written.
2. **Notifications is its own sidebar module, not a `settings` section.** The
   transport (SMTP host, port, credential) is an abuse surface of its own, so
   it keeps `notifications.*` permissions instead of riding on `settings.view`.
   Flag `notifications` is declared, routes gated, and the composer filters the
   entry — flag first, permission second, in `AppMenuComposer`'s existing order.
3. **The bell links into the module and is gated like it** — same flag, same
   permission. A bell that answers 403 on click is worse than the dead button it
   replaced. When `P9-C2` moves it to the inbox (the viewer's own rows, no
   permission needed) the bell drops the permission check and keeps the flag.
   Both pages get their own flat sidebar entry — the partial renders flat `<li>`
   items only, and nesting one item for a single sub-page means changing shared
   layout markup. Revisit when the module has a real tree.

---

## Group E — Tests & Documentation Reconciliation — **PLANNED**

| ID | Task | Notes |
|---|---|---|
| **P9-E1** | `NotificationSettingsTest` — SMTP update round-trips through `SystemSetting`, test-mail delivery, RBAC 403 per permission, feature-flag 403, audit row per action. | Same shape as `SystemSettingUpdateTest`. |
| **P9-E2** | **NEW** — `mail_password` is encrypted at rest: assert the stored `system_settings.value` does NOT contain the plaintext, and that the read path returns the original. | Without this, `encrypt()` is an untested claim. Assert against the DB row, not the rendered page — the page already never shows the value. |
| **P9-E3** | **NEW** — inbox isolation: user A cannot mark user B's notification read, and cannot reach another's notification by id. | The inbox has no permission and reads `Auth::user()`'s relations; isolation is the only thing standing between two users' data, so it must be asserted. |
| **P9-E4** | **NEW** — Target Audience Rule test: an ordinary user receives nothing from an administrative dispatch (C3). | Named as a requirement in the plan and in `docs/base/features/notifications.md`; it has never been asserted. |
| **P9-E5** | **NEW** — bell target test: the bell's href is `/notifications/inbox`, and it renders for a plain user with no `notifications.*` permission. | Pairs with `NotificationAccessTest::the_header_bell_is_hidden_from_a_user_the_route_refuses`, which becomes the *previous* behaviour and must be inverted when C2 lands. |
| **P9-E6** | Full suite green + reconcile `progress.md`, `task-tracker.md`, `feature-tracker.md`, and this document. | |
| **P9-E7** | Reconcile `docs/base/features/notifications.md` — it advertises `slack` / `broadcast` / `vonage` / `push` channels nothing uses, and states transport settings "remain technical (config/env only)", which D-1 reverses. | Audit finding 6. The doc is the source of that contradiction, so it is the thing that must change. |
| **P9-E8** | **DONE** — correct the project conventions skill: `Permission::featureOf()` does not exist (audit finding 5). | Fixed 2026-10-05 in the `laravel-base-project-conventions` skill, along with the `@feature()`-in-Blade claim that does not match this repo's composer. |

---

## Open decisions — none blocking

Resolved 2026-10-05, recorded as **Locked decisions** above:

1. ~~**Mail settings storage**~~ → **D-1**: `SystemSetting` rows.
2. ~~**`mail_password`**~~ → **D-1**: `encrypt()` at rest, `decrypt()` at read.
3. ~~**Route naming**~~ → `/notifications` (unchanged from the earlier recommendation).
4. ~~**Scope of C1**~~ → global, admin-owned. No per-user preference rows.

Still open, and **not** blocking B:

5. **`none` vs `null` for the mail encryption scheme** (D-1 divergence 1) — a
   B1 implementation choice, recorded there rather than decided here.
6. **Whether the channels page keeps its three switches at all** — `mail` and
   `database` are real; `in_app` is a UI concept that only means something once
   the badge exists (C2). Shipping a switch for a channel with no producer is the
   same category error as a `pending` feature flag.

## Out of scope (deferred, stated not forgotten)

- Email template editing UI (the brief's "template email" has no task; `A1` only
  configures SMTP).
- Horizon / queue dashboard for mail failures.
- Bounce, complaint and unsubscribe handling.
- Queue `queue:restart` automation after a transport change — documented as the
  ceiling in Group B, deliberately not automated.
