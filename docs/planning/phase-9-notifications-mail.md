# Phase 9 — Notifications & Mail Management System

> Date: 2026-10-03 (spec) · 2026-10-03 (Group A) · 2026-10-05 (D1/D2/D4, Group B, Groups C and E) · 2026-10-08 (audit of the shipped phase, copy rewrite, performance, security)
> Branch: feature/phase-9-notifications-mail
> Status: **Groups A–E DONE, and the shipped phase re-audited 2026-10-08.** Every task is shipped and verified. Four passes ran after the groups closed: a code audit (7 findings, all closed), a copy rewrite, a performance measurement, and a pentest (11 probes, no findings). Full suite **1301 passed / 5087 assertions**.
> Scope: Mail/SMTP configuration (`SystemSetting`), admin notification configuration, global delivery channel switches, per-user in-app inbox, transactional + system notification delivery.
> Dependency chain: A → B → C → D → E. Groups A, B and C are shipped; D1/D2/D4 shipped ahead of B because a gate must land with what it guards; D3 and E1/E2 shipped with B.
> Post-ship work is tracked as **P9-X1..X9** in `task-tracker.md` and recorded here under "Audit of the shipped phase", "Audit of the shipped copy" and "Verification — performance".
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
| Full suite green | **TRUE** | `1090 passed / 4197 assertions` (2026-10-05, after Group B); the 5 risky are pre-existing. |

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
| Group B write path | 3 actions in `App/Actions/V1/Notification/`, 3 Form Requests, Web + API controllers, 3 write routes per channel, 11 `SystemSetting` keys, `bindMailConfig()`, `NotificationSettingsTest` (19 tests / 77 assertions) |

### What does not exist — verified, not assumed

| Missing | Verified by |
|---|---|
| `notifications` DB table | `ls database/migrations/ \| grep -i notif` → **empty**. Laravel's `database` driver needs that table, so **the Database/In-App channel in Group C cannot work until a migration lands.** |
| Database channel | All three notification classes declare `via() => ['mail']`. Nothing routes to `database` today. |
| ~~`notifications.*` permissions~~ | **RESOLVED 2026-10-05** — `PermissionCatalog::NOTIFICATIONS` exists. |
| ~~`notifications` feature flag~~ | **RESOLVED 2026-10-05** — declared in `config/pennant.php`. |
| ~~Any write endpoint~~ | **RESOLVED 2026-10-05 (Group B)** — `update`, `updateChannels`, `sendTestMail` on Web + API, behind `notifications.manage` / `notifications.send_test`. |
| A working inbox UI | The bell is now a real link (`header.blade.php`), but `/notifications/inbox` does not exist — C5. |
| Mail settings persistence | `config/mail.php` is env-driven; no `SystemSetting` keys for SMTP. |

### Consequences — RESOLVED 2026-10-05 (see "Locked decisions")

1. ~~**The feature flag does not exist.**~~ **RESOLVED (D2).** Originally: `config/pennant.php:52-61` documents
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
| **P9-A4** | `NotificationController` stub (`index`/`channels`) + 2 routes. Every variable assembled in the controller, never in Blade. | DONE — the controller grew to five methods when Group B landed the write path; the re-audit row below saying "index/channels only" is stale. |
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
| A4 controller is `index`/`channels` only | **STALE** | Group B added `update`/`updateChannels`/`sendTestMail` to the same controller; the test now asserts the three write methods EXIST. The variable-assembly claim still holds. |
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
| **P9-B7** | **DONE** — 3 `notification_channel_*` keys seeded, plus `NotificationChannelUpdateAction` and `UpdateNotificationChannelsRequest`. `in_app` was seeded **off** until Group C shipped the badge that gives it a meaning, then flipped on by GAP 2. | Same seeder, same store as the SMTP keys. A switch that reports success and controls nothing is the same category error as a `pending` feature flag. The seeder default is now `'true'`; this row's original "off" is the pre-GAP-2 state. |

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

## Group C — In-App Engine, Bell Icon & User Inbox — **DONE** (C4 shipped with D2)

