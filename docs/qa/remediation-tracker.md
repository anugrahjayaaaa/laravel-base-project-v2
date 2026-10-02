# Remediation Tracker

Updated: 2026-09-27

| ID | Finding | Resolution | Status |
|----|---------|------------|--------|
| P5C-001 | Dual password expiration keys in readers and persistence | Canonicalized on `password_expiry_days`; legacy key removed from runtime readers and covered by cleanup migration | RESOLVED |
| P5C-002 | `password-expired` view had no route/controller caller | Added `password.expired` route, controller action, middleware exemption, redirect, and render regression test | RESOLVED |
| P5C-003 | Password expiry warning partial was dead code | Included in authenticated app layout; service data supplied by `AppServiceProvider` view composer; render regression test added | RESOLVED |
| P5C-004 | Null `last_activity_at` was ignored by inactivity policy | Uses `created_at` as the reference; `inactivity_lock_grace_enabled` controls whether `inactivity_lock_grace_days` is applied; sweep candidates include null activity; regression tests added | RESOLVED |
| P5C-005 | Request-time inactivity lock had no audit trail | Middleware records `auth.inactivity_lock.middleware` with SYSTEM properties and null `causer_id`; regression test added | RESOLVED |
| P5C-006 | Jobs used direct activity logging instead of the requested audit entry point | Both sweeps use `$this->audit()` through `AuditsSystemActivity` | RESOLVED |
| P5C-007 | Settings Web/API persistence logic was duplicated | Both controllers use `SystemUpdateSettingsAction` | RESOLVED |
| P5C-008 | Settings timezone catalog was hardcoded | Added database-backed `Timezone` model, migration, Aisense seeder, validation, and UI catalog | RESOLVED |
| P5C-009 | Sweep schedule was hardcoded daily | Minute schedule reads runtime sweep time and timezone, then dispatches both jobs | RESOLVED |
| P5C-010 | Queue/scheduler operations were not documented | Added `bin/run-workers.sh` modes and queue operations documentation | RESOLVED |
| P5C-011 | Phase 3 documentation could be mistaken for phase-level completion | Phase 3 remains IN PROGRESS; implementation breakdown is marked complete; reconciliation remains separate | TRACKING |
| VIEW-001 | `password-strength` partial called `\App\Models\SystemSetting` in a Blade `@php` block | `PasswordStrengthComposer` supplies the policy to all four callers | RESOLVED |
| VIEW-002 | `guest.blade.php` loaded `app.js` twice — an FQCN `Vite::asset()` call on the line below an `@vite` | Duplicate script tag removed | RESOLVED |
| VIEW-003 | `header.blade.php` called `Auth::user()->name`; `welcome.blade.php` read `Application::VERSION` | Composer passes `currentUserName`; the route passes `laravelVersion` | RESOLVED |
| VIEW-004 | `password_min_length` defaulted to 8 in the settings form and the update action, 12 everywhere else | Both aligned to 12, the value the seeder writes and `PasswordPolicy` enforces | RESOLVED |
| VIEW-005 | Status badge classes were defined three times and had drifted | `UserStatusEnum::badgeClass()` is the single map; the controller closure and the view-local `match()` are gone | RESOLVED |
| VIEW-006 | Avatar initials were computed by two different algorithms and the controller's copy was dead | `User::initials()`; both pages call it. One name → 2 letters, two → initials, three or more → first + last | RESOLVED |
| VIEW-007 | Roles and the identity-change policy were threaded through three controllers | `AccountOptionsComposer` serves every page that shows those fields | RESOLVED |
| VIEW-008 | Three imports were left dead by the no-FQCN refactor, and one body still called `\Log::` | Imports removed, `\Log::` → `Log::` | RESOLVED |
| VIEW-009 | The sessions table's IP column never showed an IP — `personal_access_tokens` records none | The cell says so instead of printing `Never` under an address heading | RESOLVED |
| VIEW-010 | 10 `components/ui/*` Blade components (211 lines) are never invoked — `grep '<x-'` returns nothing | `confirm-action` adopted: all 9 triggers across users/index, users/edit, sessions. The other 7 are still unused | OPEN |
| P6C1-001 | A `ValidationException` from a button-driven action was never rendered — the error bag had the message and no view printed it, so the browser returned to a byte-identical page | `layouts/partials/alerts.blade.php` included by both index views (not the layout, so form pages do not double-print). `ActionErrorVisibilityTest` | RESOLVED |
| P6C1-002 | `RoleDeleteAction` refused a populated role but no UI path could ever pass `force`, so clicking Delete on a held role did nothing | Web `destroy` and the bulk bar now force — the confirm modal is the deliberate override. The action guard still protects API/console | RESOLVED |
| P6C1-003 | The roles trash tab rendered differently from the live tab: `table-secondary` (an unthemed Bootstrap class, the only use in the app) painted a fixed light `#e2e3e5` on the dark surface, and the trashed badge used the subtle component where users uses a solid one | Both now use the users-index markup byte for byte. `the_trashed_row_treatment_matches_the_users_index` compares the two views rather than restating the class | RESOLVED |
| P6C1-004 | Spatie's `deleting` hook skips `detach()` on a soft delete, so trashing a role would revoke nothing and a restore would silently re-grant | `RoleDeleteAction` detaches explicitly inside its transaction; pinned by `test_trashing_a_role_revokes_it_from_every_user_holding_it` | RESOLVED |
| P6C1-005 | `BulkRoleRequest::authorize()` returned `true` — a user holding only the `user` role could bulk-trash a role (measured: 302, role confirmed soft-deleted) while the single-row delete correctly refused. P6-C17's pattern, arrived with C1's bulk work | `BulkRoleRequest` and `BulkUserRequest` now share the `AuthorizesBulkAction` trait: one `BULK_ACTIONS` map, entity prefix per request, unmapped action fails closed. Pinned by `a_role_bulk_action_requires_its_own_permission` | RESOLVED |

