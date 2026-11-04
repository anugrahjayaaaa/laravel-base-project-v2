# Phase 16 — Documentation Verification

> Date: 2026-11-04 | Status: **IN PROGRESS** (breakdown written 2026-11-04; **Group A DONE** (owner-reviewed 2026-11-04); **Group B in REVIEW**; **Group C in REVIEW**; **Group D implemented 2026-11-04 — in REVIEW**; Group E PLANNED) | Branch: `feature/phase-16-documentation-verification`
> Purpose: prove that `docs/` describes the code that actually ships — every
> schema, permission, setting key, audit event and runbook reconciled against
> its implementation, the documentation interfaces rendering clean, and the
> planning trackers telling the same story as the repository.
> Dependency chain: **A ∥ B ∥ C ∥ D → E**. Inside A: `A1, A2 → A3`.
> Inside E: `E1, E2 → E3`.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/` itself — this phase verifies against it, it does
> not redefine it. A doc that is wrong is corrected to the code; a code that is
> wrong versus its doc is a defect (regression test + `changelog.md`), not a
> licence to silently rewrite the doc (`AGENTS.md` § Before any task: docs win,
> changes go via ADR in `docs/planning/decisions.md`).

---

## 1. Scope

Fourteen tasks in five groups:

| Group | Subject | Nature |
|---|---|---|
| A | Docs interfaces (`/docs/api`), Markdown compliance, render gates | **Verify** (audit-and-close) |
| B | Code-to-docs engine: API schemas, Action naming, SystemSetting key mapping | **Audit-and-close** |
| C | Security/RBAC/flags/backup/retention docs vs runtime | **Audit-and-close** |
| D | Audit-event mapping and operational runbooks | **Audit-and-close** |
| E | Link integrity, full-suite regression, tracker reconciliation, closure | **Build + close** |

**Not in scope:** changing runtime behaviour to match an aspirational doc;
i18n of documentation or UI strings (deferred project-wide, `AGENTS.md` §
*Traps already paid for* — there is no `lang/` source to write against);
introducing a new Composer/npm package (a markdown linter, a docs generator,
anything) without an ADR first — and none is proposed here; rewriting prose
for style where the content is already correct; touching `docs/base/` design
decisions — verification checks conformance, it does not relitigate ADRs.

### Why this phase is not greenfield

Every earlier phase already wrote docs as part of its Definition of Done
(Phase 12 shipped `ApiDocumentationTest` as a live drift gate, Phase 13 pinned
headers/CSP, Phase 14's Group E re-audited `storage.md`, `retention.md`,
`backup-disaster-recovery.md`, `queue.md`, `error-contract.md` against code,
Phase 15 made `pint --test` and PHPStan cover the whole tree). `progress.md` §
*Documentation Status* marks all eleven doc areas "Complete". The gap this
phase closes is **drift since those closes and coverage of the areas no
earlier phase owned end-to-end**:

- no phase enumerated *all* audit events from code and diffed them against the
  docs (events are documented per-module: `notifications.md`,
  `backup-disaster-recovery.md`, `dependencies/overview.md` — no single map);
- no gate proves every `PermissionCatalog` entry and every validated
  `SystemSetting` key appears in its feature doc;
- the ~95 `.md` files under `docs/` have **no link-integrity check at all**
  (no linter in `package.json`, no test walks the trees) and several
  cross-references have already been rewritten by path moves (e.g. `docs/base/`
  restructures since Phase 3);
- `docs/planning/` trackers carry per-phase notes but the Phase 15 closure
  numbers must be re-verified, not hand-carried.

This phase is therefore an **audit-and-close** phase in the shape Phases 12–15
used: measure the real state first, record gaps, fix only what the map shows
missing, and never invent an artefact to have something to verify.

---

## 2. Specifications (owner decisions)

1. **Verification is a diff, not a rewrite.** Each task produces a map (doc
   row ↔ code symbol) and changes only the rows that disagree. Doc prose that
   already matches code stays untouched.
2. **Code wins on facts, docs win on contracts.** A schema shape, an error
   code, a status, a retention rule is a *contract* — if code and
   `docs/base/api/*-contract.md` disagree, the code is wrong (unless an ADR
   says otherwise) and gets a regression test. File paths, class names, key
   lists are *facts* — the doc is corrected to the code.
3. **One check, one owner.** Where an existing test already owns a property
   (`ApiDocumentationTest`, `ApiDocsUiRenderTest`, `OpenApiExportTest`,
   `JobConventionTest`), this phase extends or cites it; it does not create a
   parallel file asserting the same thing. Deviations where the brief names a
   new file that duplicates an existing owner are recorded in § 8.
4. **Gates run in CI or they do not count.** Any check that must stay true
   (link integrity, event-map completeness) lands as a PHPUnit test in the
   existing suites; one-off audits are recorded in this file's "as implemented"
   sections.
5. **No new dependencies.** Markdown checks are implemented with PHP/Symfony
   primitives inside the existing test harness, not markdownlint or a docs
   framework. If verification genuinely cannot be done without a package, the
   task stops and files an ADR proposal instead of requiring it.
6. **Trackers flip only on evidence.** `PLANNED → IN_PROGRESS → REVIEW → DONE`
   with the measured run recorded in `progress.md`, per
   `docs/planning/ai-execution-guide.md`.

---

## 3. Group A — Documentation UI & Public Interface Consistency Verification

Goal: the documentation *interfaces* render clean and the repo Markdown
follows the house conventions — verified by the tests that exist, extended
where the brief's property has no owner yet.

### P16-A1 — API Documentation UI Verification (`/docs/api`)

- **Depends on:** —
- **Task:** verify (by running and reading, not rebuilding) that the Scramble
  Scalar UI at `/docs/api` and the raw document at `/docs/api.json` render the
  full contract surface: success envelopes, `data.pagination` four keys, and
  the documented status codes (200, 400, 401, 403, 404, 422, 500 — plus 409,
  410, 429, 502, 507 where annotated).
  - **Current state (measured 2026-11-04):** both routes exist in
    `route:list`; the surface is already owned by
    `tests/Feature/Api/ApiDocsUiRenderTest.php` (9 tests: admin/superadmin/
    guest/public-mode/sidebar-agrees-with-route), `ApiDocumentationTest`
    (route→path+method, bearer scheme, no status outside the contract) and
    `OpenApiExportTest` (real `scramble:export` diffed against the route
    table, ADR-025 exported document). The reachable statuses inference cannot
    see carry `#[Response]` annotations (Phase 12 C).
- **Acceptance:** the three owner tests green; a manual pass over the rendered
  UI (Scalar panels show per-status response schemas, try-it works
  same-origin) recorded in this file; any status documented in
  `docs/base/api/error-contract.md` but absent from the OpenAPI output is a
  finding with an owner.
- **Tests:** existing owners cited; no new file.
- **Docs:** `docs/base/api/api-architecture.md` (findings section only if the
  audit finds drift).

### P16-A2 — Markdown & Design-System Compliance Audit

- **Depends on:** —
- **Task:** audit the ~95 `.md` files under `docs/` against the house
  conventions actually in force: H1 → H2 → H3 heading hierarchy (no level
  skips), relative links resolving to real files, fenced code blocks carrying
  an info string (`php`, `blade`, `bash`, `json`, `text`, `markdown`) — plus
  the table style already dominant in `docs/planning/`. Run as a scripted
  sweep (a throwaway PHP one-liner or `bin/` helper is fine; nothing enters
  the suite unless P16-E1 pins it).
  - **Current state:** no linter is configured anywhere (`package.json` has
    none; no `.markdownlint*`), so every convention is unstated habit. The
    sweep therefore *measures* first; a convention with >90 % existing
    compliance is enforced in P16-E1, one with mass existing violation is
    recorded as a house-style decision, not a defect.
  - **Scope correction:** the brief's "Markdown view renderers" — **no
    in-app Markdown renderer exists** (zero matches in `resources/views`;
    `docs/` is repository Markdown consumed on Git hosting). This audit
    checks the files, not a viewer. Recorded as D-1.
- **Acceptance:** the sweep output recorded per-convention (compliance %,
  violation list); no doc is reformatted wholesale — only broken links and
  skipped heading levels fixed.
- **Tests:** none (manual/scripted audit; enforcement deferred to P16-E1 with
  the measured baseline).
- **Docs:** none.

### P16-A3 — Documentation Render Test Gate

- **Depends on:** P16-A1, P16-A2
- **Task:** the brief asks for `tests/Feature/Documentation/DocsUiRenderTest.php`.
  The `/docs/api` render gate **already exists** as `ApiDocsUiRenderTest`
  (§ 2 rule 3: one check, one owner). This task therefore closes the *remaining*
  render properties instead of duplicating:
  - the exported document (`storage/app/openapi.<env>.json`, ADR-025) still
    matches the live `/docs/api.json` contract surface (`OpenApiExportTest`
    extended if a row is missing);
  - the docs gate behaves under `api_docs_public` off/on and flag semantics
    unchanged (already pinned — cite, do not re-test);
  - any *new* property P16-A1's manual pass found (e.g. an envelope missing a
    response schema) gets a test in the owning file.
- **Acceptance:** `--filter=Docs` green; no second file asserting what
  `ApiDocsUiRenderTest` asserts.
- **Tests:** `tests/Feature/Api/ApiDocsUiRenderTest.php`,
  `OpenApiExportTest.php` (extended only where a gap was found).
- **Docs:** none.

### Group A — as implemented (2026-11-04)

- **A1 ran the owners; all green.** `ApiDocsUiRenderTest` +
  `ApiDocumentationTest` + `OpenApiExportTest` — **22 tests / 70 assertions**,
  including the real `scramble:export` vs route-table diff. The per-status
  check was done against the exported document (48 paths / 59 operations):
  statuses present are 200/201/202/400/401/403/404/409/410/422/429/500/502/503,
  every one inside the contract (`ApiDocumentationTest` fails on any status
  outside it), so no undocumented status leaks. The Scalar UI pass itself was
  proven by the render tests (page + spec 200 for admin, sidebar agrees with
  the route, public-mode toggle semantics) rather than a live browser — the
  HTML shell is the package's own view; recorded as the shortcut.
  **Two findings, both owners elsewhere:**
  1. `scramble:export` warns **JR001 "Cannot infer the resource model"** on
     `HealthCheckResource` (`app/Http/Resources/Api/V1/HealthCheck/HealthCheckResource.php:19`)
     — the health schema is published without an inferred shape. This is a
     schema-annotation row, not a render gap → **owner P16-B1**.
  2. **507 is absent from every per-operation document.** By design: no
     `api.v1` route writes to a guarded disk (secure media, backup trigger and
     capacity metrics are web-only per the Phase 14 capability sync); the code
     is handler-pinned (`ApiErrorEnvelopeTest`, Phase 15 D3). The brief's
     "verify 500/422/… schemas" is satisfied; the absence is a recorded
     exception, not a defect — if B1 finds the exceptions table in
     `api-architecture.md` should name it, that is the fix there.
- **A2 sweep measured, not assumed.** One-off scanner over **96 `docs/**/*.md`**
  (kept out of the repo per § 2.5; enforcement lands in P16-E1):
  | Property | Baseline | Action |
  | --- | --- | --- |
  | Relative links resolve | **1 broken** — `docs/base/data/soft-delete.md` → `./ui-architecture.md` (file moved to `docs/base/ui/`) | fixed → `../ui/ui-architecture.md`, anchor `§ Confirmation Modal` verified to exist |
  | Heading skips (fence-aware) | **1 real** — `docs/qa/remediation-tracker.md` `### C4 gate re-audit` under H1 | promoted to `##` |
  | Fences with info string | **103 of 192 bare (46 % compliance)** — mostly ASCII diagrams and `.env` samples in `docs/planning/` | mass violation → recorded as a house-style decision, **not gated** (§ 3 A2 rule); E1 gates links + headings only |
  Four of the five apparent heading skips were the scanner's own false
  positives (`# comment` lines inside `bash`/`ini` fences) — the corrected
  fence-aware sweep is the number above. This is why the baseline was scripted
  before any file was touched: naive counting would have "fixed" four correct
  docs. Re-scan after the fixes: zero broken links, zero skips.
  - **Scope correction held:** no in-app Markdown renderer exists (zero
    `resources/views` matches) — D-1 stands; A2 audited files, not a viewer.