> Dependency: A + B. **Blocked only on the `notifications` table migration** —
> nothing in this group can work without it, since `DatabaseNotification` is the
> whole design.

| ID | Task | Notes |
|---|---|---|
| **P9-C1** | **DONE** — migration generated by `php artisan make:notifications-table` (Laravel's own stub: `uuid`, `type`, `morphs notifiable`, `data`, `read_at`, timestamps). The channels write landed in Group B. | Generated rather than hand-written, so the schema is whatever the framework's `DatabaseNotification` expects. **No per-user preference table** — the switches are global admin state in `SystemSetting`. |
| **P9-C2** | **DONE** — unread badge on the bell, target moved to `/notifications/inbox`, `notifications.view` dropped from `$notificationsVisible` in the same change. Count read in the `layouts.partials.header` composer, not in markup. | **Correction to the plan:** the count could NOT go on the `layouts.app` composer. `@include` shares the parent scope, so a child partial renders before its parent's composer runs — a variable set there is always undefined in the header. The header composer already ran once per page for `$sessionsVisible`, so the count is one more query on a page that has several, not a query per partial. Badge size is a class in `theme.css`, not an inline `style` in shared layout markup. **The count is cached** (`UnreadNotificationCount`), invalidated on `NotificationSent` rather than on the inbox being opened — the second is too late, and a badge nobody has to click to refresh is the whole point of a badge. Owner chose caching over accepting one query per page, which kept fifteen existing query guards intact. |
| **P9-C3** | **DONE** — `NotificationAudience`: an event→permission map, `administratorsFor()`, and one `forEvent()` entry point so the two audiences cannot be resolved by two different rules. | **The permissions are per-action, not `*.manage`.** The first draft used `users.manage` / `roles.manage`, which do not exist — the catalogue splits management into `users.create`, `users.lock`, `roles.update`, `roles.assign_permissions` and so on. A map naming an undeclared permission routes the event to **nobody**, and an empty audience looks exactly like "no one is an admin". `every_administrative_event_names_a_permission_the_catalogue_declares` asserts the map is total against the catalogue. Superadmin is unioned in rather than filtered for: it holds no permission rows, so a permission-only query silently excluded the one role guaranteed to see everything. A single `whereHas`, not `foreach → can()`, because `User::can()` is memoized per instance and this runs over every user. An unclassified event fails toward the NARROW audience. |
| **P9-C4** | Sidebar menu item per admin page, gated on flag then permission | **DONE with D2** — `AppMenuComposer` System group carries one flat entry per admin page, each with flag `notifications` then `notifications.view`, each `active` pattern EXACT (`notifications.*` would highlight both on either page). **The inbox gets NO entry**: it is reached from the bell, and a nav item for a page every user already has a bell for is a third link to the same place. |
| **P9-C5** | **DONE** — `NotificationInboxController` + `NotificationInboxAction` + `pages/notifications/inbox`, 3 routes behind `feature:notifications` and the auth stack with **no permission**. Separate controller from the admin pages on purpose: different gates in one controller means one of them is applied to both. | Every method starts from `$viewer->notifications()` and no method accepts a bare id. This is the module's only user-isolation boundary — the inbox has no permission, so nothing else enforces it — and `markAsRead($id)` written the obvious way (`Notification::findOrFail($id)`) marks **any** user's notification read. `markAllAsRead` is a scoped `update()`, not `get()->markAsRead()`: the collection helper exists in this framework but loads every unread row into memory, and a bulk write over a set the user cannot see the size of should not depend on their inbox size. Two tests assert the isolation directly, and both go red when the scope is removed. |
| **P9-C6** | **DONE** — `NotificationChannel::for()` reads the global switches; all three classes' `via()` delegates to it and each gained a `toArray()`. | `via()` cannot be a hardcoded list — that is what made the channels page a switch that controlled nothing (audit finding 2). `toArray()` is required the moment `database` is a channel; without it Laravel throws rather than writing an empty row. Its shape (`subject` + `lines`) is a contract with the inbox view, and escaping happens there because the string is persisted and re-rendered later. `in_app` is deliberately not consulted: it is a UI concept with no producer, and reading it would be a switch reporting success while controlling nothing. |
**ponytail:** the whole in-app engine is Laravel's `database` driver plus three
model methods. Any custom table or manager re-implements shipped functionality.
The badge is a count on an existing composer, not a component library.

---

## Group D — Security Enforcement, Feature Flags, RBAC & Audit — **DONE** (D1–D6)

> D1/D2/D4 landed together on 2026-10-05 as one change: a `can:` against an
> unseeded permission and a `feature:` against an undeclared slug are both dead
> gates, and the plan's own rule is to ship each with what it guards.

> Dependency: A + B + C. D1/D2/D4 ship early: a permission and a flag nothing
> gates is a row that lies in the UI, so the gate lands with the code it guards.

| ID | Task | Notes |
|---|---|---|
| **P9-D1** | **DONE** — `notifications.view` / `.manage` / `.send_test` in `PermissionCatalog`, `can:notifications.view` on both web routes in the same change. | `.send_test` is a third permission — mail to an arbitrary address is a real abuse vector, not a subset of `manage`. The API routes land with B4. |
| **P9-D6** | **DONE** — `NotificationUiRenderTest` builds four real Spatie roles carrying exactly the named permissions, replacing the `Gate::before` override. | The override granted abilities the application never grants, so a page rendering read-only for every real viewer could still pass. Three things this needed that a role swap is not: `syncRoles` rather than `assignRole` (Spatie's adds, so narrowing a test's permissions would not narrow them); a `PermissionRegistrar` forget per swap; and clearing `User::can()`'s own `canMemo`, which is a **separate** cache from Spatie's — forget the registrar and miss it, and `can('…manage')` keeps saying true for a user who no longer holds it, so the test passes for the wrong reason. Preconditions are now asserted (`assertTrue($user->can('notifications.manage'))` and friends), which the `Gate::before` fixture made true by construction. |
| **P9-D2** | **DONE** — flag declared in `config/pennant.php` (group `Settings`, no `pending` marker), `feature:notifications` on both admin routes, `FeatureFlagSeeder` writes the row, and the sidebar entry is filtered in `AppMenuComposer` (flag first, then permission). **A disabled flag returns 403, not 404** — see Corrections. | All three places, or it is not gated. The middleware already existed; this added the flag, not a class. **Not `@feature()` in Blade** — this repo filters the menu in the composer, so a `@feature()` wrap would be a second mechanism answering a question the composer already answers. |
| **P9-D5** | **DONE** — `feature:notifications` on all three inbox routes, with **no** permission (D-2). | The inbox is part of the module and must vanish with it, but there is nothing to authorize: the routes read the viewer's own rows. Asserted by `a_disabled_flag_hides_the_bell_and_closes_the_inbox`. |
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

## Group E — Tests & Documentation Reconciliation — **DONE**

| ID | Task | Notes |
|---|---|---|
| **P9-E1** | **DONE** — `NotificationSettingsTest` (19 tests): SMTP round-trip into the runtime config, test-mail delivery and failure, RBAC 403 per permission, feature-flag 403, audit rows, validation bounds. | Same shape as `SystemSettingUpdateTest`. Most assertions read the value the TRANSPORT ends up with rather than the row written — a settings row nothing reads is the failure this module exists to end. |
| **P9-E2** | **DONE** — `mail_password` encrypted at rest: the raw column holds no plaintext, decrypts back to the submitted value, and the transport receives the usable password rather than the ciphertext. | Compared by decrypting, not by equality with `encrypt()`: the cipher is randomized per call, so two encryptions of one string differ and an equality check fails on a correct implementation. |
| **P9-E3** | **DONE** — two tests in `NotificationInboxTest`: a user cannot mark another's notification read through the single route, and `markAllAsRead` leaves the other user's rows untouched. | The inbox has no permission, so isolation is the only thing between two users' data. Both were verified load-bearing by replacing the relation scope with a raw `DB::table('notifications')` read — two tests go red, exactly on the isolation assertions. |
| **P9-E4** | **DONE** — four tests: an administrative event reaches only permission holders and an ordinary user receives nothing; superadmin is a recipient despite holding no permission rows; a personal event reaches only its subject; an unclassified event reaches nobody. Plus a totality check that every event in the map names a permission `PermissionCatalog` declares. | The last one exists because the first draft of the map named `users.manage` and `roles.manage`, which do not exist — the events silently routed to an empty audience, which is indistinguishable from "nobody is an admin". |
| **P9-E5** | **DONE** — the bell's href is `/notifications/inbox`, it renders for a plain user holding no `notifications.*` permission, and the unread badge reflects the count. | `NotificationAccessTest::the_header_bell_is_hidden_from_a_user_the_route_refuses` was **inverted** rather than deleted: it asserted the old behaviour (permission-gated, because the target was the configuration page) and now asserts the bell is no longer permission-gated, with a comment naming what replaced it. Keeping the shape means the next person to move the bell has to change a test rather than find a missing one. |
| **P9-E6** | **DONE** — `1111 passed / 4263 assertions` (5 risky pre-existing), Pint clean on every touched file. `progress.md`, `task-tracker.md`, `feature-tracker.md` and this document reconciled; `feature-tracker.md:34` already read `done`. | The counts are dated; `progress.md` carries the live figure. |
| **P9-E7** | **DONE** — channels trimmed to `mail` and `database`; the phantom `mail.default_transport` key replaced by the eight real ones; "transport settings remain config/env only" reversed explicitly; the category table split into the two disjoint audiences with their trigger permissions. | Audit finding 6. The reversal is stated rather than silently deleted — a reader comparing two documents needs to know which one changed and why. |
| **P9-E8** | **DONE** — correct the project conventions skill: `Permission::featureOf()` does not exist (audit finding 5). | Fixed 2026-10-05 in the `laravel-base-project-conventions` skill, along with the `@feature()`-in-Blade claim that does not match this repo's composer. |
| **P9-E9** | **DONE** — regression for the null-default bug: `an_install_without_a_configured_username_still_binds`. | New, not in the original plan. Verified load-bearing by reintroducing the bug and watching the host assertion go red — the exact symptom, where the transport silently kept its `.env` values. A test that cannot fail is not a guard. |
| **P9-E10** | **DONE** — the bell's count is cached, invalidated on delivery AND on mark-read, and a warm cache costs no query per render. | New. The count read live was one query on every authenticated page, which broke fifteen existing query guards; owner chose caching over relaxing them. Invalidating on the inbox open would be too late — a badge is the one number nobody clicks to refresh. |

---

## Open decisions — none

All four original blockers are resolved and recorded as **Locked decisions** at
the top of this document. Two implementation questions remain recorded where
they belong rather than here: the `none`-vs-`null` scheme mapping (answered in
B1) and whether the `in_app` switch earns its place (seeded off, revisited when
a producer exists).

---

## Gaps found by auditing the shipped phase — closed 2026-10-06

A green suite is not a finished phase. These were found by asking what the code
does NOT do, and all three are the same shape: a thing that exists, is tested,
and is never reached.

### GAP 1 — the audience rule had no caller

`NotificationAudience` shipped with four passing tests and **zero call sites**
(`grep -rn NotificationAudience app/` → its own class declaration). The rule was
correct, tested, and never ran. Every dispatch was still
`Notification::send($user, …)` — so "an admin gets notified when a user is
registered", the first example the owner gave, never happened.

Closed by `NotificationAccountStateAction`, called from the four user-state
actions after their transactions commit. `AccountStateChangedNotification` is one
class rather than four, because the events differ in a word.

**The bug the tests then caught.** Deduplicating the two audiences with
`->unique('getKey')` silently dropped everyone but one recipient: `unique()` takes
its string as a data path and never calls the accessor, so every model resolved
to the same value. The subject was still notified, so half the rule kept working
and the failure looked like "the resolver sent nobody" — a place to look that had
no defect in it. A closure, `->unique(fn (User $u) => $u->getKey())`, is the
fix. The two tests that caught it assert the preconditions separately (the role
holds the permission, the resolver includes the operator) so a future dedupe
bug cannot be mistaken for a resolver bug again.

### GAP 2 — a switch that saved and changed nothing

`notification_channel_in_app` was seeded, rendered on the channels page, saved
successfully, and read by nothing — the same category of defect as a `pending`
feature flag. Its stated reason for being off ("no badge yet") had expired: the
badge shipped in Group C.

Closed by reading it in `NotificationChannel::for()` as the gate for `database`,
falling back to `database`'s own row. Seeder default flipped to on so the pair
agrees on a fresh install.

### GAP 3 — the base doc claimed events that do not exist

`docs/base/features/notifications.md` listed "New user registered → `users.manage`",
"Account locked", "Role or permission changed". No notification class existed for
any of them. The doc had replaced one false claim with another, which is worse:
the first was a missing mechanism, the second was a missing mechanism described
so confidently that nobody would look for it.

Replaced with what ships, what does not, and the rule for the events the audience
map declares but nothing dispatches yet. Those are now described as declared and
unreachable rather than as features.

**Regressed after this was written, corrected 2026-10-08.** The replacement
described role changes, feature toggles and setting changes as "declared and
unreachable" — all three dispatch (`RoleAssignAction`,
`FeatureToggleAction`, `SystemSettingsUpdateAction`), and the three classes that
shipped with them were missing from the table entirely. The only genuinely
undispatched mapped events are `permission.changed` and `user.deleted`. The same
failure GAP 3 describes, committed a second time by a doc that had already learned
it: describing an absent mechanism confidently is worse than listing nothing.

