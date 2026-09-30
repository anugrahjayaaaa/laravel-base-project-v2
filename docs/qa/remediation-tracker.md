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

## Verification

Evidence for each finding is recorded in the tracker row and in
`docs/planning/phase-6-rbac.md` § C1 audit notes. Full-suite runs are quoted in
`docs/planning/progress.md`.