- **A3 produced no code.** Both A1 findings belong to schema annotation (B1)
  and documented exceptions — neither is a render property, so per § 2.3 the
  owners are extended only when a render gap appears, and none did.
  `--filter=Docs`-equivalent suites are green as run above; the
  `tests/Feature/Documentation/DocsUiRenderTest.php` the brief asks for would
  be a byte-for-byte second copy of `ApiDocsUiRenderTest` (D-4).

---

## 4. Group B — Code-to-Docs Engine & Endpoint Schema Audit

Goal: the three machine-checkable mappings — endpoint schemas, Action naming,
setting keys — agree between code and docs, end to end.

### P16-B1 — API Endpoint Schema & Annotations Audit

- **Depends on:** —
- **Task:** walk every `routes/api.php` v1 endpoint; diff the controller
  PHPDoc `@return` shapes and Scramble-documented responses against the actual
  test-asserted payloads (`tests/Feature/Api/V1/**`) and the two contracts
  (`response-contract.md`, `error-contract.md`). Contract text is never
  restated here or in the docs — linked (house rule).
  - **Current state:** Phase 12 C/D/E already migrated every `respond()`
    success envelope, pinned nine handler codes in `ApiErrorEnvelopeTest`, and
    `ApiDocumentationTest` fails CI on route/status drift. Expected residual
    findings: PHPDoc shapes added after Phase 12 (Phase 14/15 surfaces —
    storage metrics, backup trigger, audit export) not yet annotated with
    `#[Response]`, and any endpoint whose test asserts a key no `@return`
    declares.