---

## Audit of the shipped phase — 2026-10-08, findings closed

A second audit, run against Groups A–E as they stood rather than against this
document's own tables. Method: every claim re-checked in the code; three
failures by the document itself were found and are corrected above (A4, B7, E6).

The architecture held up. What failed were seven things the tables do not
describe, and they share a shape: each is a decision that was correct inside the
module and wrong at its boundary — where another module could reach it, where the
queue could reach it, or where time could reach it.

| # | Finding | Closed by |
|---|---|---|
| **1** | **The SMTP credential left through the settings module.** `mail_password` is a Phase 9 key in Phase 8's store, and `SystemSetting::getAll()` had no redaction — so `GET /api/v1/settings` (`settings.view`), `PUT /api/v1/settings`, and the settings page's read-only table (it prints every key) all handed out the ciphertext. `settings.view` is a much wider gate than the `notifications.view` the credential was built for, and Group A's `$hasPassword` finding was scoped to the notifications pages only. | `SECRET_KEYS` on the model; `getAll()` filters them. `getString()` is unchanged, so `bindMailConfig()` still reads the credential. Asserted on all three surfaces by `SettingsPentestTest::the_smtp_credential_never_leaves_the_settings_module`. |
| **2** | **Queued notifications dispatched inside a transaction.** `UserCreateAction`, `UserUpdateAction` and `UserRequestEmailChangeAction` all called `Notification::send` within `DB::transaction`, every class implements `ShouldQueue`, and every queue connection runs `after_commit => false`. A worker could send before commit, and a rollback still delivered a working temporary password, a verification link for a token the database never stored, and an email-change link that could never be used. The phase's own "dispatch after commit" rule held at the four state-change call sites and nowhere else. | Two things, because one of them is not enough. The three actions send after their transaction closes — which covers the case where the action owns the transaction. And every notification class declares `public $afterCommit = true`, which is what covers the case where something ABOVE it owns one: `SendQueuedNotifications` reads that property and the framework defers the job to the outermost commit. Moving the call out is not sufficient on its own, and I only learned that by writing the rollback test that disproved it. |
| **3** | **The badge and the list under it could disagree.** `UnreadNotificationCount::forget()` cleared the count key only. The `recent()` key was never forgotten, so the badge updated instantly while the dropdown kept showing the previous five, and a row read as unread next to a count that had already dropped. | `forgetAllFor()` clears both; the `NotificationSent` listener and both mark-read paths call it. Two tests, both asserted through rendered HTML. |
| **4** | **The `notifications` table grew without a ceiling.** Nothing deleted from it. The inbox paginates, which hides this from every screen that reads the table while it keeps filling. | Daily scheduled sweep `notification-retention`: read notifications older than 90 days. Unread rows are kept — deleting one removes a badge the user was never shown. A direct `DELETE`, because `model:prune` needs a `Prunable` model and an override of `Notifiable::notifications()`, the relation every delivery, read and count goes through. |
| **5** | **Send-test-mail had no rate limit.** `notifications.send_test` is already a third permission, but a permission answers "may this person" and not "how often". Every other write route in `routes/web.php` carries a named limiter; this one did not. | `throttle:send-test-mail` on web and API, 5/minute keyed on operator **and** recipient, so spraying addresses does not buy a fresh budget. |
| **6** | **`app/Models/Notification.php` was dead code.** No import anywhere in `app/`, `tests/`, `database/`, `routes/`. Laravel resolves `Illuminate\Notifications\DatabaseNotification`; the class was also inconsistent with its own table (`HasUlids` on a uuid-keyed table). | Deleted. The pruning decision in #4 is why it is not coming back in a corrected form. |
| **7** | **The global mail switch could break four flows.** `NotificationChannel::for()` applied the switch to everything, and for verification, email-change confirmation, account state changes and the temporary password the mail is not one channel among several — it is the only delivery. A locked account cannot open the inbox that would otherwise carry the news, because `account.state` refuses the login first. | `NotificationChannel::for(essential: true)`. The in-app switch is not bypassed. The channels page states the exception rather than letting a switch imply an effect it does not have. The contrast is asserted: an administrative notice follows the switch, an account-state notice does not. |

