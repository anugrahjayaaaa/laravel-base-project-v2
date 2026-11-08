# Membership & Loyalty

Feature flag `member_loyalty` (`config/pennant.php`, group `Membership`). Flag off →
every member route **403s** (`App\Http\Middleware\EnsureFeatureIsEnabled`) and the whole
**Membership** sidebar label drops for everyone, including a superadmin. The flag also owns
five System Setting keys, so a settings save with the flag off strips them (§ Settings).

Phase 18. Design authority: `docs/planning/phase-18-membership-loyalty.md`; the delivered
rows and what still ships are in `docs/planning/task-tracker.md`.

## Layers (convention § 0)

| Layer | State |
|---|---|
| Backend web | Shipped: `Web/V1/{MemberController,MemberTierController,MemberDashboardController}` — reads, member creation, subscribe/cancel, tier CRUD, trash, state switches, bulk. `Web/V1\DashboardController` also swaps `/dashboard` for the member's own dashboard (§ The member's own dashboard) |
| Backend API | Shipped for everything the web channel offers except the three read-only screens (`GET /members/create`, `GET /members/tiers/{tier}/edit`, `GET /members/dashboard`): `Api/V1/Member/{MemberController,MemberTierController}` + `MemberResource`, `MemberSubscriptionResource`, `MemberTierResource`, `MemberPointHistoryResource`. Every route runs the same Actions and the same Form Requests, so the guards are not restated |
| AdminLTE UI | `resources/views/pages/members/{index,create,show,tiers,tier-form,dashboard,my-dashboard}.blade.php` |

The API is independently usable: a non-browser client can register a member, price tiers,
issue and cancel a period, move points, use the trash and queue an expiry broadcast —
`ChannelParityTest` records each of those as a two-sided capability. `membership dashboard`
is the declared one-sided exception, with its reason.

## Data model

Four tables (`P18-B1`): `member_tiers`, `members`, `member_subscriptions`,
`member_point_histories`. `members.user_id` is a **unique** FK — a member is a profile on
`User`, never a second identity (open decision O-1, closed 2026-11-05). `members.is_active` is
the profile's own switch (a trashed row is a different thing from a deactivated one);
`member_subscriptions.paused_at` is the frozen clock of a period whose member stepped out
without a sanction. `Member`,
`MemberPointHistory`, `MemberSubscription`, `MemberTier` are `Auditable`; `Member` and
`MemberTier` are `SoftDeletes`. Morph aliases: `member`, `member_tier`,
`member_subscription`, `member_point_history` (`App\Support\MorphMap`).

Alongside them sits `currencies` — a reference table, not a loyalty table. It holds the code,
symbol and separator characters for each currency an operator may pick, and it exists only so
`App\Support\Money` can write an amount the way that currency is written. `CurrencySeeder`
fills it from a static list (no HTTP, unlike `TimezoneSeeder`: the set is small and stable and
a screen that prints a price must not depend on a service being up).

## Routes

All inside `Route::middleware('feature:member_loyalty')`; authorization is on the route
(`agents.md` hard rule), never in a controller constructor.

| Route | Name | Permission |
|---|---|---|
| `GET /members` | `members.index` | `members.view` |
| `GET /members/create` | `members.create` | `members.create` |
| `POST /members` | `members.store` | `members.create` |
| `GET /members/tiers` | `members.tiers` | `member_tiers.view` |
| `GET /members/tiers/create` | `member_tiers.create` | `member_tiers.create` |
| `POST /members/tiers` | `members.tiers.store` | `member_tiers.create` |
| `GET /members/tiers/{tier}/edit` | `members.tiers.edit` | `member_tiers.update` |
| `PUT /members/tiers/{tier}` | `member_tiers.update` | `member_tiers.update` |
| `POST /members/tiers/{tier}/activate` | `member_tiers.activate` | `member_tiers.activate` |
| `POST /members/tiers/{tier}/deactivate` | `member_tiers.deactivate` | `member_tiers.deactivate` |
| `DELETE /members/tiers/{tier}` | `members.tiers.destroy` | `member_tiers.delete` |
| `POST /members/tiers/{tier}/restore` | `member_tiers.restore` | `member_tiers.restore` |
| `DELETE /members/tiers/{tier}/force` | `members.tiers.force-delete` | `member_tiers.force_delete` |
| `POST /members/tiers/bulk-action` | `members.tiers.bulk-action` | per action (see below) |
| `GET /members/dashboard` | `membership.dashboard` | `members.view` |
| `POST /members/dashboard/broadcast-expiry` | `membership.broadcast-expiry` | `members.notify` |
| `GET /members/{member}` | `members.show` | `members.view` |
| `POST /members/{member}/subscribe` | `members.subscribe` | `members.update` |
| `POST /members/subscriptions/{subscription}/cancel` | `members.subscriptions.cancel` | `members.update` |
| `POST /members/{member}/activate` | `members.activate` | `members.activate` |
| `POST /members/{member}/deactivate` | `members.deactivate` | `members.deactivate` |
| `DELETE /members/{member}` | `members.destroy` | `members.delete` |
| `POST /members/{member}/restore` | `members.restore` | `members.restore` |
| `DELETE /members/{member}/force` | `members.force-delete` | `members.force_delete` |
| `POST /members/bulk-action` | `members.bulk-action` | per action (see below) |

