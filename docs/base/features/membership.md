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
| Backend API | **Not built.** No `Api/V1/Member`, no resources, no Scramble. `ChannelParityTest` holds the nineteen web routes as the declared one-sided capability `membership screens` until the twins land |
| AdminLTE UI | `resources/views/pages/members/{index,create,show,tiers,tier-form,dashboard}.blade.php` |

The API is not independently usable yet — that is P18-B6's remaining half, not a gap in
the web slice.

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
| `GET /members/create` | `members.create` | `members.manage` |
| `POST /members` | `members.store` | `members.manage` |
| `GET /members/tiers` | `members.tiers` | `members.view` |
| `GET /members/tiers/create` | `members.tiers.create` | `members.manage` |
| `POST /members/tiers` | `members.tiers.store` | `members.manage` |
| `GET /members/tiers/{tier}/edit` | `members.tiers.edit` | `members.manage` |
| `PUT /members/tiers/{tier}` | `members.tiers.update` | `members.manage` |
| `POST /members/tiers/{tier}/activate` | `members.tiers.activate` | `members.activate` |
| `POST /members/tiers/{tier}/deactivate` | `members.tiers.deactivate` | `members.deactivate` |
| `DELETE /members/tiers/{tier}` | `members.tiers.destroy` | `members.delete` |
| `POST /members/tiers/{tier}/restore` | `members.tiers.restore` | `members.restore` |
| `DELETE /members/tiers/{tier}/force` | `members.tiers.force-delete` | `members.force_delete` |
| `POST /members/tiers/bulk-action` | `members.tiers.bulk-action` | per action (see below) |
| `GET /members/dashboard` | `membership.dashboard` | `members.view` |
| `GET /members/{member}` | `members.show` | `members.view` |
| `POST /members/{member}/points` | `members.points` | `members.manage` |
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

**Removing is its own permission, three ways** — `members.delete` (to the trash),
`members.restore`, `members.force_delete` — the way users and roles already split them.
`members.manage` edits and does not remove. The bulk endpoints carry no `can:` on the route:
`BulkMemberRequest` / `BulkMemberTierRequest` use the shared `AuthorizesBulkAction`, which maps
the body's `action` to `<prefix>.<suffix>` — so `delete` needs `members.delete` and a tier bulk
call is authorised by the same set as a member one (tiers share the `members` prefix). An action
with no matching permission (e.g. `lock`, which only users have) is a 403, not a fallthrough.
Each handler's `getValidItems()` narrows first: `delete` acts only on live rows, `restore` and
`force_delete` only on trashed ones, so a mixed selection touches what it may and ignores the
rest.

**Switching a row off is a permission pair of its own** — `members.activate` /
`members.deactivate`, one pair for both lists, on the `users.activate` / `users.deactivate`
shape. Both lists render them the way the users row does: icon only, the action name on hover.

- **Tier** — `member_tiers.is_active`, the same column the tier form's `Active` switch writes.
  New subscriptions stop; rows already subscribed to the tier keep running, which is exactly the
  case `MemberTierDeleteAction` refuses to trash.
- **Member** — `members.is_active`, the profile's own switch, independent of the subscription's.
  `MemberDeactivateAction` asks one question, and the modal carries it: *is this a violation?*
  - violation → the live subscription goes `cancelled`. The paid period is forfeit by the
    decision taken here, and `reason` is mandatory (`MemberDeactivateRequest`) because a
    sanction nobody wrote down is not a record.
  - not a violation → `member_subscriptions.paused_at` freezes the clock. `status` and
    `expires_at` stay put, so `MemberActivateAction` adds the paused span back and hands over
    the days that were bought and never used.
  Either way `member.deactivated` carries `last_tier`, `points_balance`, `remaining_days`
  (rounded up: part of a day lost is a day lost), `violation` and `reason` — the state to
  rebuild from, because coming back in is a fresh decision, not an undo. Activate issues no
  subscription: the member buys the next period themselves or at the cashier.

Neither route touches the login account — that is `users.deactivate`, a different permission on a
different table. The violation switch and reason field live in the shared confirmation modal
(`layouts/partials/modals/confirmation.blade.php`), hidden **and disabled** until a trigger asks
for them: `x-ui.confirm-action` with `:deactivation-fields="true"` emits
`data-deactivation-fields`, and the driver enables the block per trigger. Inside an enabled block
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
button that 404s. Trashing, restore, force-delete and the bulk bar are live; subscribe and
cancel are still unbuilt, so those URLs are `null` today and their buttons are absent — which is
the contract working, not a missing feature. The detail page links the profile to the account
behind it (`user_url`, shown only to a viewer who holds `users.view`).

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

**`members.manage` alone is a 403 here.** Handing out a role is its own permission
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
one route with one Action. Both cards sit behind `@can('members.delete')`, not `members.manage`.

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

`MemberExpirePointsAction` (driven by `MemberPointsExpirySweepJob`, queue `sweeps`, daily at
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
receipt, the tier `point_multiplier` interaction, the subscribe path (issuing a new period from
the member page — `subscribeUrl` and `cancel_url` in `MemberShowAction` are still null; a
deactivation pauses or forfeits the period a member already holds, it never issues one), the
expiry broadcast, and every API twin.

## Tests

`tests/Feature/Member/` — `MemberWebTest` (routes, gates, flag, sidebar labels, exactly one
active sidebar item per page),
`MemberUiRenderTest` (fixture render, null-URL affordance removal, no DB/route/inline script
in Blade), `MemberCreateTest` (account + role + mail + refusal paths), `MemberPointsTest`
(ledger, earn rule, negative-balance refusal, annual reset), `MemberTierTest` (tier create /
update / delete routes, one audit row each, the superadmin refusal, the slug generated from the
name on both paths, the Danger Zone on the edit page only, formatted prices, seeder idempotency, the in-use delete refusal, a trashed
slug still taken, gates and flag), `MemberDeleteTest` (the trashing itself: `members.delete`
trashes, `members.manage` alone is refused, the account survives, the flag switches it off),
`MemberTrashTest` (what each tab shows and hides, the tier trash tab that used to be unreachable, the
two-step guard on a live row, the cascade a force delete takes with it, the per-action permission
split, and the bulk path on both lists — including a mixed selection and a batch aborted by one
in-use tier), `MemberStateTest` (the activate/deactivate pair on both lists: the tier switch and
its audit row, a trashed tier unreachable from the state routes, `members.manage` alone refused,
pause versus forfeit, the reason requirement, the paused days handed back on reactivation, the
Inactive tab and the icon+tooltip affordances).