- **Acceptance:** the walk is recorded as a per-endpoint table (endpoint /
  schema source / payload source / verdict); every ❌ row is fixed in code
  annotation or doc text, and the fix keeps `ApiDocumentationTest` green.
- **Tests:** `ApiDocumentationTest` (unchanged role: the drift gate).
- **Docs:** `docs/base/api/api-architecture.md` audit section; per-module API
  docs only where they contradict payloads.

### P16-B2 — Domain Architecture & Action Naming Reconciliation

- **Depends on:** —
- **Task:** verify every class under `app/Actions/V1/` matches the
  domain-first contract `{Domain}{Operation}Action`
  (`App\Actions\V1\{Domain}\{Domain}{Operation}Action`).
  - **Current state (measured 2026-11-04):** 50 Action classes across nine
    domains (Audit, Auth, BulkAction, Feature, Notification, Permission, Role,
    System, User); spot-checked files already conform
    (`Role/RoleCreateAction`, `Auth/AuthRevokeSessionAction`). The naming
    doc is `docs/base/architecture/naming-conventions.md` § *Action Naming
    (Domain-First)* — the brief's `docs/base/architecture.md` does not exist
    as a root file (it moved under `docs/base/architecture/`). Recorded as
    D-2.
- **Acceptance:** a full enumeration (script or `find` + review) with zero
  non-conforming class names; the doc's domain list matches the directory
  list — including that **no POS/Member domain exists** (Phase 15 D-6 — do
  not verify an invented module).
- **Tests:** optional Arch rule in `tests/Arch/` if enumeration is mechanical
  (`classes in app/Actions/V1 match {Domain}\{Domain}.*Action`); this is the
  one new gate this phase proposes beyond P16-E1, and only if it stays a
  one-liner.