`{tier}` resolves through the default (non-trashed) scope, so restore and force-delete take the
raw id and re-read it through `onlyTrashed()` — the two-step that stops a live tier being
destroyed in one click. `{member}` is bound `withTrashed()` (a trashed profile stays readable),
so those two routes carry an `abort_unless($member->trashed(), 404)` instead. Same protection,
two mechanisms, because the two bindings differ.

**The API mirrors the table, one prefix deeper.** `routes/api.php` group
`feature:member_loyalty` declares `api.v1.members.{index,store,show,points,subscribe,subscriptions.cancel,broadcast-expiry,destroy,restore,force-delete,activate,deactivate,bulk-action}`
and `api.v1.members.tiers.{index,store,update,destroy,restore,force-delete,activate,deactivate,bulk-action}`
under `/api/v1/members…` — same verbs, same `can:` per route, same two bulk endpoints with no
route-level `can:` (`throttle:bulk-action`, permission decided from the body's `action`). The
three web GETs that exist only to render a form or a screen (`members.create`,
`members.tiers.edit`, `membership.dashboard`) have no twin: an API client posts the payload or
reads the subscription rows directly. `api.v1.members.show` answers with the profile plus its
`subscriptions` and `points` arrays, so the badge the web table derives is left to the client.
The asymmetry runs the other way for `points` alone: the web route and its Adjust Points card
were removed 2026-11-06 by owner decision — a balance move belongs to the Phase 19 checkout that
earned it — while the endpoint stays for programmatic corrections.

**Two permission sets, not one** (ADR-032). `members.*` is the member profile and
`member_tiers.*` is tier configuration; the suffix list of each is the `users.*` shape —
`view` / `create` / `update` / `delete` / `restore` / `force_delete` / `activate` /
`deactivate` — with `members.notify` as the one addition. There is no `members.manage`: one
"manage" made Add-member, a points adjustment and a tier price edit a single decision, and the
users list never had it.

**Removing is its own permission, three ways** — `members.delete` (to the trash),
`members.restore`, `members.force_delete` — the way users and roles already split them.
`members.update` edits and does not remove. The bulk endpoints carry no `can:` on the route:
`BulkMemberRequest` / `BulkMemberTierRequest` use the shared `AuthorizesBulkAction`, which maps
the body's `action` to `<prefix>.<suffix>` — so `delete` needs `members.delete` while a tier bulk
call is authorised by `member_tiers.delete` (its `bulkEntityPrefix()` is `member_tiers`). An action
with no matching permission (e.g. `lock`, which only users have) is a 403, not a fallthrough.
Each handler's `getValidItems()` narrows first: `delete` acts only on live rows, `restore` and
`force_delete` only on trashed ones, so a mixed selection touches what it may and ignores the
rest.

**Switching a row off is a permission pair of its own** — `members.activate` /
`members.deactivate`, one pair for both lists, on the `users.activate` / `users.deactivate`
shape. Both lists render them the way the users row does: icon only, the action name on hover.

- **Tier** — `member_tiers.is_active`, the same column the tier form's `Active` switch writes.
  New subscriptions stop; rows already subscribed to the tier keep running, which is exactly the
  case `MemberTierDeleteAction` refuses to trash. **Renewal rule:** a member on a deactivated tier
  keeps their current tier valid until `member_subscriptions.expires_at`, but cannot renew or
  extend it while `is_active` is `false` — `MemberSubscriptionActivateAction` refuses an inactive
  tier with a `ValidationException` and never re-activates the tier.
- **Member** — `members.is_active`, the profile's own switch, independent of the subscription's.
  `MemberDeactivateAction` asks one question, and the modal carries it: *is this a violation?*
  - violation → the live subscription goes `cancelled`. The paid period is forfeit by the
    decision taken here, and `reason` is mandatory (`MemberDeactivateRequest`) because a
    sanction nobody wrote down is not a record.
  - not a violation → `member_subscriptions.paused_at` freezes the clock. `status` and
    `expires_at` stay put, so `MemberActivateAction` adds the paused span back and hands over
    the days that were bought and never used — **for a period still `active` only**. A row
    cancelled after the pause keeps its `paused_at` (the cancel ends the period, it does not
    clean the freeze stamp), and the resume filters on `status`, so a dead period is never
    credited days and never reported as resumed.
  Either way `member.deactivated` carries `last_tier`, `points_balance`, `remaining_days`
  (rounded up: part of a day lost is a day lost), `violation` and `reason` — the state to
  rebuild from, because coming back in is a fresh decision, not an undo. Activate issues no
  subscription: the member buys the next period themselves or at the cashier — **after** the
  profile is switched back on. `MemberSubscriptionActivateAction` refuses a period for a
  deactivated profile, because its paused one is still owed and a second live row would leave
  two `paused_at` rows that a single resume cannot both hand back.

Neither route touches the login account — that is `users.deactivate`, a different permission on a
different table. The violation switch and reason field are **pushed into** the shared
confirmation modal, not written into it: `pages/members/index.blade.php` owns the block and
emits it with `@push('confirmModalFields')`, and `layouts/partials/modals/confirmation.blade.php`
renders `@stack('confirmModalFields')` in its body. The distinction is not cosmetic — that modal
partial is `@include`d by `layouts/app.blade.php`, so a block hardcoded there ships on every
authenticated page in the app, which is how the feature-flag and notification screens came to
carry a membership control (`MemberWebTest::test_the_deactivation_fields_ship_only_on_the_member_list`
is the gate that keeps it on this page). A trigger asks for the block by rendering
`x-ui.confirm-action` with `:deactivation-fields="true"`, which emits `data-deactivation-fields`,
and the driver enables it per trigger; on any other page the block is not in the DOM at all.
Inside an enabled block
the reason field stays disabled and not-required until the violation switch is turned on — toggle
off, reason disabled; toggle on, reason editable and required. Every other action
submits neither key.

`{member}` resolves through `Member::withTrashed()` — a trashed profile is still readable,
and its write affordances disappear. The dashboard is named `membership.dashboard`, not
`members.dashboard`: the sidebar marks an entry active with `fnmatch` against `members.*`
(no pipe alternation), and a `members.` prefix would light up two entries at once. The URI
stays `/members/dashboard`. The tiers keep the `members.tiers*` names but are the one
sub-tree the Members entry excludes, through the item's `except` key — see
`layouts/partials/sidebar.blade.php`. `MemberWebTest` asserts exactly one active item per
membership page.

**Views render no URLs of their own** (D-4): every `route()` is resolved by the Action and
arrives as a variable, and a **null write URL removes the affordance** rather than render a
button that 404s. Trashing, restore, force-delete, the bulk bar, subscribe and cancel are live;
`subscribeUrl` is null exactly when no new period may be issued — the profile is trashed, is
deactivated, or a live period already runs — and a subscription's `cancel_url` is null once it
is cancelled. The
detail page links the profile to the account behind it (`user_url`, shown only to a viewer who
holds `users.view`).

**Both lists have tabs, and the tabs are the Action's work.** `MemberIndexAction` and
`MemberTierIndexAction` return a `tabs` array of `{label, url, count, active}` objects —
`Active` / `Inactive` / `Trash` — because a Blade file may not call `route()` or build a query
string. Both lists also share the same header layout: a `search` filter (name/slug for tiers,
member number/user name for members), the bulk bar at the right end of the filter row, and
pagination at 10 per page. For members, *active* and *inactive* follow the profile's own `is_active` flag — the same
switch the Activate / Deactivate button pair offers, so a row's tab and its state button can never
disagree (ADR-031). Subscription state stays visible as the row badge (`ACTIVE` / `EXPIRED` /
`CANCELLED` / `NONE`): a lapsed-but-undeactivated profile sits in Active carrying an EXPIRED badge.
For tiers it is also `is_active` alone. `status=trashed` wins over the other two, so a
trashed row never counts twice, and the default is `active` — a trashed row is exactly what used
to disappear. `status` is validated (`in:active,inactive,trashed`) so a typo in a bookmark
redirects back with an error rather than silently showing everything. Each tab's URL carries the
current `search`, and the filter form posts `status` as a hidden input, so searching inside the
trash tab stays inside the trash tab.

## Creating a member

`MemberCreateAction` delegates the account half to `UserCreateAction`, so a member is a
login account exactly like an admin-created user: generated temporary password,
`must_change_password`, welcome mail + signed verification link sent after that Action's
commit. The role is fixed to `SystemRole::MEMBER` — the operator does not choose it, and
`PermissionSeeder` grants that role nothing.

Two transactions, deliberately not one: wrapping `UserCreateAction` would mail credentials
for an account that a rolled-back member profile then leaves behind.

**`member_number` is never operator input.** `Member::nextMemberNumber()` reserves it inside
the second transaction: `{prefix}-NNNNNN`, one above the highest number already issued under
that prefix (soft-deleted members included, so a number is never reused), with the prefix
from `member_number_prefix`. The row's unique index stays the last line of defence. The form
shows the number in a **disabled** field — a disabled input carries no `name`, so a forged
`member_number` in the payload is not validated and not read.

**`members.create` without `users.assign_roles` is a 403 here.** Handing out a role is its own permission
(`users.assign_roles`, enforced by `RoleAssignAction`), so an operator who cannot assign
roles cannot create login accounts. Both halves are pinned by `MemberCreateTest`.

## Trashing, restoring, destroying a member

`MemberDeleteAction` soft-deletes the loyalty profile and nothing else: the login account
stays, and is deleted on the users screen — leaving the programme is not the same decision
as revoking access. One `member.deleted` row, written inside the transaction, carrying the
`member_number` captured before the delete.

`MemberRestoreAction` writes `member.restored` and puts the profile back in the default scope.
`MemberForceDeleteAction` writes `member.force_deleted` and captures the counts *before* the
DELETE — `subscriptions` and `point_history_rows` — because both FKs cascade and the audit row
is the only place that number survives. The `users` row is not this screen's to delete: it is
the row the FK points *at*, so destroying the profile leaves the login account alone (and
deleting that account is refused by the FK until the profile is gone).

Trash → restore or trash → permanent delete is the only order the UI offers: a live profile 404s
on both write routes, and `getValidItems()` on the bulk path drops live rows for `restore` /
`force_delete`. Proven by `MemberDeleteTest` and `MemberTrashTest`.

## Tiers

Three screens, the shape the users feature uses: a read-only list (`pages/members/tiers`),
one form page that serves create and edit (`pages/members/tier-form`), and a row delete
through the shared confirmation modal. The per-row inline editor Group A shipped is gone —
the list now prints values and links each row to `/members/tiers/{id}/edit`.

**The slug is generated, never typed.** `MemberTierRequest::prepareForValidation()` overwrites
whatever arrived under `slug` with `Str::slug($name)` — on create and on every edit, so renaming
a tier renames its slug. The form renders the field **disabled** because a disabled input
submits nothing: the value on screen is a preview (`@push('scripts')` mirrors the generator), and
the server's is the one stored. A submitted slug is therefore ignored rather than trusted —
an integration key a hand-crafted POST can set to anything is not a key. The generated value
still runs through the `unique` rule, so two names that slugify alike are refused with a message
pointing at the name, which is the only field left to fix.

**Both list-style screens describe themselves; only the form pages link back.** `pages/members/tiers`
and `pages/members/dashboard` open with a one-line description of what they hold — the way `Members`,
`Permissions` and `Vouchers` do — because there is no screen above them to return to. The breadcrumb
keeps its `Members` link for anyone who wants it. `tier-form` and the member profile are sub-pages, so
they keep "Back to Tiers" / "Back to Member List" (`docs/base/ui/design-system.md` § Content Header).

**The tier edit page owns a Danger Zone**, the same card the member profile uses: copy, the shared
`x-ui.confirm-action` modal, and `members.tiers.destroy` behind it. The create page renders no card —
there is no row to trash yet — and the list keeps its per-row delete, so the two affordances point at
one route with one Action. Both sit behind `@can('member_tiers.delete')`, not `member_tiers.update`.

**A trashed tier is a row, not a ghost.** The list used to read the default scope, so trashing one
removed its only route back — no Restore, no Permanent Delete, nothing on screen. `status=trashed`
is the third tab, and the rows it shows carry `restore_url` / `force_delete_url` where a live row
carries `edit_url` / `delete_url`; the view's null-hides-the-button rule does the rest. A trashed
row is tinted and badged `DELETED`. `MemberTierForceDeleteAction` refuses a tier that still has
subscriptions on it with the same `ValidationException` on `name` as the soft delete, and a bulk
tier call runs in one transaction, so one in-use tier aborts the whole batch rather than trashing
the rows that came before it — the operator fixes the conflict and resubmits.

**`MemberTierSeeder` ships three defaults** (`DatabaseSeeder` calls it: starter configuration,
not demo rows): Bronze 30 days `Rp 35.000,00` ×1.00, Silver 60 days `Rp 70.000,00` ×1.50, Gold
100 days `Rp 100.000,00` ×2.00, all with `role_name` null and `is_active` true. The seeder is
idempotent through `withTrashed()->firstOrNew(['slug' => …])` + `restore()`, because a
soft-deleted tier still holds its slug in the unique index and a plain `updateOrCreate` would
try to INSERT over it.

**Prices are formatted, never computed.** `MemberTierIndexAction` hands the view
`price_display` from `App\Support\Money::format($tier->price)`, which reads `currency_code`
plus that currency's symbol and separators. Nothing converts: an amount stored as 100000 is
printed as 100000 in whatever currency the operator chose (`shortcut:` a per-tier currency
would need a rate lookup here).

**The role picker never offers `superadmin`.** `MemberTier::assignableRoleOptions()` is
`RoleLookup::assignable()` minus that one name, unconditionally rather than per viewer (compare
`visibleTo()`, which is viewer-scoped): attaching superadmin to a tier would hand
administrator rights to whoever subscribes to it. The same set builds the dropdown and backs
`MemberTierRequest`'s `Rule::in(...)`, so the form cannot offer a choice its own validation
refuses — and a payload naming superadmin posted directly gets a field error.

**Editing a tier is forward-only.** A subscription copies role and expiry at the moment it is
created, so changing duration or role afterwards does not restatise the members already on the
tier; the form says so on the page. `MemberTierUpdateAction` audits `member_tier.updated` with
only the fields that changed (same shape as `UserUpdateAction`), and
`MemberTierDeleteAction` refuses while any subscription points at the tier — a delete would
strand rows that are not trashed with it, so the answer is "deactivate it instead". Refusal is
a `ValidationException` on `name`, which renders back onto the list with the shared error bag.

## Points

`MemberAdjustPointsAction` posts one signed movement, in two mutually exclusive modes
(`MemberAdjustPointsRequest::after()` refuses giving both, and refuses a zero delta). It is
reachable only through `POST /api/v1/members/{member}/points` since 2026-11-06 (owner): the
manual card on the member page was removed because Phase 19's checkout earns the points, and
the endpoint stays for programmatic corrections:

- `delta` — points, positive or negative.
- `purchase_amount` — rupiah, converted by the configured earn rule (§ Settings).

The balance is re-read under `Member::whereKey()->lockForUpdate()` before the arithmetic,
then balance + ledger row + `member.points_adjusted` audit are written in the same
transaction. `balance_after` is **stored, not derived**. A movement that would take the
balance below zero is refused before any write, and a correction is always another row —
nothing edits a ledger entry.

`shortcut: the tier `point_multiplier` is not applied — the owner asked for one global earn
rule; a per-tier factor is a second one.`

### Annual expiry

`MemberExpirePointsAction` (driven by `MemberPointsExpirySweepJob`, queue `sweeps`, daily at
00:00, `withoutOverlapping`) reads `member_points_expiry_date` and does nothing when it is
empty or still in the future. When it is due it chunks members with a positive balance
(500 at a time), zeroes the balance, appends one `Annual points expiry` ledger row per
member, audits `member.points_expired` with `properties.causer = SYSTEM`, then advances the
setting to the next anniversary strictly after today — so the stored value is always the
*next* reset. Each chunk is its own transaction, not the whole run: at 100k members one
transaction would hold every row lock for minutes, and a chunk that dies is picked up by the
next tick because a profile already at zero is skipped. **Only `points_balance` is touched; no
member's subscription, tier or account is ended by this job.**

The job refuses to run when the flag is off (it logs `reason=feature_off`) — the flag is the
kill switch for the sweep as well as the screens.

### Subscription expiry sweep

`MemberSubscriptionExpireAction` (driven by `MemberSubscriptionExpirySweepJob`, queue
`sweeps`, daily at **00:30** — staggered half an hour off the points sweep's midnight, so the
two sweeps never race for the same chunk of workers) scans live, unpaused periods with
`expires_at <= now()` in `chunkById(500)` — carrying `member.user.roles` and `tier` on the
chunk, because the per-row work reads all four and an un-eager-loaded sweep costs four selects
per period —
sets each to `expired`, and revokes only the role
that row applied — through `RevokesSubscriptionRole`, the same trait the cancel Action uses,
so a role another live period still needs survives, and the base role always does. Audits
`member.expired` with `properties.causer = SYSTEM` inside the transaction. Idempotent: a
second run claims zero rows, and periods inside their window or frozen by a pause are not
rows it ever sees. Flag off → log and return, same as the points sweep.

### Notifying expiring members

`POST /members/dashboard/broadcast-expiry` (`membership.broadcast-expiry`, `members.notify`)
does one thing: `MemberExpiryBroadcastAction` reads `member_expiry_alert_window_days` (14
fallback), writes `member.expiry_broadcasted` with that window and the operator as causer, and
then pushes `SendMemberExpiryNotificationJob` — the request returns before any
message is sent, and the API twin (`api.v1.members.broadcast-expiry`) queues the same run.
The audit row is written **before** the dispatch, so a mail that went out always has the name
of whoever ordered it; a refused caller (no `members.notify`) leaves neither behind.
The job scans live periods inside the window grouped per member (the **earliest** expiry wins
when one member holds several), then `Notification::send`s one
`MemberExpiryWarningNotification` per member on the switches' channels — mail + in-app only
(D-3: no WhatsApp driver exists) — with `$afterCommit = true`, so nothing goes out for a
rolled-back read. The dashboard's *Notify Expiring Members* button is a confirm-action trigger
inside `@can('members.notify')` — a viewer with `members.view` sees the expiring table but no
button, and the route itself 403s the same caller, so screen and gate cannot disagree.

## Membership analytics

`GET /members/dashboard` (`membership.dashboard`, `members.view` + `feature:member_loyalty`)
is one screen, not two: the Group C *Expiring soon* table and its broadcast modal stayed
where they are, and Group F's read was merged into the same view model. `MemberDashboardController::index()`
takes `MemberAnalyticsQueryRequest` (the timeframe lives in the query string) and passes
`array_merge($dashboardAction->run(), $analyticsAction->run($request->window()))`
to the view. No new route name, no new permission, no API twin — the numbers an API client
wants are the same Action, and a read-only screen gets no twin by the rule in § Routes.

**Filter UI (dropdown revision 2026-11-08):** a single Stockbit-style dropdown trigger
displays the current window dates. Opening it reveals two `input[type=date]` fields (both
constrained `max=today`), seven quick-preset buttons (Today/7D/1M/3M/6M/9M/1Y) that fill
the inputs client-side, and an Apply button. All submissions carry `range=custom` with
explicit dates. The server-side `PRESETS` map (`7d/30d/90d/1y`) and the `timeframes()`
builder in `MemberAnalyticsStatsAction` remain for API and programmatic access but are
not exercised by the dropdown UI — cleanup deferred.

`MemberAnalyticsStatsAction` answers every number on the page. The bold names are the widget
titles on screen; each one carries a `bi-info-circle` tooltip that states what is counted and
what is not.

- **New Members vs Expiring Subscriptions** — `members.joined_at` and
  `member_subscriptions.expires_at` bucketed by day (≤ 14 days), week (≤ 92) or month, one
  statement per series: N `SUM(CASE WHEN col >= ? AND col < ? THEN 1 ELSE 0 END)` columns.
  Half-open buckets, so a row on a boundary is counted once. Dialect-portable (MySQL + sqlite),
  so no `DATE_FORMAT`.
- **Renewal Rate** — periods started inside the window that follow an *expired* period of the
  same member whose `expires_at` came at or before the new `starts_at`. The temporal join is
  what makes it a renewal; a period that overlaps the one it replaces is an upgrade, not a
  renewal (`test_a_period_that_overlaps_the_one_it_replaces_is_not_a_renewal`).
- **Daily Engagement** and **Inactive Members** — `users.last_activity_at`, written at
  login. Inactive is a live (`currentlyActive()`, so paused rows drop out) period whose member
  has not signed in for 30 days or never has — `Never` on the list, not omitted.
- **Length of Membership** — `members.joined_at` in five bands. The demographic/age widget the
  brief listed is **cut**: no date-of-birth column exists and collecting one for a chart is PII
  the phase does not otherwise hold (D-9, O-5a).

Layout (owner revision 2026-11-07): one row of counter cards, then **one** row holding the
series `col-lg-8` with *Members per Tier* stacked above *Length of Membership* in `col-lg-4`,
then *Expiring Soon* and *Inactive Members* side by side in `col-lg-6` each. The two list cards
are `h-100 d-flex flex-column` with a body capped at `max-height: 340px` and `overflow-auto`,
so they are always the same height and a long table scrolls inside its own card instead of
pushing the row apart. The tooltip text is static prose in Blade — the same shape the settings
page uses, initialised by `adminlte.min.js`, `tabindex="0"` so it is keyboard-reachable.

Bars are server-rendered Bootstrap `role="progressbar"` with the width computed in the Action
(relative to the busiest bucket), so no chart library is installed and Blade computes nothing
(D-4). Cached with `Cache::remember` on primitives only, key `member_analytics.<range>` for
preset ranges or `member_analytics.custom.<from>.<to>` for the dropdown; `MemberAnalyticsObserver`
on `Member` + `MemberSubscription` busts the four preset keys
`afterCommit`. Login writes `last_activity_at` with `updateQuietly()`, which bypasses
observers, so DAU/MAU freshness is bounded by the 300 s TTL rather than an event. Measured on
60 members (sqlite, `MembershipDashboardTest`): cold p95 **11.5 ms**, cached **0.052 ms**
against the brief's 100 ms target.

## The member's own dashboard

`GET /dashboard` (`dashboard`) is the one screen a `member` account can reach: the route
carries no `can:` gate, and the `member` system role holds no permission, so every other
screen 403s. `DashboardController` therefore renders `pages/members/my-dashboard.blade.php`
from `MemberSelfDashboardAction` when the signed-in account has a loyalty profile **and**
`member_loyalty` is on — `FeatureCatalog::isActive()`, the same helper `EnsureFeatureIsEnabled`
uses, so a flag-off module never gets its tables read from an ungated route. Anyone else, and
a member whose flag went off, gets the application dashboard unchanged.

The view model is the same contract as the other member read Actions: tier name, subscription
status label + badge variant, `starts_at` / `expires_at` / days remaining, `points_balance`,
and the last 10 ledger rows (`created_at`, `reason`, signed delta, `balance_after`) — all
primitives, nothing computed in Blade, no `route()` call (there is no other member screen to
link to, so the page would be advertising a 403). Status is read exactly as
`MemberShowAction::row()` reads it: the live period if there is one, otherwise the most recent,
so a lapsed member sees the tier they had rather than an empty card; a deactivated profile is
INACTIVE whatever its subscription did.

No new route, no new permission, no API twin: it is the same profile the caller already owns,
and `GET /api/v1/members/{member}` already answers it programmatically.

## Settings

Five keys, all gated on `member_loyalty`'s `settings` group in `config/pennant.php` and
rendered in the Membership & Loyalty card on `/settings`:

| Key | Default | Read by |
|---|---|---|
| `member_expiry_alert_window_days` | 14 | `MemberDashboardAction` — the expiring-soon window; `MemberExpiryBroadcastAction` — the size of the queued run |
| `member_number_prefix` | `MBR` | `Member::nextMemberNumber()` — prefix of a generated number |
| `member_points_expiry_date` | `''` (never expire) | `MemberExpirePointsAction` |
| `member_points_earn_amount` | 50000 | `MemberAdjustPointsAction::fromPurchase()` |
| `member_points_earn_value` | 1000 | `MemberAdjustPointsAction::fromPurchase()` |

The money unit itself — `currency_code` — is **not** one of them. It is a global display
choice, so it sits in the Localization card next to the system timezone and belongs to no flag:
switching membership off must not stop an operator from setting how money is written. It is
listed here because `App\Support\Money` is what reads it.

Every whole multiple of the amount earns that many points; one point is worth Rp 1 in the
manual path. Either key at 0 switches earning off, and a `purchase_amount` then refuses
with a field error rather than crediting nothing. A partial step is not rounded up.
`member_points_expiry_date` ships empty on purpose: a date nobody chose would zero every
balance. `member_number_prefix` counts up from `000001` for a prefix it has not issued
before, so switching prefix starts a new series and leaves every existing number alone.
`currency_code` is validated `exists:currencies,code` and the rule is nullable so a payload
that shows only some groups still saves — the Action then lands on `IDR` rather than writing a
blank, which would print amounts with no symbol at all. Choosing a currency relabels and
re-separates existing numbers; it never re-prices them.

## Demo data

`database/seeders/MemberDemoSeeder.php` — ten known accounts `member1`…`member10`
(`memberX@member.com`, shared dev password `#Password123`, verified emails, the `member`
role, a random seeded tier each), laid out so every dashboard state is always on screen:
member1-3 active with the period far away, member4-6 inactive (user and profile both off,
period `expired`), member7-8 expiring inside 14 days, member9-10 inside 7 — the two window
bands the alert widget and the *Notify Expiring Members* run address. Run with
`php artisan db:seed --class=Database\Seeders\MemberDemoSeeder`; it calls `MemberTierSeeder`
itself and the `member` role must exist first (`db:seed --class=RoleSeeder`). Re-running
repairs a partial run without duplicating rows. It is **not** wired into `DatabaseSeeder` —
demo rows must never land in a production seed. It creates users directly instead of through
`MemberCreateAction`, which would mail ten inboxes and fill the audit trail with noise.

## Not in this slice (Phase 19 / later P18 rows)

Points spent or applied as a discount at a transaction, the cashier UI, a printable points
receipt, and the tier `point_multiplier` interaction. Subscribe and cancel are **not**
on that list any more: `members.subscribe` / `members.subscriptions.cancel` (and
their API twins) issue and end a period from the member page, which is the only subscribe path
this slice has — the cashier still has none. Neither is the expiry broadcast: the dashboard
button, its API twin and both sweeps ship with Group C.

## Tests

`tests/Feature/Member/` — `MemberWebTest` (routes, gates, flag, sidebar labels, exactly one
active sidebar item per page),
`MemberUiRenderTest` (fixture render, null-URL affordance removal, no DB/route/inline script
in Blade), `MembershipDashboardTest` (the analytics read: gate + flag, real rows over HTTP,
each range reading its own window, a member counted once, paused periods excluded, the
renewal and at-risk figures sabotage-verified, cache hit answering with zero queries and
primitive-only leaves, the reader's keys matching `bustCache()`'s, a bad range refused before
the query, and the read time measured rather than promised), `MemberSelfDashboardTest` (the member's own `/dashboard`: tier + expiry + ledger of
the signed-in profile only, empty states with no period and no ledger rows, flag off → the
application dashboard, a staff user unaffected), `MemberCreateTest` (account + role + mail + refusal paths), `MemberPointsTest`
(ledger, earn rule, negative-balance refusal, annual reset), `MemberTierTest` (tier create /
update / delete routes, one audit row each, the superadmin refusal, the slug generated from the
name on both paths, the Danger Zone on the edit page only, formatted prices, seeder idempotency, the in-use delete refusal, a trashed
slug still taken, gates and flag), `MemberDeleteTest` (the trashing itself: `members.delete`
trashes, `members.update` alone is refused, the account survives, the flag switches it off),
`MemberTrashTest` (what each tab shows and hides, the tier trash tab that used to be unreachable, the
two-step guard on a live row, the cascade a force delete takes with it, the per-action permission
split, and the bulk path on both lists — including a mixed selection and a batch aborted by one
in-use tier), `MemberStateTest` (the activate/deactivate pair on both lists: the tier switch and
its audit row, a trashed tier unreachable from the state routes, `members.update` alone refused,
pause versus forfeit, the reason requirement, the paused days handed back on reactivation, the
Inactive tab and the icon+tooltip affordances), `MemberSubscriptionTest` (the engine Actions and
the two routes on top of them: the role copied per row, the second-live-period and retired-tier
refusals, cancel revoking only what that row applied, `members.view` alone refused on both POSTs,
and the detail page's `subscribeUrl` / `tierOptions` / `cancel_url` across no period → live →
cancelled), `MemberExpirySweepTest` (the sweep: expired + role revoked + `SYSTEM` audit, a role
another live period still needs survives, paused rows untouched, a second run claims zero,
window rows untouched, the flag stops it), `MemberExpiryNotificationTest` (the broadcast: the
saved window is read at trigger time, `members.update` alone refused — notify is its own gate
(D-6), the API twin queues the same run and 403s with the flag off, one message per member not
per subscription, the earliest expiry wins, nothing sends while the flag is off, and 1 000
members become 1 000 queued mail deliveries without this process opening SMTP).
`tests/Feature/Api/V1/Member/` — `MemberApiTest` and `MemberTierApiTest`: the read shape, the
per-route `can:` loop (a zero-permission caller 403s on every address), the write paths, the
trash two-step, the per-action bulk permission, and the flag off → 403 for a superadmin. Cross
channel: `ChannelParityTest` declares each capability on both sides, and
`ResponseParityTest::test_members_index_returns_the_same_facts_on_both_channels()` /
`test_member_tiers_return_the_same_facts_on_both_channels()` pin the shared rows and the field
sets each channel is allowed to add.