**What the audit did not find.** No notification dispatches inside a transaction
any more; every class that can reach `database` has a `toArray()`; the inbox is
scoped at every entry point; no credential reaches a view, a response or a log
line; the audience map is total against `PermissionCatalog` and fails toward the
narrow audience; the flag is written by the seeder, so no declared-and-dead gate
is left behind.

**Open, recorded not fixed** — none worth a change on their own:

- The bell dropdown renders `bi-check2-circle` for an **unread** row and `bi-check`
  for a read one (`notification-dropdown.blade.php:41`). The markers are inverted.
- `SystemSettingSeeder` seeds `mail_mailer` to `config('mail.default')`, which is
  `log` in `.env.example`, while the form's placeholder reads `smtp`. A fresh
  install therefore shows a transport in force that differs from the hint.
- `mail_from_address` is `nullable` while `mail_host` and `mail_port` are
  `required`. Clearing the from-address is accepted; clearing the host is not.
- The audit-event inventory is not written down anywhere. `mail_setting.updated`
  and `test_mail.sent`/`test_mail.failed` are in D-3; `notification_channels.updated`
  is not in this document, and the last two are written outside a transaction
  (`test_mail.*` has nothing to commit — the probe is not a change to anything).
- No notification class declares `$tries`, `$timeout` or `$backoff`. The
  framework defaults are adequate until a permanently undeliverable address
  matters more than the retries cost.