- **Docs:** `docs/base/architecture/naming-conventions.md`,
  `docs/base/architecture/folder-structure.md` (list corrections only).

### P16-B3 — SystemSetting Configuration Mapping Audit

- **Depends on:** —
- **Task:** enumerate the validated keys from
  `app/Http/Requests/V1/System/SystemSettingRequest::rules()` (the source of
  truth; the brief's "37+" understates it — the rules array carries ~60 quoted
  key entries including per-card groups) and check each key is documented in
  its feature doc (`docs/base/features/settings.md` and the module docs that
  own it — storage, notifications, security).
  - **Current state:** keys exist across the settings cards
    (`app_timezone`, `storage_max_capacity_mb`, `temp_file_retention_hours`,
    `backup_*`, `mail_*`, password-policy keys, `api_docs_public`,
    `health_rate_limit_per_minute`, …). No test asserts doc-coverage of the
    key list today.
- **Acceptance:** table of key → validation rule → doc location → verdict;
  every undocumented key documented (one line each, in the doc that owns the
  feature, not a dump in `settings.md`); every documented key still present in
  code (a doc describing a deleted key is the same class of defect).
- **Tests:** none required (the table is the artefact); enforcement only if
  trivially expressible without a new harness.
- **Docs:** `docs/base/features/settings.md` + owning module docs.

### Group B — as implemented (2026-11-04)

- **B1 walked the exported document, not a wish list.** The per-endpoint
  verdict is a **summary + exceptions table** (59 operations would be 59
  identical ✅ rows):

  | Surface | Verdict |
  | --- | --- |
  | 48 paths / 59 operations, every 2xx/4xx with a JSON content schema (or the documented `text/csv` on the export download) | ✅ — seven "no schema" candidates were heuristic false positives: Scramble emits the `respond()` shapes as `anyOf` with `const` messages, all meaningful |
  | Statuses outside the contract | ✅ zero — `ApiDocumentationTest` gates it and is green; doc statuses (200/201/202/400/401/403/404/409/410/422/429/500/502/503) are all contract members |
  | A1's JR001 finding (`HealthCheckResource`) | **Closed as cosmetic** — the exported `/v1/health` schema is complete (`status`/`timestamp`/`checks`, all required) straight from the `@return array{}` shape; the class docblock already reasons why no `@mixin` exists. The warning stays; the doc matches the code |
  | A1's 507 per-op absence | Recorded exception — no `api.v1` route writes to a guarded disk (Phase 14 web-only surfaces), handler-pinned by `ApiErrorEnvelopeTest`. Nothing to add to the `api-architecture.md` exceptions table beyond this record |
  | PHPDoc shapes | ✅ all 8 `app/Http/Resources/Api/V1` classes carry `@return array{…}`; the two controllers without a Resource (`Notification`, `SystemSetting`) are the documented Phase 12 passthrough decision |

  Zero fixes were needed: B1's honest output is "the Phase 12 annotations still
  hold" — with the proof, not the assumption.
- **B2 found the drift inside the doc itself.** Enumeration over 54 files in
  `app/Actions/V1/` (50 `*Action` + 4 non-Action): the naming
  `naming-conventions.md` prose said "**Every** class … `{Domain}{Operation}Action`"
  while its own example table used `BulkActionProcessor` — and the table was
  stale in three more ways. Fixes (docs only):
  1. the sentence is scoped to *Action* classes and names the exception family
     it already tolerated: `BulkActionHandler` (interface),
     `User/UserBulkActionHandler`, `Role/RoleBulkActionHandler`,
     `BulkActionProcessor` — bulk machinery the Actions delegate to, named by
     role, folder-prefix still honoured;
  2. dead example `SystemUpdateSettingsAction` → real `SystemSettingsUpdateAction`;
  3. Current domains table was 6 rows for 9 folders — added `Audit`, `Feature`,
     `Notification` with verified examples (each example in the table now
     resolves to a real file; checked one by one).
  **Enforcement already has an owner** — `ActionNamingConventionTest`
  (`test_every_action_is_named_domain_first`, PSR-4 + camelCase DI checks) is
  green (3 passed) and handles the exception family: the phase doc's proposed
  one-liner Arch test is not written, per § 2.3.
