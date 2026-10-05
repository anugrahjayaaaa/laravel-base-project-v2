# Progress

> Allows a new AI session to understand project state without reading the entire conversation.

## Current Phase

| Phase | Title | Status |
|-------|-------|--------|
| 0 | Architecture & project conventions | DONE |
||| 1 | Laravel foundation & environment | DONE |
|| 2 | Database foundation | DONE |
||| 3 | Authentication foundation | IN PROGRESS (implementation breakdown DONE; phase status not yet reconciled) |
||| 4 | User lifecycle & user management | DONE |
||| 5 | Password/security lifecycle | DONE — Groups A, B, and C verified |
| 6 | RBAC & authorization | IN PROGRESS — Groups A (UI) and B (permission set + seeders) DONE; C–E pending |
| 7 | Feature availability / feature flags | DONE — Groups A–F. The kill switch is real: a flag off 403s its routes (62/62 across web + API) and drops its menu item, with no superadmin bypass |
| 8 | Settings | DONE — Groups A–E closed 2026-10-03 on `feature/phase-8-settings`. Groups A–D were already shipped by Phase 4F/5 (`SystemSettingRequest`, `SystemSettingsUpdateAction`, the two-column page, `feature:settings` on web + API, `@can` read-only, audit inside the transaction). Group A fixed four design-system defects (error messages invisible on 18 of 22 fields — `input-group` breaks Bootstrap's sibling selector) and added a render gate. Group E closed both gaps: `P8-E1a` proves all 40 numeric bounds reject out-of-range, `P8-E1b` proves the API audit row and partial-key semantics. Added since: `SettingsBenchmarkTest` (read path healthy at 1 query; save is 39 constant queries, **not** an N+1 — scales with whitelist length, not rows) and `SettingsPentestTest` (23 adversarial tests — authorization, role escalation, policy sabotage, unknown-key injection, mass assignment, audit integrity — all pass). Full suite **1015 passed / 3874 assertions**. See `phase-8-settings-management.md` |
| 9 | Notification/mail/queue | IN PROGRESS — Group A DONE (UI), D1/D2/D4 DONE (permission + flag + menu), **Group B DONE** (mail transport writes, credential encrypted at rest, config rebound after commit, test-mail probe, API twin). C and E partially done. The transport now lives in `SystemSetting` (8 keys) with `.env` as the fallback; `mail_password` is `encrypt()`ed on write and only written when a new one was submitted, so the blank field keeps the stored credential. `bindMailConfig()` is called from the action after commit, not from boot — boot runs before the settings table exists. Groups A+D1/D2/D4 + B tests: `NotificationUiRenderTest` (19), `NotificationAccessTest` (14), `NotificationSettingsTest` (19). **One real bug found and fixed while writing the B tests**: `SystemSetting::getString($key, config(…))` with an unset `MAIL_USERNAME` passes `null` into a `string` parameter, the TypeError landed in `bindMailConfig()`'s catch, and **not one key was bound** — the save returned 200, the row was written, and the transport kept its `.env` values. Every fallback is now cast, the catch logs instead of swallowing, and `an_install_without_a_configured_username_still_binds` reproduces it. Full suite **1089 passed / 4197 assertions** (5 risky pre-existing). See `phase-9-notifications-mail.md` |
| 10 | Audit Trail | ARCHITECTURE DONE — action-first standard shipped and reconciled across User, System, Role, Feature and Auth (web + API), with one audit entry point (`Auditable::audit()`) that captures `source`/`ip`/`user_agent` itself. AUD-006 (Profile) still migrates |
| 11 | Monitoring/observability | PLANNED |
| 12 | API V1 | PLANNED |
| 13 | Security hardening | PLANNED |
| 14 | Storage/backup/retention | PLANNED |
| 15 | Comprehensive testing | PLANNED |
| 16 | Documentation verification | PLANNED |
| 17 | Full regression / final review | PLANNED |

## Current Task

Phase 7 — Feature Availability & Feature Flags: DONE
**Both sides ship, and both were verified by running the app, not by reading the diff.**

- Group A (UI): ✅ DONE (`9545bce`) — index view, metric strip, grouped table, `feature-toggle` component, render gate
- Group A audit: ✅ DONE — `align-middle` on all five `<th>`, and `ConfirmActionUsageTest` extended to see the `<input>` switch (it matched `<button\b` only, so the switch was never checked at all). Both sabotage-verified. Closed P7-E3 early
- Group B (catalogue + activation): ✅ DONE (`b72a5f6`) — `config/pennant.php` (8 flags), `FeatureCatalog`, `FeatureFlagSeeder`, global scope
- Group B audit: ✅ DONE — seeder non-destructiveness proven by running it (operator's `pulse=off` survived a reseed, row count held at 8), `stores` block diffed IDENTICAL against vendor, `isActive()` confirmed the only reader. Two non-defect gaps recorded: `disabled => true` is unused until routes are gated, and the slug count is deliberately unpinned. Closed P7-B7
- Group D1–D6 (permissions, index/toggle actions, controller, web routes, sidebar item): ✅ DONE (`e538c49`)
- Group C (enforcement middleware): ✅ DONE — `EnsureFeatureIsEnabled` (403, no `features.manage` bypass) + the `feature:` alias in `bootstrap/app.php`; 9 tests, three sabotages verified. Pennant's own middleware could not be aliased: it aborts 400, and it resolves through `Feature::active()` so a `disabled => true` kill switch reads as active to it (measured) — status revised 404 → 403 on 2026-10-01
- Group D7–D10 (route matrix + menu gate): ✅ DONE — 62/62 module routes gated (33 web, 29 API), menu filters on the flag before the permission, `FeatureFlagMenuTest` 9 tests. The first pass shipped a partial gate (bulk-action, state toggles, email-change left outside) and was caught by walking `gatherMiddleware()` at runtime, not by reading the diff
- Group F (bulk feature actions): ✅ DONE (`0481541`, `f8d23f3`) — `FeatureBulkToggleAction` (one transaction, one `feature.bulk_toggled` row, every `from` read before the first write), `features.bulk-action` behind one `features.manage` gate rather than `AuthorizesBulkAction`, `#bulkBar` with `data-bulk-mixed="disable_feature"`, and 4 tests that run the real Vite bundle — after the dropdown shipped empty once with 800+ green tests
- Group E (tests + docs): ✅ DONE — E1/E2/E4/E6/E7 closed as planned; E3 closed in the Group A audit; **E5 found a real N+1** (`resolve()` called `isActive()` per slug — 2/4/8 queries for 2/4/8 flags), fixed by `FeatureCatalog::activeMap()` reading the set in one `WHERE name IN (...)`. Sabotage-verified

**Follow-up audit (2026-10-02), all fixed:**
- `bulkAudit()` left the `event` column NULL while writing `description`, so every bulk user row (7 actions × web + API) was invisible to any `where('event', …)` filter. One line in the shared helper; also gives the batch a `batch_uuid`
- `pulse` was declared but not gated: `/pulse` served **200 with the flag off**. Now gated through `pulse.middleware`, the vendor's own extension point
- the `SNAPSHOT` cache key was duplicated in 4 files behind docblocks claiming "a rename has to break a compile" — false for PHP string constants. One `public const` on the reader, referenced by all three writers
- the docs told operators to activate flags via `tinker`, which writes the store row without forgetting the resolved snapshot — `/features` then shows stale state for 30s and a `features.view`-only user cannot self-heal
- `translations` and `activity_logs` control nothing yet; the page now says so instead of letting a switch that changes nothing borrow the confidence of one that does
- a report that the API role surface was unaudited was **wrong** — all 5 mutations audit inside their actions. `ApiRoleAuditTrailTest` now proves it by running the routes, so the question does not get re-litigated by the next grep

**Known and accepted:** `translations` and `activity_logs` gate nothing until
Phase 8 builds their routes — the page labels them rather than pretending. Both
menu entries are already wired to their flag so they start hiding the moment the
routes land.

Full detail: `docs/planning/phase-7-feature-flags.md`.

---

## Previous Task

Phase 6 — RBAC & Authorization: COMPLETED / CLOSED (2026-09-30)
Every gate closed. Full suite 661 passed / 2243 assertions, 0 regressions; pint clean; assets build clean.

One live vulnerability was found and fixed during Group E rather than confirmed absent: the last superadmin could be
removed by DEACTIVATING or DELETING the account, not only by stripping the role — three sibling actions that never
asked. The bulk deactivate compounded it with a raw `UPDATE` that bypassed the action entirely. See
`docs/planning/phase-6-rbac.md` § Adversarial and the E4 notes in `task-tracker.md`.
- Group A (UI): ✅ DONE (2026-09-28, audited)
- Group B (permission set + seeders): ✅ DONE (2026-09-28, verified)
- Group C1 (role management, web): ✅ DONE (2026-09-29, audited) — P6-C1..C6
- Group C2 (permission catalogue, read-only): ✅ DONE (2026-09-29, audited) — P6-C7/C8; grouping by prefix not built, open decision
- Group C3 (role assignment sync): ✅ DONE (2026-09-29, audited) — P6-C9..C12; C13 merged into `RoleAssignAction`
- Group C4 (authorization guards + policy): ✅ DONE — P6-C14..C18. Shipped in `015d6c3`: the four ungated `authorize()` returns are closed, bulk actions map to their own permission via `AuthorizesBulkAction`, and `UserPolicy` delegates the seven CRUD methods to `users.*`. Pinned by `GateCAuthorizationTest` (8 tests)

**Gate C: MET (2026-09-30, re-measured).** All four C groups ship, and the
negative case is pinned rather than assumed: `GateCAuthorizationTest` asserts a
403 for a zero-permission user on every admin read route, on user create/update
(both the other-user and the escalate-my-own-profile shapes), on both bulk bars,
and on settings write. `RoleManagementTest` pins the same for all five role
write routes. Role assignment sync is pinned four ways by
`RoleAssignActionTest` (sync, unknown-name skip, empty-clears, absent-key
untouched) plus the last-superadmin guard in both directions. Full suite: 505
passed / 1657 assertions.

**Next:** Group D (route, menu & UI gating). The seven ungated user state routes
and `api.v1.settings.index` are P6-D1/D2 — `UserPolicy` already has the methods,
nothing calls them yet. Phase report + docs are P6-E11.

**Group B delivered:**
- `App\Support\PermissionCatalog` — 19 permissions, the single source of truth
- `PermissionSeeder` — prune orphans → create → assign, cache flushed at all three points
- `Gate::before` — `superadmin` passes every check and holds 0 `role_has_permissions` rows
- `SuperAdminSeeder` now assigns the role (it never did; `Gate::before` made that a real bug)
- `admin` holds the whole catalogue; `user` holds none
- Gate: `PermissionSeedTest` 17 tests/114 assertions, `PermissionCacheTest` 5/10

**Audit vs spec** — 6 deviations recorded in `phase-6-rbac.md` § Group B,
including two that fixed real bugs: the seeder only ever added (a permission
removed from the catalogue stayed in the DB forever) and `SuperAdminSeeder`
never assigned its role.

**Group C1 delivered (2026-09-29, audited against `phase-6-rbac.md` § C1):**
- `StoreRoleRequest` / `UpdateRoleRequest` / `DeleteRoleRequest` /
  `RestoreRoleRequest` / `ForceDeleteRoleRequest` — every one carries a real
  `authorize()` checking `roles.create` / `roles.update` / `roles.delete` /
  `roles.restore` / `roles.force_delete`. Unique rules are guard-scoped via
  `RoleLookup::guard()`.
- `RoleIndexAction` — guard-scoped, `withCount`, `search` before
  `paginate(10)`, `withQueryString`, and a `trashed` flag that scopes the whole
  query to the trash rather than filtering rows.
- `RoleCreateAction` / `RoleUpdateAction` — the spec named one `SaveRoleAction`;
  implemented as two verbs sharing an `App\Actions\Concerns\PersistsRole` trait,
  which holds the `DB::transaction`, the `array_map('intval', …)` before
  `syncPermissions` (Spatie resolves a string `'19'` as a permission *named* "19"
  and throws), and the audit write **inside** the transaction per DEP-003. Split
  the verbs to match `UserCreateAction` / `UserUpdateAction` on the user side.
- `RoleDeleteAction` — refuses system roles, refuses a populated role unless
  `force`, then in ONE transaction: `users()->detach()` → soft delete → audit
  carrying `revoked_users` / `revoked_permissions`. `RoleRestoreAction` and
  `RoleForceDeleteAction` own the other two verbs. The detach is explicit
  because Spatie's `deleting` hook skips it on a non-force delete, so relying on
  the package would revoke nothing.
- `RoleController` — thin, 10 methods, resolves route data and delegates.

**Beyond the C1 spec (shipped, and now documented):**
- Soft delete + trash tab, restore, and permanent delete for roles. Not in the
  C1 table — added for the enterprise/SaaS retirement requirement.
- Bulk actions on the roles index (`POST /roles/bulk-action`), reusing
  `BulkActionProcessor` + a `RoleBulkActionHandler`, and the shared
  `bulk-actions.js` parameterised via `data-*` so users and roles share one file.
- A refused action is now **visible**: `layouts/partials/alerts.blade.php` is
  included by both index views. Previously a `ValidationException` from a
  button-driven action landed in the session with nothing rendering it, and the
  browser returned to a byte-identical page.

**`BulkRoleRequest::authorize()` blanket `true` (regression from C1, fixed by
P6-C17).** Both bulk requests now share the `AuthorizesBulkAction` trait, so the
requested action maps to its own permission and an unmapped action fails closed.

**Open (tracked, not forgotten):** the Group A GET routes still carry no
`can:` gate, which is P6-D1 by design. The role forms and delete trigger no
longer point at `roles.index` as placeholders — Group C1 gave them real
endpoints, and Group C2 closed the reads behind `can:roles.view` /
`can:roles.create` / `can:roles.update` / `can:permissions.view` once Group B had
seeded the catalogue those gates check against. The role **write** routes still
have no `can:` on the route itself; each is gated by its own Form Request
(`StoreRoleRequest` → `roles.create`, etc.), which is why
`RoleManagementTest` can assert 403 on all five. Route-level gates there are
defence in depth, tracked as P6-D1. RBAC-006 (`/users` + `/settings` ungated on
the API twin) is also still open.

Phase 5C — Password Expiration & Inactivity Lock: ✅ FULLY DONE
- Shared Web/API settings action
- Password expiry and inactivity services
- Middleware enforcement and password-expired screen
- Warning banner and settings UI
- Scheduled expiry/inactivity sweeps with minute-based configured scheduling, overlap protection, and 500-row batching
- SYSTEM audit and operational logs
- Timezone reference data and configurable sweep schedule
- Password change and reset revoke all database-backed Web sessions, Sanctum tokens, and `remember_token` atomically with the password mutation.

Phase 3 remains IN PROGRESS independently; its implementation breakdown is documented as complete, but the phase tracker still needs reconciliation.

## Next Task

Reconcile the remaining Phase 3 documentation/tracker status, then continue the next unfinished phase.

## Completed Tasks

Phase 0 (P0-001 through P0-011) — architecture documentation and planning system.

Phase 1 (FOUND-001 through FOUND-010, + CACHE-001, QUEUE-001, CORR-001, UI-001
through UI-006, SOFT-001, TABLE-001, FLAG-001) — Laravel 13 foundation,
API foundation, AdminLTE UI foundation, and feature flags:

- Laravel 13.31.0 initialized (PHP 8.3)
- `.env.example` configured: APP_NAME="Laravel Base Project", MySQL default,
  CACHE_STORE=file, QUEUE_CONNECTION=database, SESSION_DRIVER=database,
  PENNANT_STORE=database (Laravel Pennant). `PERISCOPE_ENABLED` removed — the
  package is gone (85384b4); `laravel/pulse` needs no enable key
- Config: cache=file default / Redis available; auth=web+sanctum API guard;
  queue=database default / Redis compatible
- Sanctum ^4.0 installed, API guard configured, User has HasApiTokens
- Spatie Permission ^6.0 installed, migrations + config published, User has HasRoles
- Spatie ActivityLog ^4.8 installed (Phase 10 integration pending)
- Laravel Pulse ^1.8 installed (config + migrations published), replacing
  Telescope ^5.0 and Periscope v0.3 in commit `85384b4`. The `pulse` feature
  flag gates /pulse through `pulse.middleware` (`b08b8b4`).
  See phase-11-monitoring-observability.md for the pending `pulse.view`
  permission + `viewPulse` gate override.
- Scramble ^0.13 (dev) installed
- Pint ^1.27 for PSR-12 code style, pint.json preset=psr12
- Correlation ID middleware (FOUND-008 / CORR-001): GenerateRequestCorrelationId
  registered in bootstrap/app.php, X-Request-ID response header, 3 tests
- Health check endpoint (FOUND-010): GET /api/v1/health via HealthCheckController +
  HealthCheckService + HealthCheckResource, 4 tests
- Cache config (CACHE-001): file default, Redis available
- Queue config (QUEUE-001): database default, Redis compatible
- AdminLTE 4.9.1 vendored into public/vendor/adminlte/ (UI-001, not via npm)
- Application shell (UI-002): header + sidebar + footer shared partials
- Shared UI components (UI-003): button, input, badge, alert, empty-state,
  loading-state, error-state, sortable-th, action-menu, confirm-action
- Confirmation modal (UI-004): reusable modal with danger/warning/info variants
- Theme toggle (UI-005): system default + manual override, localStorage persisted,
  no theme flash (head inline script), icon-only toggle
- UI foundation cleanup (UI-006): partials renamed (no app-* prefix), theme
  fixed, i18n removed from UI foundation, style guide updated
- Soft delete convention (SOFT-001): documented + SoftDeletes on User model
- Table conventions (TABLE-001): sortable-th component, Bootstrap pagination
- Laravel Pennant (FLAG-001): installed, features table migration published + migrated,
  @feature/@featureany Blade directives available
|- Laravel Pulse (MONITOR-001): runtime metrics dashboard at /pulse, gated by
  the `pulse` feature flag. Replaced Telescope + Periscope (`85384b4`); gate
  verified by `PulseFeatureGateTest` (3 tests)

Phase 2 (DB-001, DB-002) — database foundation:

- Base migration scaffold: Laravel defaults + Spatie permission tables (DB-001)
- RoleSeeder created + wired into DatabaseSeeder (DB-002)

Phase 4B Group B (P4-B1 through P4-B11) — User CRUD (Web UI): DONE
Phase 4C Group C (P4-C1 through P4-C6) — Activate/Deactivate/Lock/Unlock: DONE
Phase 4D Group D (P4-D1 through P4-D6) — Admin User Creation + Temp Password: DONE
Phase 4E Group E (P4-E1 through P4-E7) — API User CRUD: DONE
Phase 4F Group F (P4-F1 through P4-F13) — Username/Email Change + System Settings: DONE
Phase 4G Group G (P4-G1) — Self-Service Profile Page: DONE
Phase 4H Group H (P4-H1 through P4-H4) — Bulk Actions + Audit Close-out: DONE

Phase 5 Group A (P5-A1 through P5-A9) — Password Policy & Validation UI: ✅ DONE
- `app/Support/PasswordPolicy.php` — validate() + strength() + 5 IM8 rules
- `app/Rules/PasswordStrengthRule.php` — Laravel ValidationRule
- `resources/views/layouts/partials/password-strength.blade.php` — shared indicator
- `resources/js/helpers/password-strength.js` — vanilla JS bar + checklist
- 2 views updated: `auth/reset-password`, `profile/edit` (P5-A5 skipped — no password field)
- SystemSettingSeeder: 6 new keys (`password_min_length=12`, `password_require_*=true`)
- Tests: 13 unit + 8 feature = 21 new tests, 223 total pass
- Pentest: clean (1 LOW — homoglyph bypass, documented)
- Bonus: eye icon positioning fix (6 buttons), `bi-eye` icon class, auth layout vite fix

- UserIndexAction, UserQueryRequest, thin UserController, index + edit views
- 8 routes (users.index through users.resend-verification)
- UserDeleteAction, UserRestoreAction, UserForceDeleteAction
- ShowUserAction + edit form, UserAdminResendVerificationAction
- Tests: UserCrudWebTest + UserExtendedCrudTest + toggle status
|| Phase 4C Group C (P4-C1 through P4-C6) — Activate/Deactivate/Lock/Unlock: DONE ✅ (approved, closed) |

Architecture gap-closing pass — added:

- `docs/base/architecture/application-components.md` (component responsibility model + canonical flow + source-of-truth rules)
- `docs/base/dependencies/` (`overview.md`, `dependency-matrix.md`)
- `docs/base/governance/dependency-governance.md`
- `docs/base/architecture/decision-records/DEP-001` through `DEP-007`
- `docs/base/ui/` (`ui-architecture.md`, `ui-authorization.md`, `design-system.md`)
- Enhanced: `application-boundaries.md` (transaction/after-commit rules),
  `audit-trail.md` (transaction boundaries), `queue.md` (after-commit dispatch),
  `logging.md` (5-way observability + correlation ID propagation),
  `observability.md` (5-way classification + security logs),
  `retention.md` (security logs), `user-management.md` (lifecycle transitions +
  state distinctions), `authentication.md` (session revocation + last_activity
  policy), `rate-limiting.md` (precedence + categories + race conditions),
  `authorization.md` (system role protection + superadmin bypass table),
  `soft-delete.md` (deletion policy + cascade rules), `roles-permissions.md`
  (system role protection), `ai-execution-guide.md` (dependency + transaction
  rules + source-of-truth rules).

## Blocked Tasks

None — no implementation has started yet.

## Known Issues

**Resolved conflicts** (closed in the gap-closure pass):
- Scramble API documentation package — resolved: Scramble is the
  selected tool (see DEP-007). All documentation updated.
- `last_activity_at = NULL` policy — resolved: unlocking does NOT populate
  `last_activity_at`; NULL preserved until actual user activity (see ADR-018).
- ADR numbering collision — resolved: dependency ADRs renamed from ADR-001–007
  to DEP-001–DEP-007 to avoid collision with architecture ADRs (ADR-001–018).
- Audit transaction-timing ambiguity — resolved: audit records are written
  within the transaction (before COMMIT); after-commit is for jobs/events only.
- Stale `fruitcake/laravel-cors` reference — resolved: CORS is handled natively
  by Laravel's `HandleCors` middleware (Laravel 11+). Updated in
  `docs/base/security/web-security.md`.
- Stale `security_login_failures` table name — resolved: updated to
  `failed_login_attempts` in `docs/base/operations/troubleshooting.md` and
  `docs/base/dependencies/overview.md` (both now use consistent table name).
- Stale `SESSION_DRIVER=sanctum`/`passport` reference — resolved: updated in
  `docs/base/operations/troubleshooting.md`.
- Stale feature-matrix section IDs (`#22`, `#27`, `#47`, `#254`, `#48`) —
  resolved: section column removed; phase/priority columns preserved.
- Missing Security Logs in retention table — resolved: added
  `docs/base/security/data-protection.md`.

**Open** (documented design decisions, not bugs):
|- CACHE-002 remains intentionally deferred — no application-level cached data
  requiring cache::tags() grouped invalidation. Pattern documented in cache.md.
|- i18n remains intentionally deferred to the final project-wide phase. No
  lang/ directory; all Phase 1 views use static text (style-guide.md §160).

## Architecture Changes

- ADR-013: Application Logging Strategy (added)
- DEP-001 through DEP-007: Dependency decision records (added)
- Cascade rules now permitted per-relationship when justified (updates
  ADR-012; see `docs/base/data/soft-delete.md`)
- Application component responsibility model documented
  (`docs/base/architecture/application-components.md`)
- Transaction/after-commit semantics formalized
  (`docs/base/architecture/application-boundaries.md`)
- 5-way observability classification (Audit Logs, Application Logs, Security
  Logs, Server Logs, Pulse) formalized
- System role protection formalized (superadmin bypass table, system role
  deletion/renaming restrictions)

## Test Status

Not yet started (Phase 15).

## Documentation Status

|| Area | Status |
||------|--------|
|| Architecture | Complete |
|| Dependencies | Complete |
|| Security | Complete |
|| API | Complete |
|| Data | Complete |
|| Infrastructure | Complete |
|| Features | Complete |
|| Testing | Complete |
|| Operations | Complete |
|| UI | Complete |
|| Planning | Complete |

## Next Steps

1. ~~Begin Phase 1: Laravel foundation & environment~~ — Phase 1 complete & merged to main (commit f54d0c9)
  (FOUND-001 through FOUND-010, CACHE-001, QUEUE-001, CORR-001,
  UI-001 through UI-006, SOFT-001, TABLE-001, FLAG-001 with Laravel Pennant)
2. ~~Begin Phase 2: Database foundation~~ — Phase 2 complete (DB-001 base migrations, DB-002 RoleSeeder wired)
5. ~~Begin Phase 4C Group C: Activate/Deactivate + Lock/Unlock~~ — Group C complete (P4-C1 through P4-C6).

## Summary

All documentation and planning complete. Phase 1 implementation
complete (FOUND-001 through FOUND-010, CACHE-001, QUEUE-001, CORR-001,
UI-001 through UI-006, SOFT-001, TABLE-001, FLAG-001 with Laravel Pennant).
Phase 2 complete (DB-001 + DB-002). Phase 4 complete (Groups A through H,
P4-H1 through P4-H4 all verified DONE). Next: Phase 5 — Password/security lifecycle.