---

## Found while closing this phase — not fixed here

**`docs/planning/implementation-roadmap.md` has a broken table.** Rows 9–11 read
`|​| 2 | Database foundation | P0 | DONE |`, `|| 3 | …` and `| 4 | User lifecycle
& user management | DONE |` — a doubled leading pipe and a short row missing its
priority column. Verified present at HEAD, so it predates this phase and is not
something Group E introduced. The table also lists phases 5–8 as `PLANNED` while
`progress.md` records them `DONE`.

Phase 9's own row was corrected to `DONE` and its section given real content; the
structural damage and the 5–8 drift are left alone deliberately. A table with
malformed rows needs a pass over every row to be trustworthy, and that is a
separate job from closing a notifications phase — doing it here would have meant
editing five phases' worth of status on the strength of one file's claims.

**`progress.md` is the accurate status document.** When the two disagree, it is
this one that was checked against the code and the test suite.

---

## Audit of the shipped copy — 2026-10-08, findings closed

A separate pass on the wording rather than the code, because the copy was
readable-looking and wrong in the same way on all seven classes. Researched
against notification-copy guidance (OneSignal, Braze, Appcues, Microsoft Fluent 2
content design) and then applied to every class that reaches the inbox.

| # | Defect | Example as it shipped | Now |
|---|---|---|---|
| **1** | **The body repeated the subject.** | Subject "Role was updated", only line "Role was updated by Ana Silva." | The lines carry the actor, the target, and the next step — nothing the subject already said |
| **2** | **Broken sentences from string concatenation.** | `sprintf('Your roles%s were changed.', $by)` → **"Your roles by Ana Silva were changed."** | The actor is its own line, or lives in the subject when the subject has room |
| **3** | **Subjects that do not identify themselves.** | "Account was locked" — in the bell, with no body and a truncation | "Locked account: jane", kind first, target after a colon |
| **4** | **Copy addressed to the wrong surface.** | An inbox row reading "Click the verification link sent to your email" | The row says the link was emailed, because the inbox has no link in it |
| **5** | **Two rows, two claims, one signup.** | "Your account registration is complete" beside "Verify your email to activate your account" | One carries the status, one carries the action |
| **6** | **Passive, present-perfect, Title Case.** | "Your account has been created", "# Your Account Has Been Created" | Present tense, active, sentence case, and no terminal punctuation on a list title |
| **7** | **Debug strings in a reader's face.** | "2 key(s)", "Target: billing_v2", "No credentials are included in this message." | "3 settings saved.", the target in the subject, and the sentence about the mail deleted — it told a reader nothing they could use |