- **B3 measured 75, not 37, and needed no doc lines.** Sources of truth
  diffed programmatically (one-off, PHP, kept out of the repo per § 2.5):
  - `SystemSettingRequest::rules()` → **66 keys**, each found in `docs/base/` —
    **0 undocumented**;
  - `UpdateMailSettingsRequest::rules()` → the **9 `mail_*`** keys, all in
    `features/notifications.md` (mail transport is its own request since
    Phase 9 — the brief's single-request view of the key list is incomplete);
  - the reverse direction: `settings.md`'s explicit key block is a
    **66↔66 exact match** with `rules()` (diffed both ways — nothing listed is
    dead, nothing live is missing); the two suspicious expiry-key names in the
    prose are there *as removed legacy keys*, past tense — correct, left alone;
    `last_activity_at`/`disk_total_space`/`system_settings_key_unique` are a
    column, a PHP function and a lang key, not settings.
  D-5 ("37+" was a floor) holds; the honest number is 66 + 9.

---

## 5. Group C — Security, RBAC, Feature Flags & Operations Documentation

Goal: the authorization matrix, flag semantics, backup/retention and hardening
docs describe the runtime as it answers today.

### P16-C1 — RBAC Permission Catalog & Authorization Docs Audit

- **Depends on:** —
- **Task:** diff `App\Support\PermissionCatalog` (measured 2026-11-04:
  grouped private constants `USERS`, `ROLES`, `PERMISSIONS`, `SETTINGS`,
  `STORAGE`, `BACKUP`, `NOTIFICATIONS`, `FEATURES`, …) against
  `docs/base/security/authorization.md` and
  `docs/base/features/roles-permissions.md` — every permission present, every
  role's permission set correct, `Gate::before` superadmin semantics and the
  **no-route `storage.manage` consumer** note (Phase 14 D) still accurate.
  Also verify the feature-flag doc set (`docs/base/features/feature-flags.md`,
  `FeatureCatalog`) against the fail-closed `database` store behaviour and
  `FeatureFlagSeeder` — flag OFF = 403 on **both** channels, no superadmin
  bypass (`AGENTS.md` § Feature flags).
- **Acceptance:** permission-by-permission table, role-by-role matrix,
  flag-by-flag table; zero doc rows without a code counterpart and vice
  versa; corrections cite the pinning test (`StorageAuthorizationTest`,
  `FeatureFlagMiddlewareTest`, `VantagePermissionGateTest`, the Phase 15 C
  rows).
- **Tests:** none new — catalog rows are already pinned by role-gate tests;
  the audit cites them.
- **Docs:** `docs/base/security/authorization.md`,
  `docs/base/features/roles-permissions.md`,
  `docs/base/features/feature-flags.md`.

### P16-C2 — Storage, Backup & Data Retention Docs Audit

- **Depends on:** —
- **Task:** reconcile
  `docs/base/infrastructure/backup-disaster-recovery.md` and
  `docs/base/operations/retention.md` against Phase 14 as shipped: **audit
  logs are never pruned** (permanent retention), **only the `tmp` disk is
  cleaned by TTL** (`temp_file_retention_hours`, 1–8760, default 24),
  `CleanTemporaryStorageJob` daily on `sweeps` plus the threshold dispatch,
  `ExecuteSystemBackupJob` single-flight lock, dedicated `backups` disk, S3
  readiness and the pentest findings #1–#8 (plaintext archives refused in
  production, https-only S3 endpoints, world-writable modes refused).
  - **Path correction:** the brief cites `docs/infrastructure/...` and
    `docs/operations/retention.md` — the real files live under `docs/base/`
    (the top-level `docs/operations/` holds only a secrets example).
    Recorded as D-3.
- **Acceptance:** each retention/backup statement in the two docs maps to the
  class or config that enforces it; the deferred runtime evidence (real S3,
  restore round-trip, live rebase — Phase 14 close note) is stated in the
  docs as deferred, not as verified; the **restore procedure is written**
  (`SystemBackupRunner` has no restore surface — the doc must tell the
  operator how restore actually happens: manual mysqldump/untar).
- **Tests:** none new (`BackupStrategyTest`, `DataRetentionTest` already pin
  behaviour).
- **Docs:** the two files above, plus `docs/base/infrastructure/storage.md`
  where the capacity numbers drift.

### P16-C3 — Security Hardening & CSP Documentation

- **Depends on:** —
- **Task:** verify `docs/base/security/` (eight files; `web-security.md`,
  `security-baseline.md`, `rate-limiting.md` are the load-bearing ones)
  against Phase 13 as shipped and Phase 15 D as extended: header set and CSP
  (nonce-based `script-src`, no `X-Frame-Options` — `frame-ancestors 'none'`
  answers it, D-1 of Phase 13; CDN origins + `'unsafe-eval'` scoped to
  `pulse.*`/`vantage.*` only), CSRF group replacement and the reachable 419,
  CORS default-deny `api/*`-only with derived credentials, composite
  identifier+IP rate keys (`login-ip`), HTML 500 masking, and the weekly
  dependency-audit cadence.
- **Acceptance:** every claim in those docs cites the pinning test
  (`SecurityHeadersTest`, `ContentSecurityPolicyTest`, `CsrfProtectionTest`,
  `CorsPolicyTest`, `CompositeRateLimitKeyTest`, `SecurityAuditTest`); any
  drift corrected toward code-with-ADR, or flagged as a defect if the code
  broke a documented contract.
- **Tests:** the cited suites, run once (`--filter=Security` green — the
  Phase 13 gate).
- **Docs:** `docs/base/security/*` (row corrections only).

### Group C — as implemented (2026-11-04)

Audit-and-close as specified: the maps were built from the code, corrections
were made only where a doc row had no counterpart or contradicted one. Five
files changed, all of them rows — no design prose was rewritten.

**C1 — RBAC / flags.** Diffed `PermissionCatalog` (36 permissions, measured
from the grouped constants) against the three docs. Three stale spots fixed:

| File | Drift | Correction |
|---|---|---|
| `security/authorization.md` | claimed a `roles.manage` permission | per-operation gates (`roles.create/update/delete/restore/force_delete/assign_permissions`), verified against `routes/web.php` `can:` strings |
| `dependencies/overview.md` | table listed `users.edit`, `roles.manage`, `permissions.manage` (none exist) and was malformed markdown | real names, prefixed "illustrations; `PermissionCatalog` is the authoritative list" |
| `features/feature-flags.md` | flag↔permission table missing `storage_management` (`storage.view/manage`, `backup.execute`); no-flag row missing `api_docs.view` | both rows added, verified against `FEATURE_BY_RESOURCE` and `UNMAPPED_RESOURCES` |

