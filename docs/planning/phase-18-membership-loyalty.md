# Phase 18 — General Membership & Loyalty System

> Date: 2026-11-05 | Status: **PLANNED** (breakdown written 2026-11-05; no task started, no
> code written) | Branch when scheduled: `feature/phase-18-membership-loyalty`
> Purpose: give the base project a membership domain — dynamic tiers, a subscription
> duration/expiry engine, member statistics, automated expiry sweeps with bulk
> notification, and a voucher/discount engine — built entirely on the primitives Phases
> 1–17 already ship (RBAC, Pennant, `SystemSetting`, notifications + queues, audit, API v1).
> Dependency chain: **A → B → C → D → E** (strictly sequential — see § 2.1).
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/` (`AGENTS.md` § Before any task: docs win; a change to a
> design decision goes through an ADR in `docs/planning/decisions.md`).

---

## 1. Scope

Nineteen tasks in five groups (the brief's eighteen, with A4 split per D-4).

| Group | Subject | Nature |
|---|---|---|
| A | Blade interfaces, feature-flag declaration, UI render gate | **Build** (UI only) |
| B | Migrations, models, Form Requests, Actions, web + API controllers | **Build** |
| C | Expiry sweep job, queued bulk notification, scheduler entry | **Build** |
| D | Route authorization, flag enforcement, audit events | **Enforce + verify** |
| E | Lifecycle/race/sweep tests, full-suite run, tracker reconciliation | **Verify + close** |

**Not in scope:**

- **Spending anything.** No checkout, no order line, no tax, no receipt, no printing —
  that is Phase 19. This phase models the tier, the subscription, the points balance and
  the voucher, and makes the voucher *claimable*; it never applies a discount to a sale.
- A payment gateway, an idempotent webhook, or the race-condition shield around money
  movement (Phase 19).
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
5. **Voucher claim is atomic.** One transaction, `lockForUpdate()` on the voucher row
   before the quota is read, a unique `(voucher_id, member_id)` usage row as the
   idempotency backstop. The lock alone is not the guard — see **D-5**.
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
| **D-4** | P18-A4: sidebar entries wired in Group A | `AppMenuComposer` deliberately rejects `#` placeholders — "the menu cannot advertise a screen that was never shipped" — and a Blade reference to `route('members.index')` throws while that route name does not exist. Group A may not create controllers | A4 splits: **A4a** (flag declaration in `config/pennant.php`, `FeatureFlagSeeder` row, `@feature`/`@can` guards inside the views) stays in A; **A4b** (the route group + the two sidebar entries) ships with **B6**, the first commit where the link target exists. The sequential rule is not bent; the task is re-cut |
| **D-5** | P18-E1: `VoucherRaceConditionTest` proves the lock | `phpunit.xml` runs `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. SQLite takes no `SELECT … FOR UPDATE`: the statement parses and does nothing | The test proves what SQLite *can* prove — quota arithmetic, the unique-usage-row backstop, a second claim refused. The row lock itself is recorded as **deferred runtime evidence** needing a two-session MySQL probe, the same way Phase 14 left the restore round trip open |
| **D-6** | P18-D1: `members.view`, `members.manage`, `vouchers.manage` | A bulk POST must map to its own permission (`AuthorizesBulkAction`, P6-C17); Phase 12 Group D found exactly this gap on `features/bulk-action` — a route comment claiming a permission the route never checked. A3's "notify all expiring members" **is** a bulk action | Four permissions: `members.view`, `members.manage`, `members.notify`, `vouchers.manage`. `members.notify` is the broadcast's own gate. Coarse `manage` over create/update/delete stays, matching `settings.manage`/`storage.manage` rather than the 11-verb `users.*` set |
| **D-7** | (unstated) a `Member` domain | `docs/base/architecture/naming-conventions.md` holds a domains table (9 rows as of P16-B2) and `ActionNamingConventionTest` is its owner | B3 adds `Member` to that table in the same commit as the first Action, not at closure |

---

## 4. Data model

Six tables, all with `softDeletes()` where a row is operator-deletable
(`docs/base/data/soft-delete.md`), all timestamps, all MySQL + SQLite-compatible
(the suite runs SQLite).

| Table | Key columns | Notes |
|---|---|---|
| `member_tiers` | `id`, `name` (unique), `slug` (unique), `role_name` (nullable → `roles.name`), `price` `decimal(12,2)`, `duration_days` `unsignedSmallInteger`, `point_multiplier` `decimal(4,2)` default 1.00, `is_active` `boolean`, `sort_order` | `role_name` is a **reference**, not a foreign key to a table Spatie owns per-guard; validated in `StoreMemberTierRequest` against the real role list for the `web` guard (`RoleLookup::guard()`) |
| `members` | `id`, `user_id` (FK → `users.id`, **unique**), `member_number` (unique, string), `points_balance` `unsignedBigInteger` default 0, `joined_at` `timestamp`, `notes` `text` nullable | One profile per account (D-2). No second identity |
| `member_subscriptions` | `id`, `member_id` FK, `member_tier_id` FK, `status` enum(`active`,`expired`,`cancelled`), `starts_at`, `expires_at`, `role_name` (the role actually applied — kept per-row so a later tier edit cannot revoke the wrong role) | `role_name` copied at activation is the fix for "tier renamed after the fact leaves a role orphaned". Index `(status, expires_at)` — the sweep's only query shape |
| `member_point_histories` | `id`, `member_id` FK, `delta` `bigInteger` (signed), `balance_after` `unsignedBigInteger`, `reason` `string`, `actor_id` nullable FK → `users`, `reference_type`/`reference_id` nullable morph | Ledger, never edited: a correction is a new row. `balance_after` makes the ledger auditable without recomputation |
| `vouchers` | `id`, `code` (unique, upper), `type` enum(`percentage`,`fixed`), `value` `decimal(12,2)`, `max_discount` `decimal(12,2)` nullable, `min_purchase` `decimal(12,2)` default 0, `quota` `unsignedInteger` nullable (null = unlimited), `claimed_count` `unsignedInteger` default 0, `per_member_limit` `unsignedTinyInteger` default 1, `starts_at`, `expires_at`, `is_active` | Money as `decimal`, never `float`. Nothing spends it in this phase (D-10 in § 8 open items) |
| `voucher_usages` | `id`, `voucher_id` FK, `member_id` FK, `claimed_at`, `reference_type`/`reference_id` nullable | Unique `(voucher_id, member_id)` — the idempotency backstop the lock cannot be alone against (D-5) |

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

#### P18-A2 — Tier configuration & voucher views
- **Task**: `resources/views/pages/members/tiers.blade.php`, `vouchers.blade.php`. Tier
  rows edit name, slug, price, duration in days, point multiplier, assigned role
  (select built from the existing roles, not free text) and active state. Voucher rows
  edit code, type, value, `max_discount`, `min_purchase`, quota, per-member limit and the
  start/expiry window.
- **Acceptance**: the role select offers exactly the roles the viewer may assign; an
  inactive tier/voucher renders its state distinctly; both tables are
  `.table-responsive`; nothing in the view computes remaining quota.
- **Tests**: `MemberUiRenderTest`.

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
  speed. The HTTP round trip is proven in E1.

### Group B — Data, business logic and the tier/subscription/voucher engine

Exit gate: migrations migrate up and down on SQLite; every Action covered by a unit test;
`ActionNamingConventionTest` and `NoInlineFqnTest` green; both channels respond.

#### P18-B1 — Migrations, models, factories
- **Task**: the six tables of § 4; `App\Models\{Member,MemberTier,MemberSubscription,MemberPointHistory,Voucher,VoucherUsage}`
  each `use Auditable` + `SoftDeletes` where the table says so; factories for every model;
  `User` gains the `member()` has-one (no role logic on the model).
- **Acceptance**: `migrate` then `migrate:rollback` exits 0; a `member` cannot exist
  without a `user`; `member_subscriptions` refuses two `active` rows for one member
  (unique partial shape enforced in the Action, the DB constraint recorded as the
  MySQL-only half — see D-5).
- **Tests**: `MemberModelTest` (relations, casts, the unique `user_id`).

#### P18-B2 — Form Requests
- **Task**: `app/Http/Requests/V1/Member/{StoreMemberRequest,UpdateMemberRequest,StoreMemberTierRequest,UpdateMemberTierRequest,StoreVoucherRequest,UpdateVoucherRequest,ClaimVoucherRequest,BroadcastMemberExpiryRequest}`
  extending `BaseFormRequest`, each with a real `authorize()` returning the permission
  that performs the change (`members.create` is not in the catalogue — `members.manage`
  is, and `authorize()` names it), and `rules()` that call `$request->validated()` at the
  controller.
- **Acceptance**: every numeric bound rejects out-of-range (quota ≥ 0, `duration_days`
  ≥ 1, `min_purchase` ≥ 0, `max_discount` ≥ `value` for a capped percentage); a code is
  normalised to uppercase and unique among non-deleted rows; `BroadcastMemberExpiryRequest`
  authorizes on `members.notify` (D-6).
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

#### P18-B5 — Atomic voucher claim
- **Task**: `app/Actions/V1/Member/ClaimVoucherAction` — inside `DB::transaction`:
  `Voucher::where('code',…)->lockForUpdate()->firstOrFail()` → verify active window,
  `is_active`, quota vs `claimed_count`, the member's usage count vs `per_member_limit` →
  increment `claimed_count`, insert the usage row, audit `voucher.claimed`.
- **Acceptance**: the unique `(voucher_id, member_id)` row means a double-submit cannot
  claim twice even if the lock is inert (D-5); an exhausted quota is refused with the
  error contract's code, not a `ValidationException` leaking to the envelope; a failed
  claim rolls back both the counter and the usage row.
- **Tests**: `VoucherRaceConditionTest` (E1).

#### P18-B6 — Controllers, routes, sidebar, API twins
- **Task**: `app/Http/Controllers/Web/V1/{MemberController,MemberTierController,VoucherController,MemberDashboardController}`
  (thin: resolve, delegate, `respond()`) and the `app/Http/Controllers/Api/V1/Member/*`
  twins + `app/Http/Resources/Api/V1` resources; the route groups in `routes/web.php` and
  `routes/api.php` carrying `feature:member_loyalty` + `can:` at the route; **A4b**: the
  two `AppMenuComposer` entries with `'permission' => 'members.view'` and
  `'feature' => 'member_loyalty'`; Scramble annotations per
  `docs/base/api/api-architecture.md` § *API Conventions*.
- **Acceptance**: the web and API capability tables diff clean by capability (the Phase 12
  method — path shape is not a gap); no controller contains `DB::transaction` or
  `$request->validate()`; `php artisan route:list` shows the gate on every write route;
  the sidebar entry disappears with the flag off for a superadmin too.
- **Tests**: `MemberWebTest`, `MemberApiTest`; `ApiDocumentationTest` is the drift gate.
- **Docs**: `docs/base/api/*` shapes, new `docs/base/features/membership.md`.

### Group C — Background processing

Exit gate: `JobConventionTest` green with the two new jobs; `schedule:list` shows the
seventh entry.

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

#### P18-C3 — Scheduler entry
- **Task**: one `routes/console.php` entry — daily, `withoutOverlapping()`, `onOneServer()`,
  gated on the `member_loyalty` flag, dispatching C1 to `sweeps`.
- **Acceptance**: `php artisan schedule:list` prints seven entries; the new one is not on
  the minute the other sweeps use (the P17-C3 collision question, answered for the new
  entry); `docs/base/infrastructure/queue.md` and the runbook's schedule table gain the row.

### Group D — Enforcement (RBAC, flag, audit)

Exit gate: every refusal is a measured status code, not a comment claiming one.

#### P18-D1 — Route authorization
- **Task**: add `MEMBERS`/`VOUCHERS` to `app/Support/PermissionCatalog.php`
  (`members.view`, `members.manage`, `members.notify`, `vouchers.manage`), let
  `PermissionSeeder` prune-orphan → create → assign, put `can:` on every route, map the
  bulk POST through `AuthorizesBulkAction` (D-6), and add the permissions to the role
  matrix.
- **Acceptance**: a zero-permission user 403s every member route on both channels; the
  broadcast POST refuses a caller holding only `members.manage`; `admin` inherits through
  `PermissionCatalog::all()` and superadmin through `Gate::before` with **no** stored
  `role_has_permissions` rows.
- **Tests**: `MemberAuthorizationTest` (the `StorageAuthorizationTest` shape); update the
  catalogue-drift guards.

#### P18-D2 — Feature-flag enforcement
- **Task**: `feature:member_loyalty` on every member route group, web and API.
- **Acceptance**: flag off → **403 on both channels** (D-1, explicitly *not* 404), the
  sidebar entry drops for everyone, and a queued sweep that starts before the flag is
  flipped still finishes its current chunk; an undeclared slug fails closed.
- **Tests**: covered by the existing per-slug flag middleware shape — the new slug is
  asserted, no parallel suite invented.

#### P18-D3 — Audit events
- **Task**: the four events (`member.subscribed`, `member.role_synced`,
  `voucher.claimed`, `member.expired`) written by the Actions, plus a fifth the brief
  omits because it cannot see the call site: `member.points_adjusted`, or the ledger has
  mutations no trail explains.
- **Acceptance**: each event is one `->audit()` call site enumerated from code, not from
  strings (the P16-D1 method), and appears in the Event Map; the audit row and the
  mutation commit or roll back together.
- **Tests**: `MemberAuditTest`; `ActionFirstAuditTest` cited as the standing owner.
- **Docs**: `docs/base/features/audit-trail.md` Event Map (48 → 53 events).

### Group E — Verification and closure

#### P18-E1 — Feature test suite
- **Task**: `tests/Feature/Member/{MemberSubscriptionTest,VoucherRaceConditionTest,MemberExpirySweepTest}.php`
  plus the files named in B/C/D. Lifecycle: activate → role present → cancel → role
  absent; sweep → status, role, audit, SYSTEM causer; claim → quota, double submit,
  per-member limit, rollback.
- **Acceptance**: the race test is **sabotage-verified** (remove the unique index and the
  quota increment → red) and names in its own docblock what SQLite cannot prove (D-5).
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

---

## 6. Execution order and per-group gates

`A → B → C → D → E`, one group open at a time, `IN_PROGRESS → REVIEW → DONE` per task,
never skipping REVIEW (`AGENTS.md`). A group does not open while the previous group has a
task in REVIEW. Two rules that decide most conflicts here are existing owners, not this
phase's invention: authorization lives on the route, audits live in the Action.

| Gate | Must be true before the next group starts |
|---|---|
| A | fixture render green, no DB in Blade, flag declared **and** seeded, Pint clean |
| B | migrate/rollback exit 0, Action unit coverage ≥ 95 %, both channels responding, `route:list` shows every gate |
| C | `JobConventionTest` green, `schedule:list` at seven entries, chunking benchmark recorded |
| D | every refusal measured at its status code (403 for permission, 403 for flag — D-1), Event Map updated |
| E | four gates exit 0, trackers reconciled, deferred evidence carried into `progress.md` § *Known Issues* |

## 7. What stays open after this phase

Carried into `progress.md` so it does not vanish at closure:

- the MySQL two-session voucher probe (D-5) — `lockForUpdate()` is untestable on SQLite;
- the WhatsApp channel decision (§ 8) — unshipped by design, not by omission;
- points **earn from a sale** has no caller until the Phase 19 POS engine exists. B4's
  manual admin earn/redeem is the shipped half, and is a real use case (an operator
  adjusting a balance), so the class is not dead code — but its `reference_type` morph
  stays unpopulated until Phase 19.

## 8. Open owner decisions

| # | Question | Options | Blocking? |
|---|---|---|---|
| O-1 | **D-2** — is `Member` a profile on `users` (recommended) or a second identity with its own login? | profile / second identity | Blocks B1. A second identity means a second authn surface, a second `User`-shaped model in every `can:` path, and new ADRs |
| O-2 | **D-3** — WhatsApp driver | defer to Phase 19 / add package + ADR + `SystemSetting` credentials now | Not blocking: C2 ships mail + in-app either way |
| O-3 | Points expiry | never expires / expires with the subscription | Not blocking for the schema; blocks the sweep's scope if "yes" (a second sweep) |
| O-4 | Voucher `max_discount` and `min_purchase` semantics with no order total in Phase 18 | store + validate + document as spend-time inputs consumed by Phase 19 / drop until 19 | Not blocking; § 4 keeps the columns because Phase 19 needs them and adding a column later to a table with rows is a data migration |