`NEXT_STEPS` and `ACTIONS` were added so the body answers "what do I do", which
none of them did. The two `detail` call sites that passed fragments rather than
targets were corrected (`user.updated` passed "Profile updated", which produced
"Profile updated: Profile updated"; `setting.changed` passed "2 key(s)").

**`NotificationCopyTest`** pins what a machine can judge, across all seven
classes and both audiences: no line may contain everything the subject already
says, subjects carry no terminal punctuation while lines are full sentences,
subjects fit a truncating bell, and no line is a fragment or carries markup. Two
rules I wrote first and then removed, because they were wrong:

- **"The subject must end in a full stop."** The opposite is the convention for a
  list title, and the codebase's own rows never had one.
- **"Every word of the subject must be lowercase."** Fails on "Ana Silva changed
  the roles of jane" — a proper noun is capitalised, and no mechanical check
  separates that from Title Case. Kept as a documented convention in the template
  constants rather than a test that would get switched off for being noisy.

Mutation-checked: restoring the old "Role was updated by Ana Silva." line turns
the redundancy test red.

One finding is a harness trap worth remembering, because it looks exactly like a
copy bug: `isSelf()` compares `getKey()`, and two unsaved factory models both
have a null key, so every operator row rendered as the personal copy and the
administrative half of the test proved nothing. The test now pins ids.