`roles-permissions.md` survived: its table mirrors the catalog and its own
paragraph documents the `roles.manage` drift it once had. `Gate::before`
semantics and the fail-closed seeder behaviour match `PermissionSeeder::matrix()`
and `FeatureFlagSeeder` as read.

**C2 — backup / retention.** `backup-disaster-recovery.md` was accurate on
every Phase 14 mechanism (retry_after/timeout pair, `$tries=1` +
`WithoutOverlapping`, 5 GB cap, mandatory production encryption, DR honesty).
Its one required gap — the generic 8-step "Restore Procedure" — is now a
concrete operator procedure with the artifact layout **verified from the
shipped vendor source**, not invented: zip named `Y-m-d-H-i-s.zip`
(`filename_prefix` empty), `db-dumps/mysql-<db>.sql` as plain SQL
(`database_dump_compressor => null`), storage files under their absolute path
minus the leading slash (`files.relative_path => null`), ZipCrypto
(`encryption => 'default'`) so `unzip -P` opens it. The round trip stays
**deferred, stated as deferred** (the P15-C4 row it belongs to).
`retention.md` needed four corrections where the doc promised what no code
does:

| Row | Doc said | Code does |
|---|---|---|
| Password reset tokens | 60 min | 15 min (`PASSWORD_RESET_EXPIRE_MINUTES` default, `config/auth.php`) |
| Revoked tokens | 30-day investigation window | deleted on revocation, no archive |
| Failed jobs | 7 days | no scheduled prune — operator policy, now labelled as such |
| Application logs | 30 days | default channel is `stack → single` (one growing file); `daily`/`LOG_DAILY_DAYS` is opt-in |

The Configuration block listed `retention.*` keys that no code reads; it now
separates the four real `SystemSetting` knobs from the design targets, adds the
Vantage sweep row (`vantage.retention_days`, 14) and states the notification
sweep prunes **read** rows only. Audit-logs-never-pruned is enforced, not just
promised (`activitylog.delete_records_older_than_days => null`).

**C3 — security docs.** `web-security.md` and `security-baseline.md` read
clean against Phase 13/15 (no `X-Frame-Options`, scoped `'unsafe-eval'`, CSRF
419, CORS default-deny — each already cites its pinning test). Two fixes:
the rate-limiting table was missing the `system-backup` limiter (3/hour
composite key, hardcoded, flash response — `POST /storage/backup` sits
*outside* the `user-state-actions` group, now stated in the ladder), and
`web-security.md` § Security Audit said "Policy-based" authorization, which
contradicts the project's route-level pattern — corrected to name `can:` +
`PermissionCatalog` and the single `UserPolicy`. Spot-grep for the known
renamed-away strings (`roles.manage`, `users.edit`,
`password_security_sweep_timezone`, `X-Frame-Options`) across `docs/base/`
returned only deliberate historical/"this used to be wrong" mentions.

Gate runs: `--filter=Security` **139 passed / 718 assertions**, Pint clean
(486 files). No production code changed; every correction moved the doc toward
the code, which is the direction this phase's spec 2 fixes.

---

## 6. Group D — Audit Trail, Activity Logs & Operational Runbooks

Goal: one complete event map, and runbooks an operator can follow as written.

### P16-D1 — Action-First Audit Log Event Mapping Audit

- **Depends on:** —
- **Task:** enumerate every `Auditable::audit()` call site and build the
  single event map the repo has never had: event name → emitting class →
  subject → causer semantics (including `properties.causer = 'SYSTEM'` for
  system-initiated rows: scheduled backup, capacity guard, inactivity lock,
  account lock, temp cleanup).
  - **Current state (measured 2026-11-04):** the grep-visible event strings
    number ~19 (`user.*`, `role.*`, `backup.triggered`/`backup.failed`,
    `mail_setting.updated`, `system_setting.updated`,
    `storage_setting.updated`, `notification_channels.updated`,
    `temp_storage.cleaned`, `test_mail.sent`/`failed`, …) plus
    `storage.capacity_warning` in `StorageCapacityGuard` (missed by the naive
    suffix pattern — evidence the map must be built from call sites, not
    strings). Events are currently documented piecemeal in
    `notifications.md`, `backup-disaster-recovery.md`,
    `dependencies/overview.md`; **no doc lists them all** — the brief's
    target `docs/base/architecture.md` does not exist (see D-2); the map
    belongs in `docs/base/features/audit-trail.md`.
- **Acceptance:** a `## Event Map` section in `docs/base/features/audit-trail.md`
  covering every call site, each row citing the owning Action/Job/Service;
  the brief's four example events (`user.created`, `backup.triggered`,
  `mail_setting.updated`, `storage.capacity_warning`) all present; zero audit
  writes outside the transaction by the Action layer (`AGENTS.md` hard rule —
  the map surfaces any observer-based writer if one crept in).
- **Tests:** `ActionFirstAuditTest` already pins the zero-observer property;
  cited, not duplicated.
- **Docs:** `docs/base/features/audit-trail.md` (+ per-module docs cross-linked).

