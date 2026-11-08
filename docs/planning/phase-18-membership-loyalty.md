# Phase 18 — General Membership & Loyalty System

> Date: 2026-11-05 | Status: **Groups A and B DONE (2026-11-06), Group C in REVIEW
> (2026-11-06)** — A shipped 2026-11-05 (views + `member_loyalty` flag; voucher rows moved
> to Phase 20 — owner decision the same day); B closed on the re-run exit gate
> (`tests/Feature/Member` + `tests/Feature/Api/V1/Member` + `tests/Feature/CrossCutting`
> 192 passed / 1168 assertions, Pint PASS 561 files, PHPStan `[OK] No errors`), with
> `BroadcastMemberExpiryRequest` rolled into **P18-C2** — the owner moved it to the group
> that ships the broadcast route it guards, and C2 shipped the route, then dropped the request:
> `rules()` was empty and `can:members.notify` on the route is the gate (body-less trigger routes
> take the plain no-request shape, like `activate`/`cancel`). **C: `MemberSubscriptionExpirySweepJob`
> (C1), `SendMemberExpiryNotificationJob` + `MemberExpiryWarningNotification` + the broadcast
> route on both channels (C2), the eighth `schedule:list` entry at 00:30 (C3) — proven by
> `MemberExpirySweepTest` (6) + `MemberExpiryNotificationTest` (9), full `tests/Feature/Member`
> 113 passed / 531 assertions, `JobConventionTest` + `ChannelParityTest` + `NotificationCopyTest`
> green, `tests/Arch` 620 passed, Pint PASS 570 files, PHPStan `[OK] No errors`; uncommitted,
> awaiting owner REVIEW.** D1/D2 stay as the B slices recorded them; D3 and E1–E2 not built. |
> Branch when scheduled: `feature/phase-18-membership-loyalty`
> Purpose: give the base project a membership domain — dynamic tiers, a subscription
> duration/expiry engine, member statistics, automated expiry sweeps with bulk
> notification — built entirely on the primitives Phases
> 1–17 already ship (RBAC, Pennant, `SystemSetting`, notifications + queues, audit, API v1).
> **Vouchers moved out to Phase 20 (owner decision 2026-11-05): they are their own
> domain — own flag, own menu, validated at payment time — not part of membership.**
> Dependency chain: **A → B → C → D → E** (strictly sequential — see § 2.1).
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/` (`AGENTS.md` § Before any task: docs win; a change to a
> design decision goes through an ADR in `docs/planning/decisions.md`).

---

## 1. Scope

Eighteen tasks in five groups (the brief's nineteen: A4 split per D-4, and the voucher
tasks moved to Phase 20 — B5 is the pointer row).

> **2026-11-06 owner adjustment** (roadmap resequencing): **Group F — Membership Analytics
> Dashboard** is added (F1–F4, § 5), the delivered member-facing `/dashboard` moves from the
> ID it held to **P18-E3** (D-8), and the general app dashboard leaves this phase to become
> **Phase 19** — `docs/planning/phase-19-general-dashboard.md`. Phase 18 is now titled
> *General Membership & Loyalty System*; POS and payment, previously numbered 19, are
> **Phase 20**. Twenty-three tasks in six groups.

> **2026-11-05 owner adjustment:** every voucher row in this document (`P18-A2`'s voucher
> view, `P18-B1`'s two voucher tables, `P18-B5`, `P18-B6`'s `VoucherController`,
> `P18-D1`'s `vouchers.manage`, `P18-D3`'s `voucher.claimed`, `P18-E1`'s race test) ships
> in **Phase 20** as the `vouchers` domain. The shipped voucher view already lives at
> `resources/views/pages/vouchers/index.blade.php` under its own flag — see P20-A6.

> **Second 2026-11-05 owner adjustment** (six asks, all shipped on this branch):
> `member_points_expiry_date` + the earn pair + the alert window moved out of code into
> System Setting (`member_expiry_alert_window_days` replaces the `MemberDashboardAction`
> constant, and `MemberDashboardAction` reads it with a 14-day fallback); the three
> Members / Tiers / Dashboard entries now sit under their own **Membership** sidebar label
> instead of Management, still dropping with the `member_loyalty` flag; an **Add new
> member** form posts to `members.store` and creates a `User` + `Member` with the fixed
> `member` role (`App\Support\SystemRole::MEMBER`, no permissions — see O-1); a manual
> **Adjust Points** card posts to `members.points` (see B4; **the card and its web route were
> removed 2026-11-06 by owner decision — a balance move belongs to the Phase 20 checkout, and
> `POST /api/v1/members/{member}/points` stays for programmatic corrections**); and
> `MemberDemoSeeder`
> (10 members, tiers, subscriptions, ledger) is runnable but deliberately **not** wired
> into `DatabaseSeeder` — demo rows must never land in a production seed.
>
> Why the Tiers link was dead before this: `AppMenuComposer::visible()` filters
> `Route::has()` **first**, so an entry naming `members.tier` (a route that did not exist)
> was dropped silently — no error, no log, no link. The `'permission' => 'tiers.view'` on
> the same entry pointed at a permission `PermissionCatalog` never had. Not a missing
> controller: the controller and the route existed as `MemberTierController` +
> `members.tiers`; the entry pointed at the wrong name. `MemberWebTest` now asserts all
> three labels, so a silently-dropped entry cannot pass again.

| Group | Subject | Nature |
|---|---|---|
| A | Blade interfaces, feature-flag declaration, UI render gate | **Build** (UI only) |
| B | Migrations, models, Form Requests, Actions, web + API controllers | **Build** |
| C | Expiry sweep job, queued bulk notification, scheduler entry | **Build** |
| D | Route authorization, flag enforcement, audit events | **Enforce + verify** |
| E | Lifecycle/race/sweep tests, full-suite run, tracker reconciliation | **Verify + close** |

**Not in scope:**

- **Spending anything, and vouchers entirely.** No checkout, no order line, no tax, no
  receipt, no printing — that is Phase 20. And since 2026-11-05 the voucher is *its own*
  domain in Phase 20 (validated at payment time), not a membership deliverable: this
  phase models the tier, the subscription and the points balance only.
- A payment gateway, an idempotent webhook, or the race-condition shield around money
  movement (Phase 20).
- A WhatsApp / third-party notification channel — see **D-3**.
- i18n of any new string (`AGENTS.md` § *Traps already paid for*: no `lang/` source
  exists; every string in this phase is literal English by design).
- A new Composer or npm package. None is proposed. If one becomes necessary (a whatsapp
  driver, a barcode/PDF library), it stops here and goes through `docs/planning/changelog.md`
  + an ADR first (`AGENTS.md`).
- Multi-tenancy, tier promotion rules based on spend history, referral schemes, or a
  points catalogue/store-redemption UI. A tier is assigned, it is not earned.

### Why this phase is not new infrastructure

Every capability the brief asks for already has an owner in this codebase, and using it is
the whole design constraint:

| Need | Existing owner | What Phase 18 adds |
|---|---|---|
| "Roles assigned/revoked when a subscription activates" | Spatie `Role` + `App\Actions\V1\Role\*` + `PermissionCatalog` | a `member_tiers.role_name` column and a `syncRoles` call inside a Member Action |
| "Kill switch per module" | `config/pennant.php` + `App\Support\FeatureCatalog` + `EnsureFeatureIsEnabled` + `FeatureFlagSeeder` | one declared slug (`member_loyalty`) |
| "Bulk notification with chunking" | `App\Support\NotificationChannel::for()` + queued notifications + `QueueName` | one notification class + one job |
| "Scheduled daily sweep" | `routes/console.php` (6 entries today) + `JobConventionTest` | a seventh entry + one job |
| "Audit every mutation" | `App\Models\Concerns\Auditable::audit()` inside the transaction | four event names |
| "API surface" | `routes/api.php`, `app/Http/Resources/Api/V1`, Scramble, `ApiDocumentationTest` | resources + controllers |

A Phase 18 that invents a second permission system, a second mail path, or a second audit
writer is wrong regardless of working, per `docs/base/architecture/application-components.md`.

---

## 2. Specifications (owner decisions)

1. **Sequential execution.** A → B → C → D → E. A group's exit gate (§ 6) is green before
   the next group's first commit. Within a group the listed order is the order.
2. **Group A is UI-only.** No migration, model, Action, request or controller in A — with
   one carve-out forced by the framework, recorded as **D-4**.
3. **Tier is data, not a hardcoded role.** `member_tiers` is a domain entity; a tier
   *references* a Spatie role, it does not replace the RBAC layer. Deleting a tier never
   deletes a role.
4. **Action-first.** Every state mutation is in `app/Actions/V1/Member/`, inside
   `DB::transaction`, with the audit write **inside** that transaction
   (`AGENTS.md`: audits are written by the Action layer, never by an observer). Jobs and
   events dispatch **after** commit.
5. ~~**Voucher claim is atomic.**~~ **Moved to Phase 20 (P20-B6)** — the voucher is its
   own domain; the atomicity rules (one transaction, `lockForUpdate()` before the quota
   read, unique `(voucher_id, member_id)` backstop) ship there unchanged.
6. **Bulk notification is queued and chunked**, never a `foreach` inside the request.
7. **Both channels.** A capability that exists on web exists on the API in the same group.
   `AGENTS.md`: *a capability is not delivered by the UI that shows it* — the Phase 9/10
   failure mode. Diffed by capability, not by URI (the Phase 12 method).
8. **Thin controllers, FormRequest-only validation.** `$request->validated()`, never an
   inline `$request->validate()`; authorization on the **route** (`can:` + `feature:`),
   never in a constructor.
9. **No inline FQCN anywhere** (`tests/Arch/NoInlineFqnTest.php`, one data set per file).
10. **Cache primitives only.** Member dashboard counters go through `Cache::remember` as
    ints/strings; a Collection or model in the cache returns `__PHP_Incomplete_Class` on a
    hit (`AGENTS.md` § *Traps already paid for*).
11. **New code does not grow the PHPStan ledger.** Baseline is 310 entries at level 8; a
    new entry is a finding to fix now, not to bank.

---

## 3. Deviations from the brief (measured against the shipped code)

The brief was written against an assumption of the repository. Six points do not hold
here. Each is recorded rather than silently resolved, and each changes a task below.

| # | The brief said | The repository does | Consequence |
|---|---|---|---|
| **D-1** | P18-D2: flag disabled → **HTTP 404** | **403.** `EnsureFeatureIsEnabled` carries a docblock that settles exactly this question ("Why 403, not 404", 2026-10-01, reversing an earlier 404), Phase 12 recorded it as conflict D-1 — "flags return 403, not 404 … not to be *fixed* into a bug" — and 62 tests across web + API pin it | P18-D2 asserts **403**. Changing it is an ADR, not a phase task |
| **D-2** | P18-B1: a `members` table | Spatie's `HasRoles` is wired on `App\Models\User`; `Gate`, `can:`, `syncRoles`, the session guard and `PermissionCatalog` all resolve against `User`. A parallel `members` identity would either be unable to hold a role at all, or become a second login-capable account system | `members` is a **loyalty profile keyed to the existing user**: `user_id` FK, unique. Tier roles sync onto the **`User`**, so `can:` keeps working unchanged. No new authn path |
| **D-3** | P18-C2: chunked **WhatsApp**/Email alerts | Two channels exist: `mail` and `database` (`NotificationChannel::for()`, switches `notification_channel_mail` / `_database`). No WhatsApp driver, no broadcast channel, no credentials | C2 ships **mail + in-app**, reading the same global switches. A WhatsApp driver is an open owner decision (§ 8): new package → `changelog.md` + ADR + `SystemSetting` credentials, exactly the SMTP shape |
| **D-4** | P18-A4: sidebar entries wired in Group A | `AppMenuComposer` deliberately rejects `#` placeholders — "the menu cannot advertise a screen that was never shipped" — and a Blade reference to `route('members.index')` throws while that route name does not exist. Group A may not create controllers | A4 splits: **A4a** (flag declaration in `config/pennant.php`, `FeatureFlagSeeder` row, `@feature`/`@can` guards inside the views) stays in A; **A4b** (the route group + the two sidebar entries) ships with **B6**, the first commit where the link target exists. The sequential rule is not bent; the task is re-cut. **Amended 2026-11-05**: the owner asked for the member screens in the sidebar, and `AppMenuComposer` drops an entry whose route does not resolve — so A4b's read half could not ship without B1 and the four GET routes. `P18-B1` and the read half of `P18-B6` (four read Actions, three thin web controllers, `members.view` on every route, three sidebar entries — the brief's two plus Tiers, which the A2 screen needs a door of its own) shipped together as a read-only slice. D-4's contract still holds: no member Blade file calls `route()` — the Actions hand every URL over as a variable, and a **null write URL removes the affordance instead of rendering a button that 404s** (`MemberUiRenderTest::test_null_write_urls_take_away_every_write_affordance`) |
| **D-5** | P18-E1: `VoucherRaceConditionTest` proves the lock | `phpunit.xml` runs `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. SQLite takes no `SELECT … FOR UPDATE`: the statement parses and does nothing | Applies to every `lockForUpdate()` here, including the points ledger lock in B4: the test proves what SQLite *can* prove — balance arithmetic, a second redemption refused — and the row lock itself is recorded as **deferred runtime evidence** needing a two-session MySQL probe, the same way Phase 14 left the restore round trip open. The voucher race test moved with the voucher domain to **P20-E1** |
| **D-6** | P18-D1: `members.view`, `members.manage`, `vouchers.manage` | A bulk POST must map to its own permission (`AuthorizesBulkAction`, P6-C17); Phase 12 Group D found exactly this gap on `features/bulk-action` — a route comment claiming a permission the route never checked. A3's "notify all expiring members" **is** a bulk action | Three permissions: `members.view`, `members.manage`, `members.notify`. `members.notify` is the broadcast's own gate; `vouchers.manage` moved to Phase 20 (P20-D1) with its domain. Coarse `manage` over create/update/delete stays, matching `settings.manage`/`storage.manage` rather than the 11-verb `users.*` set. **Amended 2026-11-05 by the owner for removal only**: `members.delete`, `members.restore` and `members.force_delete` join the catalogue, because coarse `manage` meant an operator who could edit a tier's price could also destroy a member's points history. Create/update/points stay coarse; `members.notify` is unchanged. Tiers share the same six (one module, one permission set), so `BulkMemberTierRequest::bulkEntityPrefix()` answers `members`, not `tiers`. **Amended 2026-11-06 by P18-E2**: coarse `members.manage` is gone entirely (the granular `users.*`-shaped suffix set replaced it slice by slice), and tiers are their own **resource** — `member_tiers.{view,create,update,delete,restore,force_delete,activate,deactivate}` — because `PermissionCatalogTest` gates every name on one dot and `members.tiers.view` has two. `bulkEntityPrefix()` therefore answers `member_tiers`, and `FEATURE_BY_RESOURCE` maps both prefixes to `member_loyalty` |
| **D-7** | (unstated) a `Member` domain | `docs/base/architecture/naming-conventions.md` holds a domains table (9 rows as of P16-B2) and `ActionNamingConventionTest` is its owner | B3 adds `Member` to that table in the same commit as the first Action, not at closure |
| **D-8** | Group F opens at **P18-F1** | `P18-F1` was already taken on 2026-11-06 by the delivered member-facing `/dashboard` (`MemberSelfDashboardAction` + `pages/members/my-dashboard.blade.php`, owner request outside this breakdown, 5 tests green) | The delivered row is renumbered **P18-E3** — it shipped with Group E's tests and closes Group E's verification scope. Group F keeps the brief's F1–F4 numbering. Two rows cannot share an ID: the tracker is the ledger and a flipped row must name exactly one piece of work |
| **D-9** | P18-F1: "Age Group / Demographic Breakdown (derived from Date of Birth and Address)" | `users` has **20 columns and none of them is a date of birth, an address, or an avatar** (measured with `php artisan db:table users`); `members` adds `member_number`, `points_balance`, `is_active`, `joined_at`, `notes`. Nothing in the schema carries a birthday | The demographic widget is **not buildable from data that exists**. Member **tenure** is (from `members.joined_at`), so F1 ships tenure distribution; age group is cut to open decision **O-5** — it needs a profile migration (`date_of_birth`, `address`), which is a data-collection and privacy question, not a chart. The same gap blocks Phase 19's "Profile Completeness Score" |
| **D-10** | P18-F2: `GetMembershipDashboardStatsAction` | `docs/base/architecture/naming-conventions.md` § Action Naming: `{Domain}{Operation}Action`, domain prefix first — "`UserCreateAction` right, `CreateUserAction` wrong", enforced by `ActionNamingConventionTest` | The class ships as **`MemberAnalyticsStatsAction`** in `app/Actions/V1/Member/`. A verb-first name is red on that gate the day it is committed |
| **D-11** | P18-F2: "event-driven cache invalidation to load dashboard stats under 100ms" | § 2 rule 10 already owns this: member dashboard counters go through `Cache::remember` **as primitives** — and `AGENTS.md` § Traps records that a non-primitive (Collection / model / Carbon) cached through the Redis store comes back `__PHP_Incomplete_Class` on a hit | Reuse what is there: `Cache::remember` of a primitive array, tagged-by-key invalidation on the member/tier events that already fire (`member.subscribed`, `member.points_adjusted`, `member.expired`). A new cache layer or a new invalidation bus is not needed. The 100 ms figure becomes a **measured** number in F4's own assertion, not a promise written before the query exists. **Corrected on delivery (P18-F2, 2026-11-06):** `member.subscribed` / `member.points_adjusted` / `member.expired` are the **audit-log action names**, not `Illuminate` events — `grep -rn "Event::dispatch" app/Actions/V1/Member` returns nothing, so there is no event to subscribe to. Invalidation is one `MemberAnalyticsObserver` bound to `Member` + `MemberSubscription` in `AppServiceProvider` (the house `UserObserver` shape: `cacheKeys()` + static `bustCache()` inside `DB::afterCommit`) — one registration instead of eighteen Action call sites, and it cannot miss a write an Action forgot to announce. `users.last_activity_at` is written with `updateQuietly()` at login, which bypasses observers, so DAU/MAU freshness is bounded by the 300 s TTL |
| **D-12** | P18-F1 as a new screen and P18-F3 as a new controller endpoint | Both already ship: `pages/members/dashboard.blade.php` (A3), `MemberDashboardController::{index,broadcastExpiry}`, `membership.dashboard` + `membership.broadcast-expiry` routes, and the Expiring-Soon table **with** its `members.notify` bulk-broadcast confirm modal (C2) | F1 and F3 are **extensions of the shipped screen**, not new files. F1's "Expiring Soon Table with Bulk Notification trigger modal" and F4's broadcast path are delivered work — rebuilding them would duplicate `MemberDashboardAction::run()`'s `$expiring` and `MemberExpiryBroadcastAction`. What is genuinely new in F is: growth-vs-expiration series, renewal rate, DAU/MAU ratio, tenure histogram, at-risk list, and the timeframe filter |
| **D-13** | (implied) DAU/MAU and "inactive >30 days" need activity history | No daily-active table exists, but `users.last_activity_at` does | Both are computable **from that one column** with no new schema: DAU = distinct members with `last_activity_at` inside 24h, MAU inside 30d; at-risk = active subscription **and** `last_activity_at` older than 30 days. The ratio is a *current* snapshot, not a historical series — a trend line would need a daily rollup the phase does not have, so F1 renders the number, not a chart of it |

---

## 4. Data model

Four tables, all with `softDeletes()` where a row is operator-deletable
(`docs/base/data/soft-delete.md`), all timestamps, all MySQL + SQLite-compatible
(the suite runs SQLite).

| Table | Key columns | Notes |
|---|---|---|
| `member_tiers` | `id`, `name` (unique), `slug` (unique), `role_name` (nullable → `roles.name`), `price` `decimal(12,2)`, `duration_days` `unsignedSmallInteger`, `point_multiplier` `decimal(4,2)` default 1.00, `is_active` `boolean`, `sort_order` | `role_name` is a **reference**, not a foreign key to a table Spatie owns per-guard; validated in `StoreMemberTierRequest` against the real role list for the `web` guard (`RoleLookup::guard()`) |
| `members` | `id`, `user_id` (FK → `users.id`, **unique**), `member_number` (unique, string), `points_balance` `unsignedBigInteger` default 0, `joined_at` `timestamp`, `notes` `text` nullable | One profile per account (D-2). No second identity |
| `member_subscriptions` | `id`, `member_id` FK, `member_tier_id` FK, `status` enum(`active`,`expired`,`cancelled`), `starts_at`, `expires_at`, `role_name` (the role actually applied — kept per-row so a later tier edit cannot revoke the wrong role) | `role_name` copied at activation is the fix for "tier renamed after the fact leaves a role orphaned". Index `(status, expires_at)` — the sweep's only query shape |
| `member_point_histories` | `id`, `member_id` FK, `delta` `bigInteger` (signed), `balance_after` `unsignedBigInteger`, `reason` `string`, `actor_id` nullable FK → `users`, `reference_type`/`reference_id` nullable morph | Ledger, never edited: a correction is a new row. `balance_after` makes the ledger auditable without recomputation |

The `vouchers` and `voucher_usages` tables moved to Phase 20 (§ 4 of
`phase-20-pos-and-payment.md`) with the rest of the voucher domain.

Migrations are named `2026_11_XX_000000_create_member_*` in dependency order, each with a
working `down()` (`mysql` sessions and the Phase 14 migration-`down()` lesson).

---

## 5. Tasks

### Group A — Views, UI, Feature Flag Declaration & Render Gate (UI only)

Exit gate: every new Blade renders from a fixture with **zero** DB touches, Pint clean,
no route touched.

#### P18-A1 — Member management & form views
- **Task**: `resources/views/pages/members/index.blade.php`, `create.blade.php`,
  `show.blade.php`. Two-column layout per the design system: `col-lg-8` table/form,
  `col-lg-4` summary + tier-badge card. Card standard `card border-0 shadow-sm mb-4`,
  footer `bg-body-tertiary border-top py-3` (`docs/base/ui/design-system.md`).
  Index: searchable, sortable table wrapped in `.table-responsive`, pagination via the
  house `sortable-th` + Bootstrap paginator, bulk-action trigger attributes as the
  users/roles indexes do. Error text is **not** placed inside an `input-group`
  (Bootstrap sibling-selector trap, `AGENTS.md`).
- **Behaviour**: every value arrives from the view model; no arithmetic, no queries, no
  `@php` blocks in Blade. Every inline script is `@nonce`d.
- **Acceptance**: the three pages render for fixture data in every state (active tier /
  no tier / expired / cancelled); a validation error lands on the field that caused it;
  `show` renders the points ledger and the subscription history from passed collections.
- **Tests**: `MemberUiRenderTest` (A5).
- **Docs**: `docs/base/ui/ui-adminlte-setup.md` only if a new component appears (none expected).

#### P18-A2 — Tier configuration view
- **Task**: `resources/views/pages/members/tiers.blade.php`. Tier rows edit name, slug,
  price, duration in days, point multiplier, assigned role (select built from the
  existing roles, not free text) and active state.
- **Moved**: the voucher view (`pages/members/vouchers.blade.php`) became `resources/views/pages/vouchers/index.blade.php`
  under the `vouchers` flag on 2026-11-05 — its own domain, its own menu. See **P20-A6**.
- **Acceptance**: the role select offers exactly the roles the viewer may assign; an
  inactive tier renders its state distinctly; the table is `.table-responsive`.
- **Tests**: `MemberUiRenderTest` (the voucher render gate is `VoucherUiRenderTest`, P20-A6).

#### P18-A3 — Member dashboard & expiry alert widget
- **Task**: `resources/views/pages/members/dashboard.blade.php`: counters (members by
  tier, active vs expiring-N-days, points issued), the warning widget listing
  subscriptions inside the alert window, and the bulk-notification trigger modal built
  from the house `confirm-action` component with `data-*` parameters (`bulk-actions.js`
  is shared — parameterise, do not fork).
- **Behaviour**: counters arrive as **primitives** already computed by the read Action.
  The widget renders an empty state rather than a zero that came from Blade.
- **Acceptance**: dashboard renders with zero subscriptions; the modal posts to the
  broadcast route with the action name in `data-*`, not in a hand-built URL.
- **Tests**: `MemberUiRenderTest`, plus the design-system sweeps that already forbid
  `bg-white`/`bg-light`.

#### P18-A4a — Feature-flag declaration and activation
- **Task**: add `member_loyalty` to `config/pennant.php` (`label`, `group`,
  `description`, and the `settings` key list it owns) and a row in
  `database/seeders/FeatureFlagSeeder.php`.
- **Why the seeder is part of "UI"**: the store is `database` and **fail-closed** —
  declaring a slug does not activate it, and an unseeded flag 403s the module for
  everyone including superadmin (`AGENTS.md` § Feature flags). A flag declared without a
  row is a module that does not exist.
- **Acceptance**: `Feature::active('member_loyalty')` is true after `db:seed`; the slug
  appears in `/features` in its own group.
- **Tests**: reuse the existing `FeatureFlag*` suites — they enumerate the catalogue, so a
  declared flag is covered the day it is written (no new flag test file).
- **Docs**: `docs/base/features/feature-flags.md` gains the row (P16-C1 owns that diff).

#### P18-A5 — UI render test suite
- **Task**: `tests/Feature/Member/MemberUiRenderTest.php` in the `FormViewRenderTest`
  shape: `view(...)->render()` against fixture arrays, plus a sweep asserting **no member
  view reaches for the database** (the property `FormViewRenderTest::test_no_form_view_reaches_for_the_database`
  already proves for the auth forms).
- **Acceptance**: the file passes with the application under maintenance; each gate is
  sabotage-verified individually before it is called a gate (the house rule since P16-E1).
- **Note**: rendering by fixture rather than by HTTP is forced by **D-4**, not chosen for
  speed. The HTTP round trip is proven in E1 — its member half arrived early, in
  `MemberWebTest`, with the read-only slice on 2026-11-05.

### Group B — Data, business logic and the tier/subscription engine

Exit gate: migrations migrate up and down on SQLite; every Action covered by a unit test;
`ActionNamingConventionTest` and `NoInlineFqnTest` green; both channels respond.

#### P18-B1 — Migrations, models, factories
- **Task**: the four tables of § 4; `App\Models\{Member,MemberTier,MemberSubscription,MemberPointHistory}`
  each `use Auditable` + `SoftDeletes` where the table says so; factories for every model;
  `User` gains the `member()` has-one (no role logic on the model). `Voucher`/`VoucherUsage`
  ship with the voucher domain in **P20-B6**.
- **Acceptance**: `migrate` then `migrate:rollback` exits 0; a `member` cannot exist
  without a `user`; `member_subscriptions` refuses two `active` rows for one member
  (unique partial shape enforced in the Action, the DB constraint recorded as the
  MySQL-only half — see D-5).
- **Tests**: `MemberModelTest` (relations, casts, the unique `user_id`).

#### P18-B2 — Form Requests
- **Task**: `app/Http/Requests/V1/Member/{StoreMemberRequest,UpdateMemberRequest,StoreMemberTierRequest,UpdateMemberTierRequest,BroadcastMemberExpiryRequest}`
  extending `BaseFormRequest` — the voucher requests ship in **P20-B6** with their domain — each with a real `authorize()` returning the permission
  that performs the change (`members.create` is not in the catalogue — `members.manage`
  is, and `authorize()` names it), and `rules()` that call `$request->validated()` at the
  controller. **Amended 2026-11-06 (owner): `BroadcastMemberExpiryRequest` moved to
  P18-C2 with the broadcast route it guards — and was deleted there the same day: an empty
  `rules()` behind a route that already names `members.notify` is the dead-weight shape the
  conventions call out for body-less state routes; the rest of Group B is DONE.**
- **Acceptance**: every numeric bound rejects out-of-range (`duration_days`
  ≥ 1, `point_multiplier` ≥ 1); a tier slug is normalised and unique among non-deleted rows; the expiry broadcast authorizes on
  `members.notify` on the route (D-6; the request died, the permission rule did not).
- **Tests**: `MemberRequestTest` + the standing Requests coverage bar in
  `bin/coverage_gate.php` (≥ 95 %).

#### P18-B3 — Subscription Actions
- **Task**: `app/Actions/V1/Member/{ActivateMemberSubscriptionAction,CancelMemberSubscriptionAction}`.
  Activate: one `DB::transaction` → create the subscription with `role_name` copied from
  the tier → apply the role to the **`User`** through the existing role path
  (`syncRoles`, never `assignRole` where a swap can narrow) → audit
  `member.subscribed` + `member.role_synced` **inside** the transaction. Cancel: mark
  `cancelled`, revoke only the role that row applied, audit.
- **Acceptance**: a tier rename after activation does not orphan the applied role; the
  role write is visible on `/roles` immediately; a rolled-back activation leaves neither
  a subscription nor a role; nothing dispatches inside the transaction.
- **Tests**: `MemberSubscriptionTest` (E1) + unit coverage bar (Actions ≥ 95 %).

#### P18-B4 — Loyalty point Actions
- **Task**: `app/Actions/V1/Member/{EarnMemberPointsAction,RedeemMemberPointsAction}` —
  signed `delta`, `balance_after`, ledger row + balance update + audit in one transaction,
  `lockForUpdate()` on the `members` row so two concurrent earns cannot lose one
  (same SQLite caveat, D-5).
- **Acceptance**: a redemption larger than the balance is refused before any write; the
  ledger's last `balance_after` equals `points_balance` for every fixture member; a
  correction is a new row, never an update.
- **Tests**: `MemberPointsTest`.
- **Shipped 2026-11-05 (partial, owner request)**: `MemberAdjustPointsAction` is the manual
  half — one signed `delta`, *or* a `purchase_amount` converted by the System Setting rule
  (`member_points_earn_amount` → `member_points_earn_value`, Rp 50.000 → 1.000 by default).
  Same shape the brief asked for: one transaction, `Member::whereKey()->lockForUpdate()`
  re-read before the arithmetic, a stored `balance_after`, a redemption below zero refused
  with no ledger row, a correction posted as another row. `EarnMemberPointsAction` /
  `RedeemMemberPointsAction` as POS-driven classes stay in **Phase 20** with the checkout
  that would call them (points spent as a discount, the cashier note, the printable
  receipt, and the tier `point_multiplier` interaction — the manual action deliberately
  applies the one global rule, not the per-tier factor).
- **Owner revision 2026-11-06**: the Adjust Points card and the web `members.points` route
  were removed — manual balance moves belong to the Phase 20 checkout, not to the profile
  page. The Action, `MemberAdjustPointsRequest` and the API endpoint are unchanged;
  `MemberPointsTest` now drives the API route.

#### P18-B5 — Atomic voucher claim — **MOVED to Phase 20 (P20-B6)**
Owner decision 2026-11-05: the voucher is its own domain — its own flag (`vouchers`), its
own menu, and it is validated at payment time by POS, so the table pair, the Actions, the
requests, the controller, the permissions, the `voucher.claimed` audit event and the race
test all ship in Phase 20. The atomicity rules travel unchanged (one transaction,
`lockForUpdate()` before the quota read, the unique `(voucher_id, member_id)` usage row as
the idempotency backstop the lock cannot be alone against — D-5).

#### P18-B6 — Controllers, routes, sidebar, API twins
- **Task**: `app/Http/Controllers/Web/V1/{MemberController,MemberTierController,MemberDashboardController}`
  (thin: resolve, delegate, `respond()`) — `VoucherController` ships in **P20-B7** — and the `app/Http/Controllers/Api/V1/Member/*`
  twins + `app/Http/Resources/Api/V1` resources; the route groups in `routes/web.php` and
  `routes/api.php` carrying `feature:member_loyalty` + `can:` at the route; **A4b**: the
  `AppMenuComposer` entries with `'permission' => 'members.view'` and
  `'feature' => 'member_loyalty'`; Scramble annotations per
  `docs/base/api/api-architecture.md` § *API Conventions*.
- **Acceptance**: the web and API capability tables diff clean by capability (the Phase 12
  method — path shape is not a gap); no controller contains `DB::transaction` or
  `$request->validate()`; `php artisan route:list` shows the gate on every write route;
  the sidebar entry disappears with the flag off for a superadmin too.
- **Tests**: `MemberWebTest`, `MemberApiTest`; `ApiDocumentationTest` is the drift gate.
- **Read-only slice already live (2026-11-05, see D-4)**: the four GET routes, the three web
  controllers and three sidebar entries (Members, Membership Dashboard, Tiers) are shipped and green in `MemberWebTest`. The create
  The create screen **is** routed as of 2026-11-05 (owner request): `members.create` +
  `members.store` (`MemberCreateAction`, `can:members.create`) and `members.points`
  (`MemberAdjustPointsAction`, `can:members.update`) joined the four reads, so
  `MemberIndexAction` hands a real `createUrl` and the detail page renders the Adjust
  Points card. What this row still owed at the time: the subscribe / cancel writes and the
  API twins + Scramble — those URLs were still null and their affordances still
  disappeared.
  A consequence the brief did not predict: creating a member assigns a role, and role
  assignment is its own permission (`users.assign_roles`, `RoleAssignAction`). So
  `members.manage` alone is a **403** on the store, by design — a member is a login
  account, and an operator who cannot hand out roles must not hand out accounts.
  `MemberCreateTest` pins both halves.
  One naming detail the brief could not predict: the dashboard route is `membership.dashboard`,
  not `members.dashboard` — the sidebar marks an entry active with `fnmatch` against
  `members.*`, which has no pipe alternation, so a `members.` prefix would highlight two
  entries at once. The URI stays `/members/dashboard`.
  The channel question is answered at the moment the routes land (the Phase 12 rule): while
  the twins were missing `ChannelParityTest` carried these as the declared one-sided
  capability `membership screens`; that entry is gone now that `Api/V1/Member` ships.
- **Shipped 2026-11-06 (owner request, "subscribe/cancel + API twins")**:
  `members.subscribe` + `members.subscriptions.cancel` (web) put the P18-B3 Actions behind a
  route for the first time — `MemberSubscribeRequest` resolves the tier, the controller stays
  thin, and `MemberShowAction` now hands real `subscribeUrl` / `tierOptions` / `cancel_url`,
  which is why no Blade changed: the buttons were already conditional on those keys. Their
  nulls are decisions, not gaps — `subscribeUrl` is null for a trashed profile or a member who
  already holds a live period, `cancel_url` for a period that is already cancelled.
  `Api/V1/Member/{MemberController,MemberTierController}` + the four resources are the API
  half: 21 routes under `feature:member_loyalty`, the same Actions and the same Form Requests,
  the same per-route `can:`. `MemberIndexAction::query()` / `MemberTierIndexAction::query()`
  went public instead of being copied — one query per listing, decoration left to each
  channel, which is what makes `ResponseParityTest` able to compare the rows at all. The
  parity map is now capability-by-capability (`member list`, `member read`, `member create`,
  `member points adjust`, `member subscribe`, `subscription cancel`, the delete/restore/force
  /activate/deactivate/bulk pair for both lists, `tier list`, `tier create`, `tier update`),
  with `membership dashboard` the one remaining one-sided entry and its stated reason.
- **Docs**: `docs/base/api/*` shapes, `docs/base/features/membership.md` (written 2026-11-05
  for the shipped slice, its Layers / Routes / Tests sections updated 2026-11-06 for the API).

### Group C — Background processing

Exit gate: `JobConventionTest` green with the two new jobs; `schedule:list` shows the
eighth entry (seven today — `member-points-expiry` landed early, see C3's count note).
**Ran 2026-11-06: both green — `JobConventionTest` passes with the two new jobs, `schedule:list`
prints eight entries with `member-subscription-expiry` at `30 0 * * *`.**

#### P18-C1 — Expiry sweep job
- **Task**: `app/Jobs/MemberSubscriptionExpirySweepJob` on `QueueName::SWEEPS` (an
  idempotent scheduled sweep — the same queue `PasswordExpirySweep` uses, so
  `bin/run-workers.sh` needs no change). Scan `status = active` and `expires_at <= now()`
  in 500-row chunks, set `expired`, revoke the role the subscription row applied, audit
  `member.expired` with `properties.causer = 'SYSTEM'` (the P14-C convention), and
  `failed()` logging through `ReadsCorrelationId`.
- **Acceptance**: the standard `docs/base/infrastructure/queue.md` holds by reflection —
  named queue via `onQueue()`, explicit `$tries`/`$timeout`/`$maxExceptions`/`backoff()`,
  no `public $queue`; `request_id` is never a constructor argument; a second run claims
  zero rows.
- **Tests**: `MemberExpirySweepTest` (E1), `JobConventionTest` (already blanket-enforcing).
  **Shipped 2026-11-06**: the job plus `MemberSubscriptionExpireAction` (status → `expired`,
  the role the row applied revoked through the `RevokesSubscriptionRole` trait now shared
  with the cancel Action — a role another live period still needs survives, the base role
  always does), `member.expired` audited inside the transaction with `causer = 'SYSTEM'`,
  `chunkById(500)`, flag off = log and return. A second run claims zero rows.
  `MemberExpirySweepTest` 6 tests green; `JobConventionTest` green without edits.

#### P18-C2 — Queued bulk expiry notification
- **Task**: `app/Jobs/SendMemberExpiryNotificationJob` chunking the alert window and
  sending `MemberExpiryWarningNotification` (`app/Notifications/`, `via()` reading
  `NotificationChannel::for()` → mail + in-app, D-3), `$afterCommit = true`, and
  `ShouldQueue` per chunk. **Mail only** to the members in the window — one message per
  member, not one message per subscription.
- **Acceptance**: the request that triggers a broadcast returns before any message is
  sent; 1 000 expiring members do not open 1 000 SMTP connections in one job; the
  notification's subject stands alone (the bell shows subjects only —
  `NotificationCopyTest` pins that contract for the existing seven classes, this class
  joins it); the `notifications` retention sweep reaps the rows it writes.
- **Tests**: `MemberExpiryNotificationTest`; a benchmark test for the chunking, matching
  `NotificationBenchmarkTest`.
  **Shipped 2026-11-06**: the broadcast routes carry `can:members.notify` (D-6 —
  no request class: empty payload, the route gate is the authorization, proven by the
  `members.update`-alone refusal), `MemberExpiryBroadcastAction` (reads `member_expiry_alert_window_days` with the
  14-day fallback read inline — owner revision 2026-11-06: no cross-Action constant, a `private const … = MemberExpiryBroadcastAction::DEFAULT_ALERT_WINDOW_DAYS` can shadow the stored setting; `MemberDashboardAction` reads the same key the same way),
  `POST /members/dashboard/broadcast-expiry` (`membership.broadcast-expiry`) and its API
  twin `api.v1.members.broadcast-expiry`, both `can:members.notify` inside the flag group —
  `ChannelParityTest` gains the two-sided `expiry broadcast` capability (`membership dashboard`
  stays the sole one-sided read entry), so
  the Group B loose end closed as a route gate, not a class. The job collapses a member's
  periods to the earliest expiry and sends one `MemberExpiryWarningNotification` per member
  (`$afterCommit = true`, mail + in-app per D-3). Two facts the tests pin: the benchmark
  assertion counts **per channel** — 1 000 members become 2 000 `SendQueuedNotifications`
  jobs, 1 000 of them the mail one — and this suite overrides
  `shouldFakeNotifications(): false`, because the global fake in `Tests\TestCase` would
  otherwise swallow every real queue-out and the 1 000-count would assert against nothing.
  `NotificationCopyTest` gained the class. 9 tests green.

#### P18-C3 — Scheduler entry
- **Task**: one `routes/console.php` entry — daily, `withoutOverlapping()`, `onOneServer()`,
  gated on the `member_loyalty` flag, dispatching C1 to `sweeps`.
- **Acceptance**: `php artisan schedule:list` prints seven entries; the new one is not on
  the minute the other sweeps use (the P17-C3 collision question, answered for the new
  entry); `docs/base/infrastructure/queue.md` and the runbook's schedule table gain the row.
  **Count note 2026-11-05**: `member-points-expiry` (the annual points reset behind
  `member_points_expiry_date`, O-3) shipped early with the write slice, so
  `schedule:list` already prints **seven** at `0 0 * * *`. C1's subscription sweep is the
  **eighth** entry, not the seventh — and it shares midnight with the points sweep, so the
  collision question in this row is live, not hypothetical.
  **Shipped 2026-11-06**: the collision is answered by staggering — the subscription sweep
  runs at `00:30`, half an hour off the points sweep's midnight, both `dailyAt`, named,
  `onOneServer()->withoutOverlapping()`, dispatched to `sweeps` via `Schedule::job()`; the
  flag check lives inside the job (a scheduled entry has no HTTP flag middleware to lean
  on). `schedule:list` prints eight. `queue.md` and the runbook schedule table gained the
  row.

### Group D — Enforcement (RBAC, flag, audit)

Exit gate: every refusal is a measured status code, not a comment claiming one.

#### P18-D1 — Route authorization
- **Task**: add `MEMBERS` to `app/Support/PermissionCatalog.php`
  (`members.view`, `members.manage`, `members.notify` — `vouchers.*` ship in Phase 20,
  P20-D1), let
  `PermissionSeeder` prune-orphan → create → assign, put `can:` on every route, map the
  bulk POST through `AuthorizesBulkAction` (D-6), and add the permissions to the role
  matrix.
- **Acceptance**: a zero-permission user 403s every member route on both channels; the
  broadcast POST refuses a caller holding only `members.manage`; `admin` inherits through
  `PermissionCatalog::all()` and superadmin through `Gate::before` with **no** stored
  `role_has_permissions` rows.
- **Tests**: `MemberAuthorizationTest` (the `StorageAuthorizationTest` shape); update the
  catalogue-drift guards.
- **Verified 2026-11-06**: no separate `MemberAuthorizationTest` file — the acceptance is
  carried by the route loops that grew into it. `MemberWebTest` now walks **all 25 web member
  routes** with staff (zero permissions) and with the flag off for a superadmin, each 403;
  `MemberApiTest`'s loops gained the P18-C2 `api.v1.members.broadcast-expiry` that had slipped
  out of them, so both channels are proven by the table, not by hand-picked screens. The
  broadcast refusal for a caller without `members.notify` ships in `MemberExpiryNotificationTest`.

#### P18-D2 — Feature-flag enforcement
- **Task**: `feature:member_loyalty` on every member route group, web and API.
- **Acceptance**: flag off → **403 on both channels** (D-1, explicitly *not* 404), the
  sidebar entry drops for everyone, and a queued sweep that starts before the flag is
  flipped still finishes its current chunk; an undeclared slug fails closed.
- **Tests**: covered by the existing per-slug flag middleware shape — the new slug is
  asserted, no parallel suite invented.
- **Verified 2026-11-06**: the flag-off half of the `MemberWebTest` loop above now covers all
  25 web routes for a superadmin (previously three screens); the API loops already walked every
  member and tier route. Sweeps check the flag inside `handle()` (`reason=feature_off`), proven
  in `MemberExpirySweepTest`.

#### P18-D3 — Audit events
- **Task**: the four events (`member.subscribed`, `member.role_synced`,
  `member.expired`, plus the one the brief omits because it cannot see the call site:
  `member.points_adjusted`, or the ledger has mutations no trail explains) written by the
  Actions. `voucher.claimed` ships with the voucher domain in Phase 20 (P20-D3).
- **Acceptance**: each event is one `->audit()` call site enumerated from code, not from
  strings (the P16-D1 method), and appears in the Event Map; the audit row and the
  mutation commit or roll back together.
- **Tests**: `MemberAuditTest`; `ActionFirstAuditTest` cited as the standing owner.
- **Docs**: `docs/base/features/audit-trail.md` Event Map (48 → 52 events).
- **Verified 2026-11-06**: no `MemberAuditTest` file — every event is asserted by the suite
  that owns its Action. Enumerating `->audit()` call sites under `app/Actions/V1/Member/`
  yields 19 events (12 `member.*`, 7 `member_tier.*`); all 19 have Event Map rows, and the
  write-inside-transaction rule is proven by the refusal tests (a refused points move, cancel
  or in-use tier delete leaves no audit row). `ActionFirstAuditTest` passes untouched.

### Group E — Verification and closure

#### P18-E1 — Feature test suite
- **Task**: `tests/Feature/Member/{MemberSubscriptionTest,MemberExpirySweepTest}.php`
  plus the files named in B/C/D. Lifecycle: activate → role present → cancel → role
  absent; sweep → status, role, audit, SYSTEM causer; points → ledger sum equals balance,
  over-redemption refused. (`VoucherRaceConditionTest` travels with the voucher domain to
  **P20-E1**.)
- **Acceptance**: the points-ledger test is **sabotage-verified** (remove the balance
  guard → red) and names in its own docblock what SQLite cannot prove (D-5).
- **Tests**: are the tests. Coverage must not lower a bar: global ≥ 85 %, Actions ≥ 95 %,
  Requests ≥ 95 %, Policies 100 % (`bin/coverage_gate.php`).

#### P18-E2 — Full-suite run and tracker reconciliation
- **Task**: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`,
  `php artisan test`, `php artisan route:list`, `schedule:list`, `npm run build`; flip
  `task-tracker.md` rows on evidence; update `progress.md`, `implementation-roadmap.md`,
  `qa-tracker.md`; a doc that contradicts shipped code is corrected toward the code, a
  code that contradicts a contract is a defect with a regression test + `changelog.md`.
- **Acceptance**: counts quoted from the run's own output; the PHPStan ledger stays at 310
  entries or is documented with its growth; nothing is stamped DONE without an assertion
  someone opened and read (the P17-D2 rule).

#### P18-E3 — Member-facing dashboard (delivered 2026-11-06)
- **Task**: `MemberSelfDashboardAction` + `pages/members/my-dashboard.blade.php`;
  `DashboardController` branches to it when the signed-in account holds a loyalty profile and
  `member_loyalty` is on. Shipped as an owner request outside this breakdown, recorded here
  under the ID **P18-E3** (it held **P18-F1** until Group F was written — see D-8).
- **Acceptance**: `tests/Feature/Member/MemberSelfDashboardTest` 5 passed / 20 assertions —
  own tier + expiry + ledger only, empty states, flag off → application dashboard, staff
  unaffected. `pint` PASS, `phpstan` no errors. Documented in
  `docs/base/features/membership.md` § The member's own dashboard.

### Group F — Membership Analytics Dashboard (non-financial member metrics)

Goal: the owner/management view of the programme — growth, retention, lifecycle risk — on the
screen A3 already ships. No money, no revenue, no gateway: those are Phase 20's financial
dashboard. Charts read `members` / `member_subscriptions` / `member_point_histories` /
`users.last_activity_at`, and nothing in this group writes a row.

Read D-8..D-13 before building anything here: the demographic widget has no columns (O-5), the
Action name the brief gives would fail `ActionNamingConventionTest`, and the expiring table plus
its broadcast modal are already delivered — F extends that page, it does not replace it.

#### P18-F1 — Membership Analytics view (`pages/members/dashboard.blade.php`, extended)
- **Task**: on the shipped A3 screen —
  - Content header: title **Membership Analytics**, subtitle *Member growth, retention, and
    lifecycle stats*.
  - Top metric strip (`card border-0 shadow-sm h-100`, the house shape from
    `pages/features/index.blade.php`): active members, renewal rate %, DAU/MAU engagement
    ratio, at-risk / dormant count. Keep the three A3 counters that still mean something
    (points issued) instead of printing two sets of numbers for one question.
  - Timeframe filter: dropdown date-range picker (7 client-side presets: Today/7D/1M/3M/6M/9M/1Y
    fill date inputs, Apply submits `range=custom` via GET to `membership.dashboard` read by the
    Form Request). Server-side `PRESETS` (`7d/30d/90d/1y`) retained for API; UI no longer
    generates those query strings directly.
  - Charts (`col-lg-8` / `col-lg-4`): member growth vs expirations over the selected
    timeframe; member **tenure** distribution (`<1mo`, `1–3`, `3–6`, `6–12`, `>1yr`, from
    `members.joined_at`). Age group / demographic is **cut** — no `date_of_birth` or
    `address` column exists (D-9, decision O-5).
  - Operational lists (`col-lg-6` / `col-lg-6`): the existing Expiring Soon table with its
    `members.notify` broadcast trigger (**already shipped, A3 + C2 — do not rebuild**, D-12);
    the new at-risk list = active subscription **and** `users.last_activity_at` older than
    30 days.
  - Chart library: none is installed and CSP forbids inline JS — the dashboard renders the
    server-side table/bars the repo already has. A charting dependency is `AGENTS.md`
    territory: `changelog.md` + ADR before `composer require`.
- **Acceptance**: every figure arrives from F2 as a primitive; no `route()` call and no
  computed metric in Blade (the D-4 contract the member screens already hold); the filter
  cannot produce a query the Action did not already gate.

#### P18-F2 — Aggregator Action (`MemberAnalyticsStatsAction`)
- **Task**: one Action in `app/Actions/V1/Member/` returning the whole view model. Growth vs
  expirations as a **single grouped conditional aggregate** per timeframe bucket (not N
  queries), tenure histogram as one `CASE` aggregate, renewal rate as
  `renewed members / members whose period ended in the window`, DAU/MAU as two counts on
  `users.last_activity_at`, at-risk as one join. Result cached with `Cache::remember` on
  **primitive arrays only** (AGENTS § Traps, D-11), key including the timeframe so a filter
  switch cannot serve a stale window, and invalidated by the events that already fire
  (`member.subscribed`, `member.expired`, `member.points_adjusted`) plus the tier switches.
- **Acceptance**: no N+1 — the count is asserted against a fixture population; the cache hit
  path returns real numbers, not `__PHP_Incomplete_Class`; the p95 read is **measured** in F4
  and quoted from the run, with the brief's 100 ms as the target it either met or did not.

#### P18-F3 — Controller endpoint (extend `MemberDashboardController`)
- **Task**: `index()` passes the request's timeframe into F2 and hands the merged view model
  to the view; `broadcastExpiry()` is untouched. No new controller, no new route name —
  `membership.dashboard` already exists, already carries `members.view` +
  `feature:member_loyalty`, and already owns its sidebar entry (D-12).
- **Acceptance**: thin controller (resolve → delegate → view); the route keeps its two gates
  and the API read-side parity question is answered in the same commit: the screen stays
  web-only and is recorded in `ChannelParityTest`'s declared one-sided exception, which
  `membership dashboard` already occupies.

#### P18-F4 — Render + aggregation tests (`tests/Feature/Member/MembershipDashboardTest.php`)
- **Task**: fixture render gate in the `MemberUiRenderTest` shape (empty states, no DB, no
  inline script), plus HTTP assertions in the `MemberWebTest` shape: the `members.view` gate,
  flag off → 403, each timeframe returning its own window, and the numbers themselves against
  a known population (a member counted once, an expired period not counted as active, a
  paused row excluded).
- **Acceptance**: the at-risk and renewal assertions are sabotage-verified (break the join →
  red); `tests/Feature/Member` green; the measurement from F2 quoted, not asserted as a
  constant; no bar lowered (`bin/coverage_gate.php`).

---

## 6. Execution order and per-group gates

`A → B → C → D → E → F`, one group open at a time, `IN_PROGRESS → REVIEW → DONE` per task,
never skipping REVIEW (`AGENTS.md`). A group does not open while the previous group has a
task in REVIEW. Two rules that decide most conflicts here are existing owners, not this
phase's invention: authorization lives on the route, audits live in the Action. Group F is
additive: it extends the screen A3 shipped and E2 verified, so it opens after E closes and
does not reopen A–E. (The header block above is dated 2026-11-05 mid-flight; `task-tracker.md`
is the live ledger for which rows closed.)

| Gate | Must be true before the next group starts |
|---|---|
| A | fixture render green, no DB in Blade, flag declared **and** seeded, Pint clean |
| B | migrate/rollback exit 0, Action unit coverage ≥ 95 %, both channels responding, `route:list` shows every gate |
| C | `JobConventionTest` green, `schedule:list` at seven entries, chunking benchmark recorded |
| D | every refusal measured at its status code (403 for permission, 403 for flag — D-1), Event Map updated |
| E | four gates exit 0, trackers reconciled, deferred evidence carried into `progress.md` § *Known Issues* |
| F | aggregation asserted against a known population (not a render-only green), no N+1 in the new queries, cache hit returning primitives, the read time measured rather than claimed |

## 7. What stays open after this phase

Carried into `progress.md` so it does not vanish at closure:

- ~~the MySQL two-session voucher probe (D-5)~~ — travels with the voucher domain to
  Phase 20 § 7; this phase carries the same deferred proof for the points lock only;
- the WhatsApp channel decision (§ 8) — unshipped by design, not by omission;
- points **earn from a sale** has no caller until the Phase 20 POS engine exists. B4's
  manual admin earn/redeem is the shipped half, and is a real use case (an operator
  adjusting a balance), so the class is not dead code — but its `reference_type` morph
  stays unpopulated until Phase 20.

## 8. Open owner decisions

| # | Question | Options | Blocking? |
|---|---|---|---|
| O-1 | **D-2** — is `Member` a profile on `users` (recommended) or a second identity with its own login? | profile / second identity | **CLOSED 2026-11-05** — profile, and the owner asked for the flow to match "new user": `MemberCreateAction` delegates the account half to `UserCreateAction` (generated temp password, `must_change_password`, verification mail), and the new `member` system role is fixed, not chosen | Not blocking — resolved |
| O-2 | **D-3** — WhatsApp driver | defer to Phase 20 / add package + ADR + `SystemSetting` credentials now | Not blocking: C2 ships mail + in-app either way |
| O-3 | Points expiry | never expires / expires with the subscription | **CLOSED 2026-11-05** — neither, on the owner's wording ("yang di-0-kan point member, bukan saldo"): a global, **optional** `member_points_expiry_date` in System Setting (empty = never expire). `MemberExpirePointsAction` zeroes every positive `points_balance`, writes one `Annual points expiry` ledger row per member, then advances the date a whole year so the stored value is always the *next* reset. `MemberPointsExpirySweepJob` runs it daily on `sweeps`. The subscription-linked variant is not built | Not blocking — resolved |
| O-4 | Voucher `max_discount` and `min_purchase` semantics with no order total in Phase 18 | **CLOSED 2026-11-05** — mooted by the move: the whole voucher domain (both columns included) ships in Phase 20 where an order total exists | Not blocking — resolved |
| O-5 | **D-9** — F1's age-group / demographic widget reads columns that do not exist | (a) cut the widget, keep tenure only (recommended: no data collection, no migration); (b) add `date_of_birth` + `address` to a profile migration, which brings PII, a retention question, and Phase 19's Profile Completeness score with it | **Blocking for F1's demographic card only** — F1 shipped 2026-11-06 on **(a)** (the card is absent, tenure covers the row); reopening as (b) is an addition, not a rewrite |
