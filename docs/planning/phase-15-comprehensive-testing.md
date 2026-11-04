# Phase 15 — Comprehensive Testing

> Date: 2026-11-04 | Status: **PLANNED** | Branch: `feature/phase-15-comprehensive-testing` (not created)
> Purpose: make the suite deliberate instead of merely large — every layer
> (view, action, request, policy, endpoint, pipeline) has a named owner test,
> the static-analysis and coverage gates are enforced rather than described,
> and the numbers in `progress.md` can be reproduced by CI.
> Dependency chain: **A ∥ B → C → D → E**. Inside A: `A1 → A2 → A3`.
> Inside E: `E1, E2 → E3`.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/testing/testing-strategy.md`,
> `docs/base/testing/testing-matrix.md`, and
> `docs/base/testing/definition-of-done.md`. A task that wants to contradict
> them needs an ADR in `docs/planning/decisions.md`, not a code change.

---

## 1. Scope

Seventeen tasks in five groups:

| Group | Subject | Nature |
|---|---|---|
| A | Blade UI render tests, design-system compliance, responsive/theme attributes | **Verify** (new harness) |
| B | Unit tests — Actions, Form Requests, Rules, Policies, `app/Support` | **Verify** (mostly new) |
| C | Feature/API/end-to-end lifecycles — auth, users, API v1, storage & notifications | **Audit-and-close** |
| D | Static analysis, style gate, application security audit, dependency advisories | **Build** (tooling) |
| E | Performance guards, coverage thresholds, CI wiring, tracker reconciliation | **Build** (pipeline) |

**Not in scope:** rewriting suites that already pass to satisfy a number;
browser-automation (no Dusk/Playwright exists and none is proposed); chasing
line coverage on migrations, seeders, config or vendor views; load testing as a
CI gate (an external load tool is a manual procedure — see P15-E1); any
production-code change whose only justification is a test.

### Why this phase is not greenfield

The suite is already large — `progress.md` § *Test Status* is the live count
(2366 tests / 8524 assertions at the Phase 14 close). The gap this phase closes
is **structure and enforcement**:

- whole layers have no unit-level owner (50 Action classes in `app/Actions/V1`,
  one policy, one custom rule, one custom support class family);
- there is no `tests/Feature/UI` directory at all, so 53 application Blade
  views are exercised only incidentally through page tests;
- no static analyser is installed (`phpstan`/`psalm` absent from
  `composer.json`), and CI runs `pint --test --dirty`, which cannot see a
  pre-existing violation;
- coverage is `coverage: none` in CI — the threshold exists nowhere but in this
  document until P15-E2 wires it.

Groups C and D are therefore mostly **audit-and-close**, the same shape Phase 12
and Phase 13 used: build the coverage map first, write only the tests the map
shows missing, and record the gap rather than assume it.

---

## 2. Specifications (owner decisions)

1. **Group A — Views.** Render tests assert *structure and policy*, not pixels.
   PHPUnit can prove a view compiles, that a payload is consumed without a
   query, and that a class or attribute is present or absent. It cannot prove a
   colour looks right in dark mode; that claim stays a manual check
   (§ *Verify before reporting done* in `AGENTS.md`).
2. **Group B — Units.** A "unit" test here means the class under test is
   constructed directly, with collaborators doubled only where they cross a
   process boundary (queue, mail, storage, DB). Anything that boots the HTTP
   kernel belongs in Group C.
3. **Group C — Lifecycles.** One test file owns one capability across both
   channels where the capability exists on both. Path shape is not a
   capability: `POST /login` and `POST /api/v1/auth/login` are one capability
   with two addresses (the Phase 12 audit method).
4. **Group D — Tooling.** Every new Composer package requires an ADR in
   `docs/planning/decisions.md` **and** a `changelog.md` entry before
   `composer require`. That rule is not waived for dev-only tooling.
5. **Group E — Gates.** A gate that can be bypassed silently is not a gate.
   Thresholds are enforced by a failing command in CI, with the exact number
   written in one place.
6. **No test is deleted to make a threshold pass.** A threshold missed is a
   recorded gap with an owner, not a lowered number.

---

## 3. Group A — Views, UI Rendering & Component Tests

Goal: render safety of every application Blade view, and compliance with the
`--lbp-*` / Bootstrap 5.3 theme contract.

New directory: `tests/Feature/UI/` (does not exist today).

### P15-A1 — Blade Component & Layout Render Tests

- **Depends on:** —
- **Task:** `tests/Feature/UI/BladeComponentRenderTest.php`.
  - `layouts/app.blade.php` and `layouts/guest.blade.php` render for an
    authenticated and a guest actor respectively, with the shell sections
    present: header, sidebar, footer, breadcrumb slot, content slot.
  - Partial set from `resources/views/layouts/partials/`: `header`,
    `sidebar`, `footer`, `alerts`, `notification-dropdown`,
    `notification-dropdown-list`, `password-strength`.
  - Component set from `resources/views/components/ui/`: `badge`,
    `confirm-action`, `empty-state`, `feature-toggle`, `sortable-th`; and
    `layouts/partials/modals/confirmation.blade.php`.
  - **Design-system sweep:** no `bg-white` or `bg-light` in any application
    view; surfaces use the theme tokens (`bg-body-tertiary`, `bg-body`).
- **Current state (measured 2026-11-04):** `bg-white|bg-light` appears **23
  times, every one of them under `resources/views/vendor/`** (Pulse and
  Vantage overrides). The sweep therefore scopes to `resources/views`
  **excluding `vendor/`** — a naive whole-tree grep fails on day one and the
  usual "fix" (deleting a vendor override) breaks the package UI.
  `bg-body-tertiary` is already used in 46 places across 10 views, so the
  positive assertion has real fixtures to point at.
- **Acceptance:**
  - every listed view renders without an exception given an array payload;
  - the layout renders the same shell with the sidebar collapsed state and
    without a breadcrumb (both branches exist);
  - the sweep fails when a `bg-white` is added to an app view (sabotage check:
    add one, watch it go red, remove it);
  - the sweep passes with `resources/views/vendor/**` untouched.
- **Tests:** `BladeComponentRenderTest`.
- **Docs:** none.

### P15-A2 — Form & Interactive UI Partial Render Tests

- **Depends on:** P15-A1
- **Task:** `tests/Feature/UI/FormViewRenderTest.php`. Render each form page
  with a **plain array** payload — no Eloquent model, no factory — and assert
  the expected field names, CSRF token, method spoofing, and error-slot markup:
  - users create / edit (`pages/users/create`, `pages/users/edit`);
  - profile edit and password change (`pages/profile/*`);
  - settings (`pages/settings/index`) — all six cards;
  - feature flags (`pages/features/*`);
  - notification inbox and channels (`pages/notifications/*`).
- **Query guard:** assert `DB::enableQueryLog()` is empty across the render.
  A zero-query render is the mechanical proof of "no DB access in Blade", and it
  is the property that makes the UI replaceable (`AGENTS.md` § Hard rules —
  a value the API needs belongs in an Action or view model).
- **Acceptance:**
  - each page renders with the array payload and zero queries;
  - `@error` slots exist for every validated field the matching Form Request
    validates (the settings page is the historical failure: `input-group`
    breaks Bootstrap's sibling `invalid-feedback` selector, which made 18 of 22
    error messages invisible — Phase 8 Group A);
  - no `Role`/`SystemSetting` model instance is required to render.
- **Tests:** `FormViewRenderTest`.
- **Docs:** none.

### P15-A3 — Responsive & Theme Attribute Verification Suite

- **Depends on:** P15-A2
- **Task:** `tests/Feature/UI/ResponsiveUiTest.php`.
  - every table-bearing view wraps its table in `.table-responsive`
    (**10 views today**; the test enumerates the views, not the count, so a
    new table without a wrapper is a failure rather than a silently changed
    number);
  - scroll containers carry `flex-nowrap` where the page relies on horizontal
    scroll;
  - `components/ui/confirm-action` emits `data-variant="warning|danger|success"`
    on both its button and its submit trigger (**the variant is a `data-variant`
    attribute consumed by the driver JS, not a Bootstrap class on the
    template** — asserting `btn-danger` proves nothing);
  - every inline `<script>` in an app view carries `@nonce`
    (**10 views today**), and a `<script>` without it fails — the CSP is
    nonce-based, so this attribute is a security control, not styling.
- **Acceptance:** green; sabotage check per assertion (drop one `@nonce`, drop
  one `.table-responsive`, drop one `data-variant`).
- **Tests:** `ResponsiveUiTest`.
- **Docs:** none.

---

## 4. Group B — Unit, Domain Logic & Action Layer Tests

Goal: an isolated owner test per unit, so a regression names the class that
broke instead of a page test 200 lines away.

New directories: `tests/Unit/Actions/`, `tests/Unit/Requests/`,
`tests/Unit/Rules/`, `tests/Unit/Policies/`, `tests/Unit/Support/`.

### P15-B1 — Domain Action Unit Suite

- **Depends on:** —
- **Task:** `tests/Unit/Actions/**` — one file per domain folder under
  `app/Actions/V1/`: `User`, `Auth`, `Role`, `Permission`, `Feature`, `System`,
  `Notification`, `Audit`, `BulkAction`.
- **Scope correction:** the brief lists **POS/Member** and **Storage** actions.
  Neither exists: there is no POS/Member module in this repository, and the
  storage work (Phase 14) is a `StorageCapacityGuard` service plus controller,
  with no Action class. Those two domains are dropped from this task, not
  invented for it.
- **Transaction proof:** do **not** assert `DB::transaction()` by reflection —
  it proves the call exists, not that it rolls back. The proof is behavioural:
  double a collaborator to throw after the first write and assert **no row was
  persisted**. That is the only assertion that catches an action that writes
  outside its own transaction.
- **Coverage target:** every public `execute()`/`handle()` entry point reaches
  its happy path and both refusal branches (policy refusal, precondition
  refusal) where they exist. 50 Action classes today — start with the
  mutation-heavy domains (User, Role, Permission, Feature, System), then the
  notification fan-out.
- **Acceptance:**
  - one file per domain, `test_` prefix, no inline FQCN
    (`tests/Arch/NoInlineFqnTest.php` enforces it);
  - rollback test present for every action that writes more than one row;
  - audit rows asserted for the mutation actions (event name, causer, source)
    — the Action layer is the source of truth, so the unit test owns it.
- **Tests:** `tests/Unit/Actions/*`.
- **Docs:** none.

### P15-B2 — Form Requests & Custom Validation Rules Suite

- **Depends on:** —
- **Task:** `tests/Unit/Requests/**` and `tests/Unit/Rules/**`.
  - `App\Rules\PasswordStrengthRule` — direct, plus the username-rejection
    branch (`new PasswordStrengthRule($username)`);
  - `App\Http\Requests\BaseFormRequest` — asserts the `FormatsApiErrors`
    contract: `{message, errors, code, meta}` with `meta.request_id` and
    `meta.timestamp`, on a 422 and on an authorization failure. This is the
    contract that made 17 requests and 4 auth requests disagree before Phase 12;
    pin it once, centrally;
  - username `alpha_dash` + `unique` (update ignores self, create does not);
  - payload transformation — normalized keys, `strip_tags()` on `name` in
    `UserCreateAction` / `UserUpdateAction`.
- **Scope correction:** the brief names a `SanitizesInput` pipeline. **No such
  class exists** — sanitization in this codebase is `strip_tags()` at the two
  user-write sites plus output escaping (`{!! !!}` is allowlisted and pinned by
  `ContentSecurityPolicyTest`). This task tests what exists; it does not
  manufacture a sanitizer to test.
- **Acceptance:** green; the error-contract test fails if `BaseFormRequest`
  stops extending `FormatsApiErrors` (sabotage check).
- **Tests:** `tests/Unit/Rules/PasswordStrengthRuleTest.php`,
  `tests/Unit/Requests/BaseFormRequestContractTest.php`, per-request tests as
  the list grows.
- **Docs:** `docs/base/api/error-contract.md` (only if the contract text drifts).

### P15-B3 — Policies, Authorization & Support Utilities

- **Depends on:** —
- **Task:** `tests/Unit/Policies/**` and `tests/Unit/Support/**`.
  - `App\Policies\UserPolicy` — **the only policy in the codebase**. Every
    method, for four actors: superadmin (via `Gate::before`), admin, plain
    user, and a user with **no role at all** (a different code path, per the
    Phase 10 pentest finding);
  - `App\Support\PasswordPolicy` — extend the existing
    `tests/Unit/PasswordPolicyTest.php`; do not relocate it;
  - `App\Support\SafeUrl`, `QueueName`, `PermissionCatalog`,
    `FeatureCatalog`, `SystemRole`, `LastSuperadmin`, `MorphMap`,
    `UnreadNotificationCount`, `ApiError` — the invariants each encodes
    (`QueueName` rejects a bare string queue; `PermissionCatalog` is the single
    source the seeder and the gates both read; `ApiError` emits every code with
    `meta`).
- **Scope correction:** the brief names `RolePolicy` and `SystemSettingPolicy`.
  **Neither exists.** Roles and settings are authorized at the **route** level
  (`can:` + `feature:` middleware) against `PermissionCatalog`, which is the
  project's stated pattern (`AGENTS.md` § Hard rules: authorization is gated on
  the route, never in a constructor). Writing a policy test would mean writing
  a policy this architecture deliberately does not have.
- **Acceptance:** green; `UserPolicy` has no untested method; the support
  invariants are pinned by name, so a rename or a widened allowlist fails.
- **Tests:** `tests/Unit/Policies/UserPolicyTest.php`,
  `tests/Unit/Support/*`.
- **Docs:** none.

---

## 5. Group C — Feature, API & End-to-End Integration

Goal: every web and API lifecycle has one owner file, and the gaps are named
before they are filled.

All four tasks start with a **coverage map**: grep the existing
`tests/Feature/{Auth,Security,User,Api,Storage,Notification}` files against the
capability list, and record which row is already covered and by which test.
Most rows will already be covered — Phase 3, 5, 9, 12, 13 and 14 wrote them.
The work is the uncovered minority plus the map itself, because a suite with no
map cannot be audited later.

### P15-C1 — Web Authentication & Security Lifecycle Suite

- **Depends on:** —
- **Task:** `tests/Feature/Auth/**` + `tests/Feature/Security/**`.
  - login (success, bad credentials, deactivated, locked, unverified), logout
    (session invalidation + token deletion), force-password-change redirect
    loop, signed email-verification URL (valid, expired, tampered), composite
    rate-limit keys (per identifier **and** per IP — `login-ip` is the bucket
    that sees a spray, per Phase 13 E2), and session revocation on deactivate /
    lock / soft-delete.
  - **Revocation is the row most likely to be missing.** A revoked session must
    fail on the *next* request, not on the next login — assert through the
    middleware, not through the state column.
- **Acceptance:** every row above maps to a test file and method in the map
  section of this task's commit message or the final report; the revocation
  rows pass; `--filter=Security` stays green (it is the Phase 13 gate).
- **Tests:** existing files extended; new files only for uncovered rows.
- **Docs:** `docs/base/features/authentication.md` if a behaviour differs.

### P15-C2 — Web User Management & State Operations Suite

- **Depends on:** P15-C1
- **Task:** `tests/Feature/User/**` — CRUD, activate, deactivate, lock, unlock,
  restore from trash, bulk actions, audit-trail generation.
- **Constraint:** the four state routes and the bulk route each carry their own
  `can:` — a sibling-permission probe is mandatory (a route written
  `can('users.view')` where it means `users.deactivate` passes every
  zero-permission test in the repo; Phase 6 E9 records this).
- **Acceptance:** every state transition asserts (a) the new state, (b) the
  audit row with event name and causer, (c) the refusal for a caller holding a
  sibling permission; `LastSuperadmin` refuses deactivate and delete of the last
  superadmin, including through the bulk path.
- **Tests:** `tests/Feature/User/**`.
- **Docs:** none.

### P15-C3 — API V1 Contract & Resource Suite

- **Depends on:** P15-C1
- **Task:** `tests/Feature/Api/V1/**` — Sanctum bearer auth, the success
  envelope, pagination, and the documented error codes.
- **Contract, not guesses:** link `docs/base/api/response-contract.md` and
  `docs/base/api/error-contract.md`. The collection shape is `data.<plural>`
  plus **`data.pagination`** with four keys (`per_page` defaults 10, the inbox
  is fixed at 10) — there is **no** top-level `meta.pagination`, and no `Link`
  header. Do not restate the shape in the test file; read it from the schema
  the contract describes.
- **Codes to pin:** 400, 401, 403, 404, 409, 422, 429, 500 — every one is
  exercised in `ApiErrorEnvelopeTest`; this task adds the per-module reachability
  that is still missing and keeps `ApiDocumentationTest` as the drift gate
  (route → path + method, `bearerAuth` scheme, no status outside the contract).
- **Scope correction:** the brief says "Scramble OpenAPI schema integrity".
  Scramble is not dev-only any more (ADR-025): the document is exported at
  deploy time and served from `storage/app/openapi.<env>.json`. The integrity
  check therefore runs against the **exported** document too, not only the live
  one.
- **Acceptance:** green; flags return **403**, not 404 (Phase 7 decision, pinned
  by `FeatureFlagMiddlewareTest`) — a test that "fixes" this into 404 is wrong.
- **Tests:** `tests/Feature/Api/V1/**`.
- **Docs:** none (drift is a failure of `ApiDocumentationTest`, not of this doc).

### P15-C4 — Storage, Backup & Notification Engine Suite

- **Depends on:** P15-C1
- **Task:** `tests/Feature/Storage/**` + `tests/Feature/Notification/**` —
  local/S3 disk isolation, private-file signed routes (expiry + tamper),
  backup job dispatch, temporary-file cleanup by TTL, and DB bell notification
  dispatch + badge invalidation.
- **Current state:** both suites exist and were closed with Phase 14 (Storage)
  and Phase 9 (Notification). This task is audit-and-close: build the map, and
  fill only what the map shows missing. Known genuine gaps to record rather
  than fake: **no test talks to a real S3 endpoint**, and the backup/restore
  cycle has only been exercised by hand or not at all (`progress.md` row 14:
  "runtime verification deferred"). A mocked S3 disk is not evidence of S3
  behaviour — say so in the map.
- **Acceptance:** the map exists; each row links a test; the deferred rows are
  listed explicitly as deferred with the reason.
- **Tests:** existing files.
- **Docs:** none.

---

## 6. Group D — Security Scanning, Static Analysis & Vulnerability Audits

Goal: the checks that do not need a browser, running on every change and on a
schedule.

**No static analyser is installed today.** `composer.json` has Pint, PHPUnit,
Mockery and Collision; there is no `phpstan`, `larastan`, `psalm` or
`enlightn`, and no `phpstan.neon`. So Group D begins with an ADR, not a config.

### P15-D1 — Static Analysis (PHPStan level 8)

- **Depends on:** —
- **Task:**
  1. ADR in `docs/planning/decisions.md` + `changelog.md` entry.
  2. `composer require --dev larastan/larastan` (pin PHPStan `^2`).
  3. `phpstan.neon` — level **8**, paths `app/`, `analysis` of the Laravel
     container extensions via Larastan, and a **committed baseline** for
     framework/vendor-typed noise only.
  4. Add the run to CI (P15-E3) and to `composer test`-adjacent scripts.
- **Baseline policy (this is the decision that matters):** the baseline is a
  debt ledger, not a mute button. Each entry carries the rule and a one-line
  reason; the gate fails on a **new** error and on any baseline entry that no
  longer matches (PHPStan reports unmatched ignores when configured to). A
  baseline that can silently grow is the same as no analyser.
- **Acceptance:** `vendor/bin/phpstan analyse --memory-limit=1G` exits 0;
  a deliberately introduced type error fails; the baseline file has no entry
  without a neighbouring comment.
- **Tests:** the analyser run itself.
- **Docs:** ADR + `changelog.md` + `docs/base/testing/testing-strategy.md`.

### P15-D2 — Code Style Gate (Pint, full tree)

- **Depends on:** —
- **Task:** change CI from `pint --test --dirty` to `pint --test` over the
  repository.
- **Why this is a real task:** `--dirty` diffs against the merge base and only
  reports files in that diff. A violation committed before the gate existed is
  invisible forever, and the Phase 11 notes record exactly the kind of framing
  bug (`--dirty` with a depth-1 checkout silently formatting nothing). One
  clean full run first, then enforce.
- **Acceptance:** full-repo `pint --test` exits 0 on `main`; a deliberately
  unformatted file fails CI.
- **Tests:** the run.
- **Docs:** none.

### P15-D3 — Application Security Audit Suite (in-house, extended)

- **Depends on:** P15-C1
- **Task:** extend `tests/Feature/Security/SecurityAuditTest.php` rather than
  add a scanning package. The suite already has ten files
  (`SecurityHeadersTest`, `ContentSecurityPolicyTest`, `CsrfProtectionTest`,
  `CorsPolicyTest`, `InputSanitizationTest`, `CompositeRateLimitKeyTest`,
  `SettingsPentestTest`, `NotificationPentestTest`, `SecurityAuditTest`,
  `SecurityOverheadBenchmarkTest`); the gap is **coverage of the Phase 14
  surface** — the 507 `STORAGE_FULL` envelope, the signed-download route, the
  backup job's refusal to run on a full target, and the timezone rebase.
- **Decision — Enlightn and Psalm are declined** for this phase, and recorded
  in § *Deviations*: Enlightn duplicates the in-house probes and adds a
  dependency (with a Pro tier) for checks this suite already performs; Psalm has
  no first-class Laravel plugin and would be a second analyser over the same
  code as PHPStan with a different baseline. One analyser, one baseline, one
  owner.
- **Acceptance:** each Phase 14 surface has an adversarial probe; the HTML 500
  branch still proves no class/message/path/stack trace reaches the browser.
- **Tests:** `SecurityAuditTest` + targeted files.
- **Docs:** `docs/base/testing/testing-matrix.md` (row coverage).

### P15-D4 — Dependency Vulnerability Scanning (scheduled)

- **Depends on:** —
- **Task:** keep `composer audit --locked` and `npm audit --audit-level=high`
  in the PR run (they exist in `ci-cd.yml`), and add a **weekly scheduled**
  audit job. An advisory published after a merge changes nothing about that
  merge, so a PR-only audit never sees it.
- **Acceptance:** a scheduled run exists and fails the workflow on a
  high/critical advisory; the PR run is unchanged (same thresholds).
- **Tests:** the workflow run.
- **Docs:** `docs/base/testing/testing-strategy.md`.

---

## 7. Group E — Performance, Coverage & CI/CD

Goal: numbers that are enforced, and a pipeline that says one thing once.

### P15-E1 — Performance Profiling & Query Count Guard

- **Depends on:** P15-C1, P15-C2, P15-C3, P15-C4
- **Task:** extend the **existing** `tests/Feature/Performance/PerformanceGuardTest.php`
  (it is not a new file — it already exists, alongside
  `UserViewPerformanceTest`, `SelfRegistrationPerformanceTest`,
  `FeatureFlagPerformanceTest` and the `tests/Benchmark` classes).
  - **query-count guards** for the hot reads: user index, roles index, audit
    index, notification inbox, settings page. Query counts are deterministic
    and belong in CI;
  - **N+1 leak detection**: assert count does not grow between N=3 and N=40
    rows (the Phase 10 export bug — 3 and 40 both fit inside one 500-row chunk
    — is why the row counts must straddle a chunk boundary where a chunker is
    involved);
  - **peak memory** per hot request, with a ceiling at least 2× the measured
    value;
  - **view-composer memoization**: the bell count is cached and must cost 0
    queries on a warm hit (Phase 9 D6).
- **Wall-clock policy:** the brief sets a "< 200 ms per endpoint" target. Keep
  it as a **recorded measurement**, not a CI assertion — a shared 2-core runner
  makes sub-200 ms assertions flaky, and a flaky gate gets disabled. Where a
  timing guard is kept, it is a loose ceiling (e.g. 1 s) that only catches a
  catastrophic regression. The five assertion-free benchmark tests stay
  assertion-free unless a stable invariant (query count, memory) can replace
  the wall clock.
- **Load testing:** k6 / ApacheBench are **manual procedures** documented in the
  phase report — no k6 dependency, no nightly load run. A load tool measures
  this host, not the runner, and the deploy target's capacity is not this
  phase's question.
- **Acceptance:** every guard fails when its optimisation is reverted (sabotage
  each one); CI runtime increase is bounded and stated.
- **Tests:** `PerformanceGuardTest` + the four benchmark files.
- **Docs:** `docs/base/testing/testing-strategy.md`.

### P15-E2 — Coverage Threshold Enforcement

- **Depends on:** P15-A3, P15-B3, P15-C4
- **Task:**
  - CI coverage driver: `setup-php` currently declares `coverage: none`; switch
    to **pcov** for the coverage job (xdebug is ~3–5× slower).
  - `phpunit.xml` already scopes the source to `app/`
    (`<source><include><directory>app</directory>`), which is the right scope
    for line coverage; keep migrations, seeders and config out.
  - **Global threshold:** `php artisan test --coverage --min=85` — the `--min`
    option is built into Laravel's test command and fails non-zero below the
    bar. No custom script needed for this one.
  - **Per-layer thresholds** (Actions ≥ 95 %, Policies/Security = 100 %) are
    **not expressible with `--min`**, which is global only. Enforce them from
    one `--coverage-clover` run with a small reader (≈20 lines) in `bin/` that
    sums the `<coverage>` metrics for `app/Actions`, `app/Policies` and
    `app/Http/Requests`, and exits non-zero under each bar. Three separate
    `--coverage-filter` runs would re-run the suite three times; the clover
    read does not.
  - **When it runs:** the coverage job runs on a **schedule and on release
    tags**, not on every PR. A second full suite with instrumentation roughly
    doubles PR wall-clock for a number that changes slowly; PR feedback stays
    with the fast run. Record the cadence in the workflow comment.
- **Realism note:** 100 % on `app/Policies` is one class and reachable. 95 % on
  50 Action classes is a large number; if the first measured run is below it,
  the output is a **gap list with owners**, not a lowered threshold (see § 2
  item 6).
- **Acceptance:** a deliberately uncovered method in an Action drops the job
  below 95 % and fails it; the report prints the three numbers it compared.
- **Tests:** the gate itself.
- **Docs:** `docs/base/testing/testing-strategy.md`,
  `docs/base/testing/definition-of-done.md`.

### P15-E3 — CI Pipeline & Tracker Finalisation

- **Depends on:** P15-D1, P15-D2, P15-E1, P15-E2
- **Task:**
  1. **Do not duplicate the existing pipeline.**
     `.github/workflows/ci-cd.yml` already runs, on every PR: `composer
     install`, `composer validate --strict`, `npm ci`, `npm run build`,
     actionlint, `php artisan test --no-coverage`, `pint --test --dirty`,
     `composer audit --locked`, `npm audit --audit-level=high`. It is also the
     `deploy` job's `needs:`. A second workflow that reruns all of it buys
     ~15 minutes per PR and two definitions of "green".
     **`testing.yml` therefore owns only what `ci-cd.yml` does not:** PHPStan
     (P15-D1), the coverage gate (P15-E2), the weekly audit (P15-D4). Pint is
     upgraded in place in `ci-cd.yml` (P15-D2), not copied into a second file.
  2. Static analysis and coverage run as **separate jobs**, so PHPStan fails a
     PR in ~2 minutes instead of waiting behind the browser-free suite.
  3. Keep `cancel-in-progress: false` on the deploy workflow: the test job is
     the deploy gate and cancelling mid-deploy leaves a half-synced release
     (the comment in `ci-cd.yml` already explains this — do not change it).
  4. Reconcile the trackers: `task-tracker.md` Phase 15 table and this file are
     written as `PLANNED` on 2026-11-04; each task flips to `REVIEW` → `DONE`
     only with evidence in `progress.md` § *Test Status*.
- **Acceptance:** PR runs green in the documented order; a deliberate PHPStan
  error fails the fast job; a deliberate coverage regression fails the coverage
  job; the deploy gate is unchanged; `progress.md` counts are reproduced by CI,
  not hand-carried.
- **Tests:** the pipeline.
- **Docs:** `task-tracker.md`, `progress.md`, `docs/base/testing/testing-strategy.md`.

---

## 8. Deviations from the brief (recorded, not silently applied)

| # | The brief says | The code says | Decision |
|---|---|---|---|
| D-1 | Group D tasks (P15-Dn) | § 2 of the brief defines Group D but lists no tasks for it | P15-D1..D4 defined here from the brief's own scope sentence (PHPStan 8, Pint, security audit, dependency scanning) |
| D-2 | "Pest / PHPUnit" | PHPUnit 12.5 only, no Pest | PHPUnit throughout; no Pest migration |
| D-3 | Psalm and Enlightn | neither installed; Psalm has no first-class Laravel plugin | both declined for this phase — one analyser (PHPStan), one in-house adversarial suite. Recorded in the ADR (P15-D1) |
| D-4 | `SanitizesInput` pipeline | no such class; `strip_tags()` at two user-write sites + output escaping | P15-B2 tests what exists; a sanitizer is not invented to have something to test |
| D-5 | `RolePolicy`, `SystemSettingPolicy` | only `UserPolicy` exists; roles/settings authorize on the route via `can:` + `PermissionCatalog` | P15-B3 covers `UserPolicy`; inventing policies would violate the route-gating rule in `AGENTS.md` |
| D-6 | POS/Member and Storage Action classes | no POS/Member module; storage is a guard service + controller (Phase 14) | dropped from P15-B1; the storage surface is already owned by `tests/Feature/Storage` |
| D-7 | `.github/workflows/testing.yml` runs Pint, tests+coverage and audit in parallel | `ci-cd.yml` already runs Pint, tests, composer/npm audit and is the deploy gate | `testing.yml` owns only the new checks (PHPStan, coverage, weekly audit); Pint upgraded in place. One check, one owner |
| D-8 | "< 200 ms per endpoint" as a guard | shared runners make sub-200 ms assertions flaky | kept as a recorded measurement; CI asserts query counts and memory, with a loose 1 s ceiling only where a guard is kept |
| D-9 | K6 / ApacheBench profiling | no load tool installed; CI has no load stage | manual procedure, documented; not a CI gate |
| D-10 | Design-system sweep "zero `bg-white`/`bg-light`" | 23 occurrences, all under `resources/views/vendor/` (Pulse, Vantage) | sweep scoped to application views; vendor overrides excluded by path |
| D-11 | "Scramble OpenAPI schema integrity" | Scramble is a production requirement (ADR-025); the served document is the deploy-time export | integrity asserted against the exported document as well as the live one |

---

## 9. Execution order

```
A1 → A2 → A3 ──┐
B1, B2, B3 ────┼→ E2 ─┐
C1 → C2 ───────┤      ├→ E3
C1 → C3 ───────┤      │
C1 → C4 ───────┘      │
D1, D2, D4 ───────────┤
C1..C4 → E1 ──────────┘
D3 ← C1
```

Groups A and B are independent and can run in parallel. C needs A only for the
render harness (a page-level test renders a view); D1/D2/D4 are pure tooling and
can start on day one. E closes last, because every threshold it enforces is a
number the earlier groups produce.

---

## 10. Definition of done for the phase

- `vendor/bin/pint --test` (full tree) — clean.
- `vendor/bin/phpstan analyse` — level 8, clean against the committed baseline.
- `php artisan test` — green, and the count in `progress.md` matches the run.
- Coverage: overall ≥ 85 %, Actions ≥ 95 %, Policies/Security = 100 % — or a
  written gap list with owners (never a lowered bar).
- Every capability in the C-group map links to a test file.
- `task-tracker.md` Phase 15 rows `DONE`; `progress.md` row 15 and § *Test
  Status* reconciled.
- No production code changed except where a test exposed a defect — and that
  defect gets its own regression test and a line in `changelog.md`.

---

## 11. Tracker reconciliation

On 2026-11-04 this breakdown and the Phase 15 rows in `task-tracker.md` were
written as **PLANNED**. The rows are summary only — the per-task detail is this
file (same split as Phases 12, 13 and 14). `progress.md` row 15 stays `PLANNED`
and cites this document; it flips only when the definition of done above is
met.
