# Phase 17 — Full Regression & Final Review

> Date: 2026-11-04 | Status: **PLANNED** (breakdown written 2026-11-04; nothing
> executed yet — this file is the plan, not the result) | Branch at
> implementation: `feature/phase-17-full-regression`
> Purpose: run every gate once on the final tree, record the numbers the run
> actually printed, reconcile the trackers to those numbers, and close the
> roadmap — with the honesty rule that no row flips without evidence.
> Dependency chain: **A ∥ B ∥ C** (independent runs) → **D1 → D2 → D3**.
> Hard predecessor: **Phase 16 Group E must leave REVIEW before P17-D2 flips any
> row** (P16-E1/E2/E3 are in REVIEW as of this writing).
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/` — Phase 17 verifies, it does not redefine.

---

## 1. Scope

Twelve tasks in four groups:

| Group | Subject | Nature |
|---|---|---|
| A | Full test-suite run, coverage thresholds, UI/Blade render regression | **Run + record** |
| B | Style, static analysis, dependency vulnerability audits | **Run + record** |
| C | Asset build, runtime cache warming, scheduler audit | **Run + record** |
| D | Code-to-docs reconciliation, tracker closure, release summary | **Close** |

**Not in scope:** writing new tests to move a number (Phase 15 owns test
coverage; if a bar is missed, the output is a gap list with owners, never a
newly invented test to pad it); installing a tool the repo does not have (Psalm,
a markdown linter, a load tester — a new package needs an ADR first, `AGENTS.md`);
changing runtime behaviour; lowering any threshold; committing (`git commit` is
the owner's, `AGENTS.md` hard rule).

### Why this phase is almost entirely a re-run

The brief reads like a build phase. It is not: every gate Group A and Group B
asks for was built and sabotage-verified in Phase 15, and the docs gates in
Phase 16. What Phase 17 adds is **one measurement on the final tree** — the
last phase to touch anything was documentation, so the shipped state has never
been checked end-to-end with the roadmap's own acceptance bar. The state at the
time of writing, measured rather than assumed:

| The brief asks for | What already exists |
|---|---|
| full `php artisan test` | **2665 passed / 9834 assertions, 9 risky**, exit 0 (P16-E2, 2026-11-04) |
| coverage ≥ 85 % / Actions ≥ 95 % / Policies 100 % | `--min=85` + `bin/coverage_gate.php`; last measured run: global **94.87 %** (5031/5303), Actions **97.11 %**, Policies **100 %**, Requests **98.09 %** (P15-E2) |
| `pint --test` PSR-12 | `pint.json` = `psr12`; PASS across **487 files** (post-P16 fix) |
| PHPStan level 8+ | `phpstan.neon`: **level 8** over `app/` via Larastan ^3.14, ADR-030, 310-entry baseline ledger, `reportUnmatchedIgnoredErrors` (P15-D1); exit 0 |
| `composer audit` + `npm audit` | per-PR in `ci-cd.yml` (`composer audit --locked`, `npm audit --audit-level=high`) + weekly in `testing.yml` (P15-D4) |
| `npm run build` | Vite, runs in CI on every PR (`ci-cd.yml`) |
| `php artisan optimize` | not yet exercised on this tree — genuinely new (see C2) |
| scheduler audit | six named entries in `routes/console.php` (see C3) — never audited as a set |
| code-to-docs reconciliation | Phase 16 did the eleven-area audit; what remains is the **58 unreconciled QA rows** (see D2) and the Tailwind/CSS conflict (see C1/D1) |

So: A1, A2, A3, B1, B2, B3, C1 are **run the existing gate, copy the line the
run prints**. C2, C3, D1, D2, D3 are the real work.

---

## 2. Specifications (owner decisions)

1. **A number is quoted from a run, never carried from a doc.** Every acceptance
   criterion below is "the output line is pasted into `progress.md` §
   *Test Status* verbatim". A hand-carried number is how Phase 16's trackers
   drifted.
2. **One check, one owner.** If a gate exists (`bin/coverage_gate.php`,
   `DocumentationLinkIntegrityTest`, `JobConventionTest`), Phase 17 runs it. It
   does not write a second file asserting the same property.
3. **A missed bar is a finding, not a fix.** If coverage or an audit falls short,
   the output is a gap list with an owner and a `changelog.md` line where a
   defect exists. No threshold moves; no test is deleted or weakened
   (`AGENTS.md`).
4. **Trackers flip on evidence, in order.** `PLANNED → IN_PROGRESS → REVIEW →
   DONE`, REVIEW never skipped (`docs/planning/ai-execution-guide.md`).
5. **The closing statement may not exceed the evidence.** `✅ COMPLETED / CLOSED`
   is written only for a surface this phase can point at. Deferred runtime
   evidence (real S3, backup→restore round trip, live `timezone:rebase` — the
   Phase 14 close note) stays stated as deferred in the release summary. A
   project that says "everything verified" when three things are deferred is
   worse than one that says what is and isn't.
6. **No new dependencies, no new packages, no new tools.** Anything that would
   need one (Psalm, Enlightn, markdownlint, k6) is declined the way Phases 15
   and 16 declined them, and recorded in § 8.

---

## 3. Group A — Automated Test Suite & Full Regression

Goal: the whole suite green on the final tree, at the documented bars, with the
measurement taken from that run.

### P17-A1 — Comprehensive Backend Test Run

- **Depends on:** —
- **Task:** `php artisan test` on the final tree. Assert 0 failures, 0 errors,
  and account for the risky count rather than absorbing it — the live figure is
  **9 risky**, all pre-existing (`tests/Feature` skips, incomplete-marked
  benchmarks, one warning; attribution belongs to P15-E1, recorded in
  `progress.md` § *Test Status*). Each named surface in the brief must have a
  suite that ran: Auth (`WebSessionRevocationTest`, login/lock/verify suites),
  User CRUD, RBAC (`RbacPerformanceTest`, `VantagePermissionGateTest`,
  `ActionNamingConventionTest`), Settings (`SettingsPentestTest`,
  `SettingsUiRenderTest`), Feature Flags (`FeatureFlagMiddlewareTest`),
  Notifications (79 + 23 + 19 rows in `progress.md` § *Test Status*), Storage
  (`StorageAuthorizationTest`, `BackupStrategyTest`), Audit
  (`ActionFirstAuditTest`, `AuditExportTest`), API V1 (`ApiDocumentationTest`,
  `ApiErrorEnvelopeTest`, `OpenApiExportTest`, `tests/Feature/Api/V1/**`).
  Run `tests/Unit`, `tests/Feature`, `tests/Arch` — the three suites are
  directory-level, so the plain command covers them; do not add a filter and
  call it a full run.
- **Acceptance:** exit 0; the printed "X passed / Y assertions, Z risky" line
  quoted into `progress.md` § *Test Status* with the date; total test count not
  lower than the P16-E2 figure (2665/9834) without an explanation of what
  disappeared; the surface list above shown as present in the run's directory
  summary.
- **Tests:** the suite itself.
- **Docs:** `docs/planning/progress.md` § *Test Status*.

### P17-A2 — Coverage Threshold Assertion

- **Depends on:** P17-A1 (same tree; the run below supersedes A1's plain run)
- **Task:** one instrumented run feeding every bar:
  `php artisan test --coverage --min=85 --coverage-clover=build/coverage/clover.xml`
  then `php bin/coverage_gate.php build/coverage/clover.xml`. The gate sums
  three layers from one clover file (XPath `//file` — PHPUnit nests files under
  `<package>`). Bars as enforced since P15-E2: **global ≥ 85 %**, **Actions
  ≥ 95 %**, **Policies = 100 %**, **`app/Http/Requests` ≥ 95 %**.
  - **Driver note:** CI uses pcov on the scheduled + release coverage job; this
    host has **xdebug**, so the local run is several times slower than the
    plain suite. That is the cost of the measurement, not a defect.
  - **"Security/Policies = 100 %"** maps to `app/Policies` (one class,
    `UserPolicy`) — there is no `app/Security` namespace; the security surface
    is asserted by `SecurityAuditTest` / `InputSanitizationTest` /
    `CompositeRateLimitKeyTest`, which run in A1 and are cited, not
    re-barcoded (see D-3).
- **Acceptance:** all four bars OK and the gate exits 0, with the four printed
  percentages recorded in this file's "as implemented" section; a miss produces
  the gate's per-file gap list filed with owners — never a lowered bar.
- **Tests:** `bin/coverage_gate.php` (the gate; sabotage-verified in P15-E2 —
  zeroing one Action dropped the layer to 94.73 % and exited 1; no re-sabotage
  needed unless the gate itself changes).
- **Docs:** `progress.md` § *Test Status*, `docs/base/testing/testing-strategy.md`
  (only if the numbers moved enough to make the doc's figures stale).

### P17-A3 — UI & Blade Render Regression

- **Depends on:** P17-A1
- **Task:** run the owners, not a new suite: `tests/Feature/UI/`
  (`BladeComponentRenderTest` 12 · `FormViewRenderTest` 7 ·
  `ResponsiveUiTest` 4 = 23 tests, Phase 15 Group A) plus the page-level render
  gates (`SettingsUiRenderTest`, `RbacUiRenderTest`,
  `NotificationUiRenderTest`, `HealthUiRenderTest`).
  - **Design-system rules** are already source-swept by Phase 15 A: no
    `bg-white`/`bg-light` outside `resources/views/vendor/` (P15 D-10), every
    footer on `bg-body-tertiary`, every table inside `.table-responsive`, every
    inline script `@nonce`d. Re-run; do not rewrite the sweeps.
  - **"Without direct DB queries in templates":** this property is **not** a
    tree-wide gate. It is asserted per page by the performance suites
    (`HealthUiRenderTest::test_it_renders_and_queries_nothing` — a query-count
    delta across a warm second render; `PerformanceGuardTest`;
    `NotificationBenchmarkTest`'s 0-query warm bell). P17-A3 records that the
    pages with a query-count gate are the ones listed in `progress.md` § *Test
    Status*, and lists any rendered page with **no** such gate as a known gap
    rather than claiming the property holds for all views (see D-4).
- **Acceptance:** the four-to-five UI suites green with their counts recorded;
  the design-system sweeps green; the "no queries in Blade" claim written as
  *what is proven per page*, with the unproven remainder named.
- **Tests:** existing owners only; no new file.
- **Docs:** `docs/base/testing/testing-matrix.md` (row corrections if a gap was
  named).

---

## 4. Group B — Code Quality, Static Analysis & Security Scanning

Goal: the three quality gates green on the final tree, and the dependency
audits read at release time rather than only in CI.

### P17-B1 — Code Style & Formatting Check

- **Depends on:** —
- **Task:** `vendor/bin/pint --test` (full tree, since P15-D2 — the `--dirty`
  scope and its `fetch-depth: 0` companion were removed when the gate went
  whole-tree).
- **Acceptance:** exit 0, file count recorded (last: PASS, 487 files). Any
  failure is fixed by `vendor/bin/pint` on the offending file only, re-run,
  re-checked — not by adding a Pint ignore.
- **Tests:** the gate itself; it is also a step in `ci-cd.yml`.
- **Docs:** none.

### P17-B2 — Static Analysis Audit

- **Depends on:** —
- **Task:** `vendor/bin/phpstan analyse --memory-limit=1G` (`composer analyse`).
  `phpstan.neon` is **level 8** over `app/`, Larastan ^3.14, with
  `phpstan-baseline.neon` as the committed debt ledger (ADR-030): the gate fails
  on new errors **and** on entries that no longer match
  (`reportUnmatchedIgnoredErrors: true`), so debt is paid by deleting lines.
  - The brief's "PHPStan **or** Psalm": Psalm is not installed and was declined
    in Phase 15 (no first-class Laravel plugin, D-3 there). One analyser, one
    ledger (see D-2).
  - Report the ledger size at close (last: 310 entries). Shrinking it is
    welcome; a growth in entries is a finding to name, not to hide.
- **Acceptance:** exit 0; baseline entry count recorded; the run's own "No
  errors" / entry summary quoted, not summarised.
- **Tests:** the `phpstan` job in `testing.yml` runs the same command per PR —
  cite it.
- **Docs:** `docs/base/testing/testing-strategy.md` only if the ledger size is
  quoted there.

### P17-B3 — Dependency & Security Vulnerability Audit

- **Depends on:** —
- **Task:** at the release tree, not on a CI schedule:
  `composer audit --locked` and `npm audit --audit-level=high` (the same
  thresholds CI runs: high/critical stops a release).
  - CI already owns the cadence: per-PR in `ci-cd.yml`, weekly Mondays in
    `testing.yml` (`schedule` only fires from the default branch — the P15-D4
    caveat). P17-B3's added value is that a release reads it once directly.
  - Both files are dev-toolchain-sensitive: `composer.lock` and `package-lock.json`
    must be the committed ones (`--locked` / `npm ci` semantics). Re-running
    after an update is a different measurement.
- **Acceptance:** both exit 0, or every advisory listed with its package,
  severity and an owner decision (accept / upgrade / defer) written into this
  file. An advisory in a **dev-only** package is a different risk class from one
  in a runtime package — say which.
- **Tests:** none (these are scanners).
- **Docs:** `docs/planning/changelog.md` if a package version changes as a
  result (a version bump is a dependency change; a new package needs an ADR).

---

## 5. Group C — Production Build, Cache Optimizations & Environment Verification

Goal: prove the app boots and serves from a production-shaped cache, and that
the scheduler as a set does not collide with itself.

### P17-C1 — Frontend Asset Compilation

- **Depends on:** —
- **Task:** `npm ci && npm run build` (Vite). Assert exit 0 and read the manifest.
  - **Known conflict to settle, not to hide:** `vite.config.js` declares
    `input: ['resources/css/app.css', 'resources/js/app.js']`,
    `resources/css/app.css` starts with `@import 'tailwindcss'`, and
    `@tailwindcss/vite` is a devDependency — while `AGENTS.md` says **"No
    Tailwind"** and every layout stylesheet is self-hosted from
    `public/vendor/` (`bootstrap`, `adminlte`, `fontawesome`,
    `bootstrap-icons`, `theme.css`). The three layouts `@vite(...)` **only**
    `resources/js/app.js`; nothing links the built CSS. So the build emits a
    stylesheet the browser never loads. P17-C1 records which way the project
    goes (drop the Tailwind input + devDependency, or load it and amend
    `AGENTS.md` via ADR). **This is an owner decision — the phase reports it, it
    does not unilaterally delete a build input** (see D-8).
- **Acceptance:** exit 0 with the bundle sizes recorded; the CSS question either
  answered by the owner or filed in `final-release-summary.md` as a known open
  decision with its consequence (dead bytes in the manifest, not a broken page).
- **Tests:** none (the build is the artefact; `ci-cd.yml` already runs it).
- **Docs:** `AGENTS.md` § Stack + `docs/base/ui/*` — only after the owner
  decides; never before.

### P17-C2 — Laravel Cache Optimization & Verification

- **Depends on:** P17-C1 (build the manifest before warming views)
- **Task:** the command set the brief names, in the order a deploy needs:
  `php artisan optimize:clear`, then `php artisan optimize`, then prove the app
  still serves. `optimize` (Laravel 13) runs `config:cache`, `event:cache`,
  `route:cache`, `view:cache` + anything a provider added via
  `ServiceProvider::$optimizeCommands`. **There is no "dynamic configuration
  caching" in the brief's sense** — `config:cache` freezes `.env`-derived config,
  and `SystemSetting` values live in the database and are deliberately **not**
  config-cached (they are runtime-mutable and cache-busted via `DB::afterCommit`,
  QA-SET-003). Say this in the record so nobody reads the frozen config as a
  settings regression.
  - **What can actually break here, checked by reading rather than hoping:**
    `env()` outside `config/` returns `null` once config is cached. Current
    scan: **zero live calls** in `app/`, `routes/`, `bootstrap/` — the only two
    mentions are comments explaining why mail settings are *not* read from
    `env()` at runtime (`AppServiceProvider:678`,
    `UpdateMailSettingsRequest:14`). Also: no closures in `config/*.php`, so
    `config:cache` has nothing to serialize.
  - Then walk a representative set of pages with caches warm (login, dashboard,
    users index, settings, audit index, `/docs/api`, `/pulse`, `/health`) and
    assert 200 — a cached-view failure is invisible to the test suite because
    tests run uncached.
  - **Restore the dev state afterwards** (`optimize:clear`) or the next person
    edits a Blade file and sees nothing change.
- **Acceptance:** all four cache steps exit 0; the page walk returns 200 with
  the status codes recorded; `env()`-outside-config scan recorded at zero;
  feature-flag and `SystemSetting` reads proven still runtime-mutable with
  config cached (toggle a flag, see the 403 flip — that is the check the brief's
  "dynamic configuration caching" phrase is really pointing at).
- **Tests:** none required; `EnsureFeatureIsEnabled` +
  `FeatureFlagMiddlewareTest` already pin the flag semantics this task re-reads
  under a warm cache.
- **Docs:** `docs/base/operations/deployment.md` — add the `optimize` step and
  the "clear after" note if the walk shows the deploy checklist is missing it.

### P17-C3 — Scheduler & Queue Validation

- **Depends on:** —
- **Task:** `php artisan schedule:list` and audit the set. Six entries exist in
  `routes/console.php` (measured 2026-11-04, from the file, not from memory):

  | Name | Cadence | Guards |
  |---|---|---|
  | `password-security-sweeps` | every minute | `withoutOverlapping`; self-gates on `password_security_sweep_time` (default `00:00`) |
  | `system-backup` | every minute | `onOneServer` + `withoutOverlapping`; dispatches via `SystemBackupSchedule::dispatchIfDue()` on the same `app_timezone`-derived window |
  | `temp-storage-cleanup` | daily | `Schedule::job(new CleanTemporaryStorageJob())` — the `tmp`-disk TTL path (`temp_file_retention_hours`, 1–8760, default 24) |
  | `notification-retention` | daily | `withoutOverlapping`; prunes **read** rows only |
  | `audit-export-retention` | daily | `withoutOverlapping` |
  | `vantage-retention` | daily | `Schedule::command('vantage:prune --force --keep-processing')`, behind `config('vantage.enabled')` |

  Check three things the brief's "no execution conflicts" means concretely:
  (a) the two `everyMinute` closures are both self-gating on the *same* stored
  time setting, so at `00:00` they both fire in the same tick — confirm they use
  different queues (`sweeps` vs `backups`) and that the worker
  (`bin/run-workers.sh`) listens on all three queues (`default,exports,sweeps`
  + `backups` via the dedicated script line); (b) every long-running entry
  overlaps safely (`withoutOverlapping`, and the backup job's
  `WithoutOverlapping` + `$tries=1` single-flight from Phase 14); (c) the daily
  sweeps do not all land at 00:00 and starve one worker.
  - `schedule:list` prints the **wrapper** callbacks, not what the closures
    decide at runtime — the two every-minute entries will look identical. The
    audit is therefore the table above plus a read of `dispatchIfDue()`, not
    the command output alone (see D-9).
- **Acceptance:** the six-entry table matches `schedule:list` output exactly
  (an entry in the table but not in the list, or the reverse, is a finding);
  queue names verified against `App\Support\QueueName` and
  `bin/run-workers.sh`; `JobConventionTest` green (it enforces the per-job
  standard by reflection); a written statement on whether the 00:00 collision
  is safe, with the reason.
- **Tests:** `JobConventionTest` cited.
- **Docs:** `docs/base/infrastructure/queue.md`,
  `docs/base/operations/deployment.md` (the scheduler/cron line Phase 16 D2
  added).

---

## 6. Group D — Definition of Done, Architecture Reconciliation & Project Closure

Goal: the docs say what the code does, the trackers say what the evidence
supports, and the release summary is something a future owner can trust.

### P17-D1 — Final Code-to-Docs Reconciliation

- **Depends on:** P17-A1..C3 (the C-group runs produce the facts this task
  reconciles)
- **Task:** close what Phase 16's eleven-area audit did not cover, plus what
  Phase 17 itself just found. Phase 16 verified architecture, dependencies,
  security, API, data, infrastructure, features, operations and planning
  against code (`progress.md` § *Documentation Status*); it deliberately left
  **Testing** and **UI** at bare "Complete" because it did not audit them. So:
  - **Testing:** `docs/base/testing/*` against A1/A2/B1/B2's measured numbers
    and the bars in `bin/coverage_gate.php` — the doc must quote the enforced
    bars, not the brief's aspiration.
  - **UI:** `docs/base/ui/*` against A3's sweep results and the C1 Tailwind
    conflict — the "no Tailwind" claim is currently a doc-vs-config conflict,
    which is exactly the class of drift this project treats as "docs win, code
    changes via ADR" (`AGENTS.md` § Before any task).
  - **Architecture/API paths:** the brief cites `docs/base/architecture.md` and
    `/docs/api`. The root file does not exist (moved to
    `docs/base/architecture/architecture.md`; Phase 16 D-2). The API surface is
    already walked (P16-A1/B1: 48 paths / 59 operations, statuses inside the
    contract) — cite it; do not re-walk it.
  - Anything where **code contradicts a documented contract** is a defect:
    regression test + `docs/planning/changelog.md` line + ADR if the contract
    itself must change. It is not folded into a doc edit.
- **Acceptance:** every `docs/base/` area carries a dated "verified against code"
  row in `progress.md` § *Documentation Status* (including Testing and UI, which
  currently have none); zero doc-vs-config conflicts left silently unresolved —
  the Tailwind one either fixed or named as an owner decision; `DocumentationLinkIntegrityTest`
  green (it is the mechanical half of this claim).
- **Tests:** `DocumentationLinkIntegrityTest` (links resolve, headings don't
  skip, no `||` rows) — extended only if a new mechanical property is asserted.
- **Docs:** `docs/base/testing/*`, `docs/base/ui/*`, `AGENTS.md` (Stack line, if
  the Tailwind decision changes it), `docs/planning/changelog.md` for any defect.

### P17-D2 — Closure of Planning Trackers

- **Depends on:** P17-D1, and Phase 16 Group E leaving REVIEW
- **Task:** flip the four planning surfaces — and here the brief's
  "mark the entire project ✅ COMPLETED / CLOSED" meets a fact that must be
  settled first:
  - `docs/planning/task-tracker.md`: 233 rows — 227 DONE, 3 REVIEW (P16-E1/E2/E3),
    1 IN_PROGRESS, 1 PLANNED (+1 "**MET**"). P17-D2 cannot close Phase 17 while
    Phase 16's E rows sit in REVIEW; it closes them first, on their own evidence.
  - `docs/planning/qa-tracker.md`: **99 rows — 41 DONE, 49 PLANNED, 6 TODO, 3
    PARTIAL.** That is 58 scenario rows whose status has not been reconciled
    against the 2665-test suite since the QA list was written. Phase 16 E3
    verified "0 open findings" — a different question from "every scenario row
    is DONE". **So the honest close here is a per-row reconciliation of those 58
    rows** (each: covered by which test file, or still genuinely unproven), not
    a blanket stamp. A blanket stamp on 58 unreconciled rows is precisely the
    "a capability is not delivered by the UI that shows it" failure `AGENTS.md`
    warns about, moved into the tracker.
  - `docs/planning/progress.md`: row 16 → DONE, row 17 → DONE with the run
    figures; § *Test Status* = A1/A2's quoted line; § *Documentation Status* =
    D1's re-dated rows; § *Known Issues* — the deferred runtime evidence and the
    delete-candidates Phase 15 recorded (the three auth Form Requests' dead
    `password()`/`remember()` accessors, 12 statements) must appear there or in
    the release summary, not vanish at closure.
  - `docs/planning/implementation-roadmap.md`: phase 17 row PLANNED → DONE
    after this group closes.
- **Acceptance:** the four trackers agree with each other and with the repo; no
  row flips without the evidence line it cites; the QA reconciliation is either
  complete or the closure text says exactly which rows remain unproven; REVIEW
  never skipped.
- **Tests:** none.
- **Docs:** the four files above.

### P17-D3 — Final Release Summary Report

- **Depends on:** P17-D2
- **Task:** write `docs/planning/final-release-summary.md` — the one document a
  future owner reads cold. Content, in this order:
  1. **What is delivered** — per capability (auth, users, RBAC, flags, settings,
     notifications, storage/backup/retention, audit, API v1, monitoring, docs),
     each with the test file(s) that prove it and the route-level gate
     (`can:` + `feature:`).
  2. **The measured quality bar** — suite count, coverage bars and last
     measured values, Pint, PHPStan level 8 + ledger size, audits, and how each
     is enforced in CI (which workflow, which trigger — the P15-E3 split:
     `phpstan` per PR, `coverage` scheduled + release, `dependency-audit`
     weekly, full suite + Pint + build per PR as the deploy gate).
  3. **What is deferred, stated as deferred** — real S3, backup→restore round
     trip, live `timezone:rebase`, the 9 risky tests, the web-verify
     signature/expiry design question (P15-C), the dead auth-request accessors,
     the C1 CSS/Tailwind decision if the owner has not settled it.
  4. **How to deploy and operate it** — three lines pointing at
     `docs/base/operations/deployment.md` (including the process-start step
     Phase 16 D2 had to add), not a duplicate runbook.
  5. **The honest verdict** — "shippable for its stated purpose" with the list
     above as the boundary. No unqualified "100 % verified".
- **Acceptance:** every claim in the document cites a file (a test, a doc, a
  config key, a workflow job) or is labelled deferred; nothing a reader would
  act on is quoted from this plan rather than from a run.
- **Tests:** none.
- **Docs:** `docs/planning/final-release-summary.md` (new).

---

## 7. Execution order

```text
A1 → A2
   → A3 ─┐
B1, B2, B3 ─┼→ D1 → D2 → D3
C1 → C2 ────┤
C3 ────────┘
        (Phase 16 Group E must leave REVIEW before D2)
```

A, B and C are independent runs and can interleave; nothing in them edits the
same file. D is strictly serial: the reconciliation needs the measurements, the
trackers need the reconciliation, and the summary needs the trackers.

---

## 8. Deviations from the brief (recorded, not silently applied)

| # | The brief says | The code says | Decision |
|---|---|---|---|
| D-1 | "PHPUnit/Pest full run" | PHPUnit 12.5 only, `test_*` prefix, no Pest (P15 D-2) | PHPUnit; no migration |
| D-2 | "PHPStan/Psalm level 8+" | PHPStan **is** level 8 (`phpstan.neon`, Larastan, ADR-030, committed baseline); Psalm is not installed and was declined in Phase 15 | run PHPStan; Psalm declined — a second analyser is a second ledger and a dependency decision needing an ADR |
| D-3 | "Security/Policies = 100 %" | `app/Policies` is one class (`UserPolicy`); there is no `app/Security` namespace; authorization is route-level `can:` + `PermissionCatalog` (`AGENTS.md`), pinned by `SecurityAuditTest`, `InputSanitizationTest`, `CompositeRateLimitKeyTest`, `VantagePermissionGateTest` | 100 % bar applies to `app/Policies`; the security surface is the suites above, run in A1 and cited. No policies are invented to hit a percentage (P15 D-5) |
| D-4 | "UI Blade render tests without direct DB queries" as a suite-wide assertion | no tree-wide gate for it; the property is proven per page by query-count deltas (`HealthUiRenderTest`, `PerformanceGuardTest`, `NotificationBenchmarkTest`) | A3 records which pages are proven and names the rest as a gap; it does not write a global "views run zero queries" test, which would need a query log wrapped around every render and would fail for reasons that are not view bugs (eager loading legitimately queries before the view) |
| D-5 | "Composer & NPM security audits" as new Phase-17 work | both already run per-PR (`ci-cd.yml`) and weekly (`testing.yml`) | B3 runs them once at the release tree (CI's schedule ≠ a release-time read) and reports; no new workflow, no duplicated job (rule § 2.2) |
| D-6 | "`php artisan optimize` configuration/route/**view**/event caching" | all four exist in Laravel 13's `optimize`; but "dynamic configuration" is a contradiction for `SystemSetting`, which is DB-backed and runtime-mutable by design | C2 caches config/routes/views/events, and separately proves the DB-backed settings + feature flags still change behaviour with a warm config cache — that is the property the phrase is reaching for |
| D-7 | "scheduled background jobs audit" | `schedule:list` shows wrapper closures; two of the six entries are self-gating every-minute closures whose real cadence lives inside `dispatchIfDue()` / a stored time setting | C3 audits the set from `routes/console.php` + the classes, with `schedule:list` as the cross-check, not as the source of truth |
| D-8 | "asset compilation … zero build errors or unhandled warnings" | the build is clean but ships a Tailwind stylesheet nothing loads; `AGENTS.md` says "No Tailwind"; layouts pull self-hosted CSS from `public/vendor/` and `@vite` only `resources/js/app.js` | C1 **reports** the conflict with the evidence and hands the decision to the owner (drop the input, or load it + amend `AGENTS.md` via ADR). Deleting a build input inside a regression phase is a runtime change this phase scoped out (§ 1) |
| D-9 | `docs/base/architecture.md` (D1) | the file moved: `docs/base/architecture/architecture.md` (Phase 16 D-2) | D1 targets the real path |
| D-10 | "task-tracker/qa-tracker/progress.md final sign-off → entire project ✅ COMPLETED / CLOSED" | `qa-tracker.md` has 58 rows unreconciled with the suite (49 PLANNED / 6 TODO / 3 PARTIAL of 99); `task-tracker.md` still has Phase 16's three E rows in REVIEW | D2 reconciles the rows first; the closure statement says what is proven and what remains — a blanket stamp would make the tracker lie, which is the failure mode `AGENTS.md` § *Current state* names |
| D-11 | Coverage bars as "≥ 85 % / ≥ 95 % / 100 %" with no driver | the bars are already enforced (`--min=85` + `bin/coverage_gate.php`, Requests ≥ 95 added as P15 D-13); CI runs pcov on schedule/release, this host has xdebug | A2 runs the existing gate and quotes it; nothing is re-implemented, and the local slowness is recorded as the cost of measuring |
| D-12 | Group D's "operational guides match implementation 100 %" | three runtime items are deliberately deferred (real S3, restore round trip, live rebase — Phase 14 close note) | "100 %" applies to what the docs *claim*; the deferred items are labelled deferred in D1/D3, not counted as drift |