---

## Verification — performance (2026-10-08)

`tests/Feature/Notification/NotificationBenchmarkTest.php`, 8 tests, numbers in
`/tmp/phase9_notifications_benchmark.json`. Same shape as Phase 8's
`SettingsBenchmarkTest`: warm-up outside the measured window, query log + wall
clock + memory, results to /tmp for a before/after diff.

Timings are measured and **not** asserted — a latency assertion fails a suite on a
loaded box for reasons unrelated to the code. The three query-count assertions at
the end of the file are a different matter: exact integers that depend on the
code, not the machine, so they are allowed to fail the run.

### Results (SQLite `:memory:` — the query counts are the portable part)

| Path | Queries | Latency |
|---|---|---|
| **Bell: unread count, warm** (every authenticated page) | **0** | 0.10 ms |
| Bell: unread count, cold (after a deploy or any cache bust) | 1 | 0.59 ms |
| Bell: recent list, warm | 0 | 0.15 ms |
| Bell: invalidate + refill (the price of one delivery) | 2 | 1.53 ms |
| Inbox: 30 rows out of 2,120 | 3 | 2.40 ms |
| Inbox: mark one read / mark all read | 1 / 1 | 0.40 / 0.66 ms |
| Audience resolution: 4 holders, 65 users | 2 | 2.85 ms |
| Audience resolution: unmapped event (narrow fallback) | 0 | 0.00 ms |
| Role change end to end (assign + audit + resolve) | 8 | 7.16 ms |
| Role change: no-op save | 8 | 7.01 ms |
| Retention sweep: scan 5,100 rows, none expired | 1 | 0.59 ms |
| Retention sweep: delete 4,000 expired rows | 1 | 0.24 ms |