### P16-D2 — Operational Playbooks & Disaster Recovery Alignment

- **Depends on:** P16-C2
- **Task:** walk `docs/base/operations/` (`deployment.md`, `environment.md`,
  `troubleshooting.md`, `retention.md`) and the runbook-adjacent sections of
  the backup doc as an operator following them cold: queue worker invocation
  (`bin/run-workers.sh backups` for the `backups` queue+connection pair),
  scheduler entries, `ExecuteSystemBackupJob` / `CleanTemporaryStorageJob`
  failure handling (`failed()` logging event + `request_id` via
  `ReadsCorrelationId` — the `AGENTS.md` job standard), the Pennant
  fail-closed `FeatureFlagSeeder` requirement, and `timezone:rebase
  --force` in production.
- **Acceptance:** every command, queue name and env key in the runbooks
  resolves against the repo as written (copy-paste provable); a gap list for
  anything an operator must currently learn by reading source; runbooks
  corrected, no runtime change.
- **Tests:** `JobConventionTest` cited for the job standard.
- **Docs:** `docs/base/operations/*`, `docs/base/infrastructure/queue.md`
  (row corrections only).

### Group D — as implemented (2026-11-04)

**D1 — Event Map.** Built from every `->audit()`/`auditBulk()` call site in
`app/` (not from event strings — the string grep undercounts: it misses
`storage.capacity_warning`'s guard and the four dynamic callers behind
`PersistsRole`, `InactivityLock`, `SystemBackupRunAction` and
`AuditsSystemActivity`). **48 distinct events**, all mapped in a new
`## Event Map` section of `docs/base/features/audit-trail.md`, grouped by
subject (Auth 13, User 16, Role 5, Feature 2, SystemSetting 12), each row
citing the owning Action/Job/Service/Middleware. The four brief examples
(`user.created`, `backup.triggered`, `mail_setting.updated`,
`storage.capacity_warning`) are present. The SYSTEM semantics are recorded as
the code implements them: causer stays **null** and `properties.causer =
'SYSTEM'` is added for scheduled backup, capacity guard outside a request,
inactivity locks, account-lock sweep and password-expiry sweep. Zero
observer-based writers restated with `ActionFirstAuditTest` as the pinning
owner (run green, 38/101 with `DataRetentionTest`).

**D2 — Runbooks.** Walked `operations/deployment.md`, `environment.md`,
`troubleshooting.md`, `retention.md` and the queue/backup operator sections
cold. Verified-as-resolvable, unchanged: `bin/run-workers.sh backups`
(local/cron/queue/backups all exist in the script), `schedule:run`,
`backup:list`, the `backups` connection pair, `ReadsCorrelationId` and
`JobConventionTest` (queue.md), fail-closed seeder (feature-flags.md),
`timezone:rebase {--force}` (settings.md + changelog). Four real defects
fixed:

| File | Defect | Fix |
|---|---|---|
| `troubleshooting.md` | audit issue told the operator to check a non-existent `audit.enabled` setting | rewritten around the Action-first truth: no switch exists, missing call is the defect |
| `troubleshooting.md` | Debugging Tools table malformed (`\|\|` rows) with the Pulse rows interleaved out of order | valid table, rows ordered |
| `troubleshooting.md` | mail issue started at `MAIL_MAILER`, which Phase 9 demoted to fallback | first check is now `system_settings` transport + the send-test probe; worker advice names `bin/run-workers.sh local`, not bare `queue:work` |
| `deployment.md` | no process-start step at all — a deploy following it could run with no worker consuming `backups` | added "Processes an operator must start" (three `bin/run-workers.sh` lines + restart-after-config rule) and the fail-closed seeder warning on step 5 |

**Gap list (operator must currently learn from source):** none left for the
walked surface — each command above resolves against the repo as written.
Runtime change: none, as specified.

---

## 7. Group E — Automated Verification, Trackers Reconciliation & Final Closure

Goal: the checks that keep the phase's result true, and the honest closing
state of every tracker.

### P16-E1 — Markdown Syntax & Internal Link Integrity Verification

- **Depends on:** P16-A2
- **Task:** pin the two mechanical properties A2 measured as a PHPUnit gate —
  `tests/Arch/DocumentationLinkIntegrityTest.php` (or `tests/Feature/`,
  whichever the existing layout favours): every relative link in `docs/**/*.md`
  resolves to a file that exists, and no fenced code block lacks an info
  string *where A2's baseline shows the convention already holds*. Heading
  hierarchy is asserted only if the sweep finds near-total compliance;
  otherwise it is recorded as house style, not gated.
  - Deliberate exclusions to write into the test: external URLs (no network in
    CI), anchors within a file, and any path inside `docs/planning/` phase
    docs that legitimately cites deleted branches by name.
- **Acceptance:** green on the corrected tree; sabotage: one broken relative
  link turns it red; the run cost is bounded (a single `FilesystemIterator`
  walk, <1 s).
- **Tests:** the new integrity test.
- **Docs:** `docs/planning/ai-execution-guide.md` (one line: docs links are
  CI-gated from Phase 16 on).

### P16-E2 — Comprehensive Regression Test Run