---

## 9. Definition of done for the phase

- `php artisan test` exit 0 on the final tree, with the printed counts quoted
  into `progress.md` (A1) — the 9 risky accounted for, not absorbed.
- `bin/coverage_gate.php` exit 0 at the enforced bars (global ≥ 85 %, Actions
  ≥ 95 %, Policies = 100 %, Requests ≥ 95 %), the four percentages recorded
  (A2).
- `pint --test` exit 0 · `phpstan analyse` exit 0 against the committed ledger,
  entry count recorded (B1, B2) · `composer audit --locked` and `npm audit
  --audit-level=high` clean or every advisory assigned (B3).
- `npm run build` exit 0 with the CSS/Tailwind conflict either decided or
  filed (C1) · `optimize` + a warm-cache page walk + runtime settings/flag
  mutation proven, dev state restored (C2) · six scheduler entries audited as a
  set with the collision question answered (C3).
- Every `docs/base/` area re-dated in § *Documentation Status* including
  Testing and UI, which have no verification row today (D1).
- Trackers reconciled: `qa-tracker.md`'s 58 unreconciled rows either flipped on
  evidence or named as unproven; Phase 16's E rows closed; row 17 REVIEW → DONE
  (D2).
- `docs/planning/final-release-summary.md` written, every claim citing a file or
  labelled deferred (D3).
- No production code changed except where this phase's own runs exposed a
  defect — and that defect gets a regression test + `changelog.md` line, called
  out as a finding rather than folded into a doc edit.
- Nothing committed: the owner commits (`AGENTS.md` hard rule).

---

## 10. Tracker reconciliation

On 2026-11-04 this breakdown was written as **PLANNED**, with the summary rows
added to `docs/planning/task-tracker.md` (§ Phase 17) and `progress.md` row 17
citing this document. The rows are summary only — per-task detail is here (same
split as Phases 12–16). Nothing has been executed to produce this file: every
"measured 2026-11-04" figure above was read from the repository (config files,
`routes/console.php`, `progress.md`, the Phase 15/16 records), and the figures
that require running a gate are written as the bar to meet, not as a result.
Branch at implementation: `feature/phase-17-full-regression`.
