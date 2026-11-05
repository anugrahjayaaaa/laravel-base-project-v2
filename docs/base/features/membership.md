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
| Backend web | Shipped for the reads + member creation + manual points + tier CRUD: `Web/V1/{MemberController,MemberTierController,MemberDashboardController}` |
| Backend API | **Not built.** No `Api/V1/Member`, no resources, no Scramble. `ChannelParityTest` holds the thirteen web routes as the declared one-sided capability `membership screens` until the twins land |
| AdminLTE UI | `resources/views/pages/members/{index,create,show,tiers,tier-form,dashboard}.blade.php` |

The API is not independently usable yet — that is P18-B6's remaining half, not a gap in
the web slice.

## Data model

Four tables (`P18-B1`): `member_tiers`, `members`, `member_subscriptions`,
`member_point_histories`. `members.user_id` is a **unique** FK — a member is a profile on
`User`, never a second identity (open decision O-1, closed 2026-11-05). `Member`,
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
| `GET /members/create` | `members.create` | `members.manage` |
| `POST /members` | `members.store` | `members.manage` |
| `GET /members/tiers` | `members.tiers` | `members.view` |
| `GET /members/tiers/create` | `members.tiers.create` | `members.manage` |
| `POST /members/tiers` | `members.tiers.store` | `members.manage` |
| `GET /members/tiers/{tier}/edit` | `members.tiers.edit` | `members.manage` |
| `PUT /members/tiers/{tier}` | `members.tiers.update` | `members.manage` |
| `DELETE /members/tiers/{tier}` | `members.tiers.destroy` | `members.manage` |
| `GET /members/dashboard` | `membership.dashboard` | `members.view` |
| `GET /members/{member}` | `members.show` | `members.view` |
| `POST /members/{member}/points` | `members.points` | `members.manage` |
| `DELETE /members/{member}` | `members.destroy` | `members.manage` |

`{tier}` resolves through the default (non-trashed) scope, so a repeat DELETE 404s instead of
writing a second audit row.

`{member}` resolves through `Member::withTrashed()` — a trashed profile is still readable,
and its write affordances disappear. The dashboard is named `membership.dashboard`, not
`members.dashboard`: the sidebar marks an entry active with `fnmatch` against `members.*`
(no pipe alternation), and a `members.` prefix would light up two entries at once. The URI
stays `/members/dashboard`.

**Views render no URLs of their own** (D-4): every `route()` is resolved by the Action and
arrives as a variable, and a **null write URL removes the affordance** rather than render a
button that 404s. Delete is live (`members.destroy`); subscribe, cancel and bulk are still
unbuilt, so those URLs are `null` today and their buttons are absent — which is the contract
working, not a missing feature. The detail page links the profile to the account behind it
(`user_url`, shown only to a viewer who holds `users.view`).

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

**`members.manage` alone is a 403 here.** Handing out a role is its own permission
(`users.assign_roles`, enforced by `RoleAssignAction`), so an operator who cannot assign
roles cannot create login accounts. Both halves are pinned by `MemberCreateTest`.

## Trashing a member

`MemberDeleteAction` soft-deletes the loyalty profile and nothing else: the login account
stays, and is deleted on the users screen — leaving the programme is not the same decision
as revoking access. One `member.deleted` row, written inside the transaction, carrying the
`member_number` captured before the delete. `members.destroy` is `members.manage` + the
`member_loyalty` flag, and a DELETE for a row already in the trash writes no audit row.
Restore is not built (no route, and `bulkUrl` on the index is still `null`), so a trashed
profile is un-trashed in the database for now. Proven by `MemberDeleteTest`.

## Tiers

Three screens, the shape the users feature uses: a read-only list (`pages/members/tiers`),
one form page that serves create and edit (`pages/members/tier-form`), and a row delete
through the shared confirmation modal. The per-row inline editor Group A shipped is gone —
the list now prints values and links each row to `/members/tiers/{id}/edit`.

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

`AdjustMemberPointsAction` posts one signed movement, in two mutually exclusive modes
(`MemberPointsAdjustRequest::after()` refuses giving both, and refuses a zero delta):

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

`ExpireMemberPointsAction` (driven by `MemberPointsExpirySweepJob`, queue `sweeps`, daily at
00:00, `withoutOverlapping`) reads `member_points_expiry_date` and does nothing when it is
empty or still in the future. When it is due it chunks members with a positive balance
(500 at a time), zeroes the balance, appends one `Annual points expiry` ledger row per
member, audits `member.points_expired` with `properties.causer = SYSTEM`, then advances the
setting to the next anniversary strictly after today — so the stored value is always the
*next* reset. **Only `points_balance` is touched; no member's subscription, tier or
account is ended by this job.**

The job refuses to run when the flag is off (it logs `reason=feature_off`) — the flag is the
kill switch for the sweep as well as the screens.

## Settings

Five keys, all gated on `member_loyalty`'s `settings` group in `config/pennant.php` and
rendered in the Membership & Loyalty card on `/settings`:

| Key | Default | Read by |
|---|---|---|
| `member_expiry_alert_window_days` | 14 | `MemberDashboardAction` — the expiring-soon window |
| `member_number_prefix` | `MBR` | `Member::nextMemberNumber()` — prefix of a generated number |
| `member_points_expiry_date` | `''` (never expire) | `ExpireMemberPointsAction` |
| `member_points_earn_amount` | 50000 | `AdjustMemberPointsAction::fromPurchase()` |
| `member_points_earn_value` | 1000 | `AdjustMemberPointsAction::fromPurchase()` |

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

`database/seeders/MemberDemoSeeder.php` — ten members (`MBR-000101`…`MBR-000110`), each on one
of the three seeded tiers in `sort_order`, a subscription whose status is **derived** from
`expires_at` (joined dates are spread back far enough that the shortest tier has already
lapsed, so the demo set shows both ACTIVE and EXPIRED without either being asserted), and a
ledger whose last `balance_after` equals the balance. Run with
`php artisan db:seed --class=Database\Seeders\MemberDemoSeeder`; it calls `MemberTierSeeder`
itself and the `member` role must exist first (`db:seed --class=RoleSeeder`). It is **not**
wired into `DatabaseSeeder` — demo rows must never land in a production seed. It creates users
directly instead of through `MemberCreateAction`, which would mail ten inboxes and fill the
audit trail with noise.

## Not in this slice (Phase 19 / later P18 rows)

Points spent or applied as a discount at a transaction, the cashier UI, a printable points
receipt, the tier `point_multiplier` interaction, subscription activate/cancel, the expiry
broadcast, bulk actions, member restore, and every API twin.

## Tests

`tests/Feature/Member/` — `MemberWebTest` (routes, gates, flag, sidebar labels),
`MemberUiRenderTest` (fixture render, null-URL affordance removal, no DB/route/inline script
in Blade), `MemberCreateTest` (account + role + mail + refusal paths), `MemberPointsTest`
(ledger, earn rule, negative-balance refusal, annual reset), `MemberTierTest` (tier create /
update / delete routes, one audit row each, the superadmin refusal, formatted prices, seeder
idempotency, the in-use delete refusal, a trashed slug still taken, gates and flag).