### C4 gate re-audit (2026-09-30, measured, not read off the plan)

A throwaway probe (`RefreshDatabase`, role with **no** permission, `Sanctum::actingAs($nobody, ['*'])` for the API side) posted to every ungated user write route and checked the row afterwards. A `302` alone proves nothing — these are `back()` redirects, so the side effect is the evidence.

| ID | Finding | Evidence | Fix | Status |
|----|---------|----------|-----|--------|
| P6C4-001 | `users.restore`, `users.force-delete` (web **and** api) carry no `can:` and no request-level check | zero-perm user → 302 web / 200 api; `deleted_at` cleared, row permanently gone. `UserPolicy::restore()`/`forceDelete()` exist but nothing calls them | `->can('users.restore')` / `->can('users.force_delete')` on both files | OPEN — P6-D1/D2 |
| P6C4-002 | The four state routes (`activate`/`deactivate`/`lock`/`unlock`) are ungated on both files | zero-perm user flipped `is_active`/`is_locked` on other accounts, 302 web / 200 api. `UserPolicy`'s state methods are dead code for this path | `can:` per route in `routes/web.php:110-113` and `routes/api.php:70-73` | OPEN — P6-D1/D2 |
| P6C4-003 | `users.resend-verification` is ungated | zero-perm user triggered a verification mail for another account, 302, no ownership check | owner-or-`users.update`, plus throttle (already present) | OPEN — P6-D1 |
| P6C4-004 | `users.cancel-email-change` is ungated and has no ownership check | zero-perm user cleared another account's `pending_email`, 302 | owner-or-`users.update` | OPEN — P6-D1 |
| P6C4-005 | `api.v1.settings.index` is ungated (the web twin is gated) | zero-perm token → **200** with the settings payload; `settings.update` correctly 403s via `SystemSettingRequest` | `->can('settings.view')` on `routes/api.php:64` | OPEN — P6-D2 |
| P6C4-006 | `roles.store`, `roles.update`, `roles.destroy`, `roles.restore`, `roles.force-delete` have no `can:` on the route | not exploitable — `StoreRoleRequest`/`UpdateRoleRequest`/`DeleteRoleRequest`/`RestoreRoleRequest`/`ForceDeleteRoleRequest` each check their own `roles.*` permission | route gate is defence in depth only | OPEN (cosmetic) — P6-D1 |

P6C4-001..005 are the seven-plus ungated user routes the plan already tracks as P6-D1/D2; C4's own five tasks are complete and none of these are C4 scope.

### Action-first audit migration (2026-10-02, measured by grep + read, not read off the plan)

The Action-First Audit Logging Standard (`docs/base/architecture/application-boundaries.md`
§ Action-First Audit Logging Standard) puts the audit write inside the Action that
performs the mutation. `UserDeleteAction` was migrated and proved out. These rows
are what the migration has NOT reached yet.

Counted with `rg -n 'audit\(|bulkAudit\(' app/Http app/Jobs app/Services` and
`rg -c 'audit\(|activity\(' app/Actions/V1/*/*.php`. Every action named below was
opened and confirmed to contain zero audit writes.