### What the numbers say

**The composer is the only Phase 9 code multiplied by the whole user base.** It
runs on every authenticated page render for every user, whether or not they have
ever received a notification — so warm at 0 queries is the number that decides
whether the module is affordable, and it is the one assertion in this file. This
is what E10's cache bought, and it is also why fifteen query guards elsewhere in
the suite are still intact rather than relaxed. The cold figure is 1 query: the
first render after a deploy, once.

**Audience resolution is 2 queries at any number of administrators**, measured at
1 and at 21. That is the `whereHas` shape from C3 doing its job — a `foreach`
with `can()` in it would also pass the notification tests and fail this one,
because `User::can()` memoises per instance.

**The inbox does not grow with the inbox.** 15 notifications and 1,015 cost the
same, which is the pagination doing its job; nothing reads the table unbounded.

**The role-change no-op save costs the same 8 queries as a real change**, and
that is correct: the diff guard saves *notifications*, not queries. It is a PHP
array comparison on two short arrays. Worth stating because "the guard made it
cheap" would be the natural misreading.

### Two findings from the benchmark

**1. A directly-granted permission is invisible to the audience.**
`NotificationAudience::administratorsFor()` reaches holders through
`roles.permissions`, so a permission attached to a person rather than to a role is
never resolved:

```
User::can(users.lock) = true
in audience            = NO
```

Not live today — Phase 6 has no UI for attaching a permission to a person, so
every holder in the application gets it through a role. It is a silent hole the
moment one code path grants directly: the operator qualifies for the action and
is never told it happened. One clause fixes it (`orWhereHas('permissions')`) and
it is **not** applied here, because it changes who receives notifications and
that is an owner's decision. Recorded in "Open item" below.

**2. The inbox page holds the module's last uncached notification read.**
`NotificationInboxAction::inbox()` counts unread rows live — the third of the
three queries above — while the header reads the cached count. One query per
visit, no defect, but it is the one place where a person can see two different
unread numbers for the same inbox on two different screens if the cache is stale
between them.

### Two mistakes this benchmark made first

Recorded because both produced numbers that looked fine:

- `Cache::flush()` was placed **outside** the measured closure. The helper warms
  up before starting the clock, so "cold" measured a second warm render — and
  reported **cold: 0 queries**, which would have been the most convincing lie in
  the file. The flush now lives inside.
- Fixture users were given permissions with `givePermissionTo()`, which does not
  go through a role, so the audience resolver found nobody and two benchmarks
  quietly measured an empty result. The fixture grants through a role now, which
  is also how the application grants.

Full suite: **1290 passed / 4979 assertions**.

---

## Open items — both closed

**A directly-granted permission resolved to no notification audience.** The
audience map joined through `roles.permissions`; a permission held on the user
record itself passes `can()` and was skipped by the resolver, so the operator
qualified to undo an action was never told it happened. Latent, not live: Phase 6
grants through roles only, and there is no UI that attaches one to a person. The
resolver now reads both, because "holds the permission" has to keep meaning what
the Gate says it means. `NotificationInboxTest::test_a_directly_granted_permission_holder_is_an_audience_member`
— verified load-bearing by deleting the second branch.

**The inbox page held the module's last uncached notification read.** It counted
unread rows live while the bell read a cache invalidated on delivery, so a row
written between the two reads left one screen saying 3 and the other saying 4.
It reads the same cached count now, which is also one query fewer per visit
(`NotificationInboxTest::test_the_inbox_and_the_bell_agree_on_the_unread_count`
— verified load-bearing by restoring the live count).

---

## Out of scope (deferred, stated not forgotten)

- Email template editing UI (the brief's "template email" has no task; `A1` only
  configures SMTP).
- Horizon / queue dashboard for mail failures.
- Bounce, complaint and unsubscribe handling.
- Queue `queue:restart` automation after a transport change — documented as the
  ceiling in Group B, deliberately not automated.