- **Depends on:** all A–D tasks
- **Task:** full gates, in this order: `vendor/bin/pint --test` (full tree),
  `vendor/bin/phpstan analyse --memory-limit=1G` (against the committed
  ledger), `php artisan test`. Doc edits in this phase must not move any
  number except by adding the E1 test; any other movement is a regression to
  explain, not to absorb.
- **Acceptance:** all three exit 0; the measured suite count is recorded here
  and carried into `progress.md` § *Test Status* verbatim (reproduced by CI,
  not hand-carried upward).
- **Tests:** the suite itself.
- **Docs:** `progress.md`.

### P16-E3 — Planning Trackers Reconciliation & Project Closure

- **Depends on:** P16-E1, P16-E2
- **Task:** reconcile every planning surface against the measured state:
  - `docs/planning/task-tracker.md` Phase 16 rows → `DONE` with evidence
    (each row cites its "as implemented" section of this file);
  - `docs/planning/qa-tracker.md` — confirm no open finding references stale
    doc paths fixed in this phase;
  - `docs/planning/progress.md` — row 16, § *Documentation Status* (each area
    re-marked "Verified against code on <date>" rather than bare "Complete"),
    § *Test Status*;
  - `docs/planning/implementation-roadmap.md` Phase 16 status;
  - `docs/base/README.md` index links (E1 gate now proves them, but the
    "last verified" state is the human-facing half).
  Phase 17 (full regression / final review) stays PLANNED — this phase closes
  documentation, not the roadmap.
- **Acceptance:** the four trackers agree with each other and with the repo;
  no row flips to DONE without its evidence line; status history per
  `IN_PROGRESS → REVIEW → DONE` (never skip REVIEW).
- **Tests:** none.
- **Docs:** the four files above.

---

## 8. Deviations from the brief (recorded, not silently applied)

| # | The brief says | The code says | Decision |
|---|---|---|---|
| D-1 | "Markdown view renderers" verified by UI render tests | no in-app Markdown renderer exists (zero `resources/views` matches); docs are repository Markdown | A2/A3 verify files + the Scramble interface; no viewer is invented to test |
| D-2 | `docs/base/architecture.md` (B2, D1) | the file moved: `docs/base/architecture/architecture.md`, naming contract in `naming-conventions.md` | audits target the real paths; the brief's path is itself the kind of drift P16-E1 gates |
| D-3 | `docs/infrastructure/backup-disaster-recovery.md`, `docs/operations/retention.md` (C2) | real paths are `docs/base/infrastructure/…` and `docs/base/operations/…` (top-level `docs/operations/` is only a secrets example) | same as D-2 — corrected citations in every task above |
| D-4 | New `tests/Feature/Documentation/DocsUiRenderTest.php` (A3) | `/docs/api` render gate exists as `tests/Feature/Api/ApiDocsUiRenderTest.php` (+ `ApiDocumentationTest`, `OpenApiExportTest`) | P16-A3 extends the owners instead of a duplicate file (rule § 2.3) |
| D-5 | "all 37+ validated SystemSetting keys" (B3) | `SystemSettingRequest::rules()` carries ~60 key entries | the enumeration is generated from the source of truth at run time; "37+" treated as a floor, the table is the truth |
| D-6 | Audit events mapped "in `docs/base/architecture.md`" (D1) | events documented piecemeal across three module docs; the audit-trail feature doc is the natural home | event map lands in `docs/base/features/audit-trail.md`; architecture docs cross-link it |
| D-7 | Markdown "linting standards" (Group A) | no linter exists and adding one is a dependency decision | checks built on PHP primitives in tests (rule § 2.5); no `markdownlint`/package without a future ADR |

---

## 9. Execution order

```text
A1 ──┐
A2 ──┼→ A3 ──┐
B1, B2, B3 ──┼→ E1 ─┐
C1, C2, C3 ──┤       ├→ E3
D1 ──────────┤  D2 ← C2
             │       │
all A–D ─────┴→ E2 ───┘
```

Groups A–D are independent reads and can interleave (they touch disjoint doc
files, so no merge conflicts by construction; E1/E3 touch shared trackers last).
D2 follows C2 because both edit the backup/retention pair.

---

## 10. Definition of done for the phase

- Every Group B/C/D audit table is recorded in this file's "as implemented"
  sections, with ❌ rows either fixed or filed as defects with regression tests.
- `docs/base/features/audit-trail.md` holds the complete event map; no audit
  write exists outside the Action layer.
- Link-integrity gate (`P16-E1`) green and sabotage-verified.
- `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` · `php artisan test`
  all exit 0, counts recorded from the run.
- `task-tracker.md` Phase 16 rows DONE; `progress.md` row 16 + § Documentation
  Status re-verified with dates; `qa-tracker.md` and `implementation-roadmap.md`
  consistent.
- No runtime behaviour changed. If verification found the code wrong against a
  contract, that fix went through its own regression test + `changelog.md`
  line, and is called out here as a finding, not folded into a doc edit.

---

## 11. Tracker reconciliation

On 2026-11-04 this breakdown and the Phase 16 rows in `task-tracker.md` were
written as **PLANNED**; `progress.md` row 16 cites this document. The rows are
summary only — per-task detail is here (same split as Phases 12–15). Branch at
implementation: `feature/phase-16-documentation-verification`. Nothing flips
without evidence, and nothing is committed by the implementing turn — the
owner commits (`AGENTS.md` hard rule).