**Not a double-write defect.** Each of these is a *placement* defect, not a
duplicate record: the controller writes the row after the action returns, so the
record is correct today but sits outside the transaction (a rollback after the
action commits leaves a record claiming a change that was undone) and no non-HTTP
caller of the action (job, command, console) is covered at all.

| ID | Finding | Resolution | Status |
|----|---------|------------|--------|
| AUD-001 | `Web/V1/UserController` writes 8 audit rows the action already owns: `user.created`, `user.updated`, `user.restored`, `user.force_deleted`, `user.verification_resent`, `user.email_change_requested`, `user.email_change_cancelled`, `user.email_changed` | All 8 moved into their action, each inside its `DB::transaction`; controller calls deleted. `user.created` and `user.updated` are gated on `$causer !== null` — see AUD-009 | RESOLVED |
| AUD-002 | `Api/V1/User/UserController` mirrors all 8 of AUD-001's rows | Landed with AUD-001 in the same pass; both channels verified by `ActionFirstAuditTest` | RESOLVED |
| AUD-003 | `Web\|Api/V1/UserStateController` — 4 rows each (`user.activated`, `user.deactivated`, `user.locked`, `user.unlocked`), behind `UserActivateAction` / `UserDeactivateAction` / `UserLockAction` / `UserUnlockAction` | All four moved, sharing one `AuditsUserState` trait so `target_id`/`target_email` cannot drift apart. `$causer` added to `UserActivateAction`/`UserLockAction`/`UserUnlockAction` (`UserDeactivateAction` already had one) | RESOLVED |
| AUD-004 | `Web/V1/UserController::bulkAction` writes aggregate `user.{action}` rows via `bulkAudit()` for the 6 actions that mutate columns directly (lock, unlock, activate, deactivate, restore, force_delete) | AUD-001/003 landed, so `getAuditEvent()` now returns `''` for `delete`/`restore`/`force_delete`/`deactivate` — those four loop an auditing action, so an aggregate row would name the same subjects twice. `lock`/`unlock` keep it: `lock`/`unlock` later routed through `UserLockAction`/`UserUnlockAction` in AUD-012, so all six now loop an auditing action and `getAuditEvent()` returns `''` for every one | RESOLVED |
| AUD-005 | `Web/V1/SystemSettingController` + `Api/V1/SystemSettingController` write `system_setting.updated`; `SystemSettingsUpdateAction`'s own docblock asserts "Controllers remain responsible for ... audit logging" | Write moved into the action, docblock reversed. Fixing the audit placement exposed a worse defect: the ~40-key loop had no transaction at all, so a mid-loop failure left a half-applied settings change with an audit row claiming success. Wrapped in `DB::transaction`. Required adding `Auditable` to `SystemSetting`, which had no audit trait | RESOLVED |
| AUD-006 | `Web/V1/ProfileController` + `Api/V1/ProfileController` — 7 rows (`user.profile_updated`, `auth.password_changed` ×2 web, `user.email_change_requested`), behind `UserUpdateAction` / `AuthChangePasswordAction` | Move into the actions. `update()` writes three rows off one action call, so the split was decided first: `user.profile_updated` is owned by `UserUpdateAction`, the password rows by `AuthChangePasswordAction`. Both controllers now call the action and nothing else. The whole update is one `DB::transaction`, not just the audit write — a mid-write failure used to leave the first field applied and the rest not | RESOLVED |
| AUD-007 | `Web/V1/Auth/AuthController` — 12 rows (login, login_failed, account_locked, password_reset_requested, password_reset_completed, email_verified, verification_resent, user.registered, logout_all, logout) | Context (`ip`, `user_agent`, `source`) is identical on every auth event and was assembled 25 times over, so it drifted — the API wrote `source => api`, the web twin `source => web`, and only some sites carried a user agent. Context is now derived inside `Auditable::audit()` itself — one place reads the request, so both channels write the same keys with no per-domain trait. (The intermediate `AuditsAuthActivity` concern was deleted; a per-domain context builder is the second writer this was meant to remove.) `AuthLoginCompletedAction` and `AuthLogoutAction` are new — `auth.login` and `auth.logout` had no owner at all, because the session does not exist until the controller creates it | RESOLVED |
| AUD-008 | 7 API auth controllers — 12 rows (Login 4, PasswordForgot 2, VerifyEmail / PasswordReset / PasswordChange / Register / ResendVerification / LogoutAll / Logout 1 each) | Lands with AUD-007; the context builder is shared, so both channels now write the same keys from one place | RESOLVED |
| AUD-009 | `UserCreateAction` and `UserUpdateAction` have 3 callers each — the admin user screen AND self-registration / the self-service profile, which each write a *different* event. Auditing unconditionally would give one signup two rows (`user.created` + `user.registered`) and one profile save two (`user.updated` + `user.profile_updated`) | Both gate on `$causer !== null`: an administrator passes one, a self-registering user and a profile save cannot. `UserVerifyEmailChangeAction` is the deliberate exception — it audits unconditionally with `$causer ?? $user`, because a signed link means no actor is signed in, and gating there would leave the main path unaudited | RESOLVED |

| AUD-010 | `SystemSetting` caches every read under `Cache::rememberForever` and busted it per-`set()`. A transaction that writes settings and then rolls back left that cache holding the rolled-back values — measured, the cache held a password policy of 20 while the row said 12 | Two paths, both required. (1) `set()` busted on write; that moved to `DB::afterCommit`, so a rollback leaves nothing to un-bust. (2) The worse one: reading inside the transaction repopulated the shared cache from uncommitted rows, so busting after commit could not help — the offending write is on the READ path. `loadSettings()` now skips the shared cache while `DB::transactionLevel() > 0`. Found while fixing AUD-005, not introduced by it | RESOLVED |

| AUD-011 | `Controller::bulkAudit()` assembled its own context: `source` came from a `$type` parameter defaulted to `'web'`, and `ip` / `user_agent` were absent. No call site passed `$type`, so every bulk action taken through the API recorded `source => web` and no bulk row in the table carried a client IP | `Auditable::auditBulk()` now derives the same context from the same `auditContext()` as `audit()`, and the `$type` parameter is gone so no caller can reintroduce the guess | RESOLVED |
| AUD-012 | `UserBulkActionHandler` still emits aggregate events for activate / lock / unlock, and the controllers write them via `Controller::bulkAudit()` (`Web\V1\UserController:172`, `Api\V1\User\UserController:158`) | Loop the per-user state action like delete already does, return `''` from `getAuditEvent()` for those operations, and delete `Controller::bulkAudit()`. Done in `3bfa91d`: `lock`/`unlock`/`activate` loop their per-user action like `delete` already did, `getAuditEvent()` returns `''` for them, and `Controller::bulkAudit()` is deleted (zero references remain). The cost is deliberate and recorded at `UserBulkActionHandler::eachUser()`: two queries per user instead of one, because a batch `UPDATE` cannot run the guard each action applies | RESOLVED |

**Out of scope, deliberately.** `LogoutController::auth.logout` has no action behind it
— logout is request-scoped session teardown with nothing to move the write into.
`EnsurePasswordChangeRequired` and the two sweeps (`InactivityLockSweep`,
`PasswordExpirySweep`) already write through `AuditsSystemActivity`; they are
non-HTTP paths doing it correctly. `PermissionController` (both), `DashboardController`,
`HealthCheckController`, `Api/V1/SessionController` are read-only and correctly
have no audit.

**Already compliant.** Role (5 actions incl. the shared `PersistsRole::persist`),
User delete, and both Feature toggle actions audit inside their transactions.
`RoleController`, `Api/V1/Role/RoleController`, `FeatureController`,
`PermissionController` return zero audit calls from `rg` — verified, not assumed.

**Suggested order.** AUD-005 (2 sites, unblocks a contradicting docblock) → AUD-001/002/003
(User, mechanical, `user.deleted` is the template) → AUD-007/008 (Auth) → AUD-006 (Profile).
Each batch needs a count assertion (`=== 1`, never `->exists()`) plus a rollback test, per
`tests/Feature/Audit/ActionFirstAuditTest.php`.

**Audit context.** `source`, `ip` and `user_agent` are derived inside
`Auditable::audit()` for every row, on every caller — there is no per-domain
context trait. Callers pass only event-specific properties (`identifier`,
`lock_duration_seconds`, `remember`) and may override `source` when there is no
request (a job passes `system`). The `channel` key is retired: it duplicated
`source` under a second name, so a row could answer "where did this come from"
only half of the time. An action that finds no subject writes no row at all —
`failed_login_attempts` and `password_reset_tokens` already hold that fact keyed
by identifier, and a subject-less `activity()` row would need a second
implementation of the context.

**Events with no state change.** `auth.login_failed`, `auth.account_locked`,
`auth.verification_resent` and `user.registered` describe a request, not a
mutation, so there is no transaction to write inside. They are recorded by the
action that handles them, outside a transaction — forcing one would buy nothing,
because there is no state that could roll back and leave an orphan row.

This list was originally wider and wrong: it also excused
`auth.password_expiry.sweep` and `auth.login`, both of which DO mutate state —
the sweep sets `must_change_password`, and login writes a token plus
`last_activity_at`. Both were unguarded two-statement sequences and are now
inside a `DB::transaction` with their audit row (`789e09f`). The test is not
'no visible form field', it is 'writes no row outside activity_log'.

`auth.password_reset_requested` is not audited at all: the endpoint answers the
same way whether or not the address is an account, and `password_reset_tokens`
already records the ones that were real.

## Follow-up audit (2026-10-02)

A second pass, after the AUD-001..012 rows above were all closed. Recorded here
because these are defects the original table could not have listed.

### Transaction coverage gaps — RESOLVED (`789e09f`)

Two mutating paths wrote their audit row as a separate statement with nothing
between the two writes, so a failure between them left state applied and the
audit row missing (or the reverse). `ActionFirstAuditTest` now pins this with a
data provider that asserts BOTH the row count and the rolled-back state — one
assertion catches a missing row, the other catches a row written for a change
that did not happen.

| File | Was |
|---|---|
`Jobs/PasswordExpirySweep` | `update(['must_change_password' => true])` then `audit()` |
`Actions/V1/Auth/AuthLoginCompletedAction` | `createToken()` + `updateQuietly()` + `audit()`, three writes, no transaction |

`Auth::login()` deliberately stays OUTSIDE the transaction: it is session state
flushed when the response is sent, so a rollback cannot un-login a user.

### A security file that never ran — RESOLVED (`8516f0b`)

`tests/Feature/PasswordHistoryPentest.php` did not end in `Test.php`, so PHPUnit
never discovered it. Fifteen security assertions had never run in a full suite —
which is why `php artisan test` was green while the file failed when named
directly. Renaming it took the Feature suite from 825 to 840 tests.

Three of those fifteen were also wrong, and in the direction that hides things:
they acted as a role-less user against `settings.update`, which is behind
`->can('settings.manage')`, so every POST got a 403 before validation ran and
`assertSessionHasErrors` read a response that had never seen the payload. They now
post as an admin, and assert the STORED value stayed put rather than only that
an error bag appeared — a run that warned and then persisted anyway would have
passed the original.

Lesson: a security test that cannot fail is documentation. Both the bounds
assertions were mutation-verified (drop `min:0`/`max:24`, both fail).

### Unbounded bulk selections — RESOLVED (`ecdfc2c`, `287f5bd`)

`BulkUserRequest` and `BulkRoleRequest` accepted any array length. The web bulk
bar cannot reach past one page — `resources/js/helpers/bulk-actions.js` scopes
select-all to the rendered `<table>` — but the API shared the request, so one
authorised caller could post thousands of ids and take a row lock per id inside a
single transaction. Both now cap at 10, pinned to the index page size so the two
cannot drift.

Roles has no API bulk route, so that cap closes the web channel only.

### Bulk is 2 queries per user, on purpose — NOT a defect

`lock`/`unlock`/`activate` went from one batch `UPDATE` to a per-user loop in
`3bfa91d`. Measured: ~2.0 queries per user at n=100, linear. This is the price
of running each action's guard, which a batch `UPDATE` cannot do — a raw update
would lock an inactive user that `UserLockAction::validate()` refuses. The
alternative (`auditBulk()`) saves the INSERT but not the per-user UPDATE, and
costs a second mutation path. At the 10-row cap this is not worth changing.

### API / web parity

19 routes are shared and symmetric, including every state-changing user and role
operation — both channels call the same actions, so the audit rows and guards
cannot drift. Web-only routes that are NOT gaps: `*/create` and `*/edit` are HTML
forms; `POST /api/v1/users` and `PUT /api/v1/users/{user}` already cover them.

The auth URL sets differ (`forgot-password` vs `auth/password/forgot`) but the
capability is identical on both channels.

Two real gaps remain, both deliberate and both out of scope at the time of
writing:

- **No API `index` for feature flags.** The catalogue is eight slugs of config
  and a caller cleared to toggle a flag can name the one it wants.
- **No API `roles/bulk-action`.** User bulk exists on both channels; roles bulk
  is web-only.

## Verification

Evidence for each finding is recorded in the tracker row and in
`docs/planning/phase-6-rbac.md` § C1 audit notes. Full-suite runs are quoted in
`docs/planning/progress.md`.
