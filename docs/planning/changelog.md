# Changelog

> Records meaningful architecture/planning changes. Do not record meaningless edits.

## Format

Each entry contains:
- **Date**
- **Change** (what was changed)
- **Reason** (why the change was needed)
- **Impact** (what's affected)
- **Related** (ADR/task/requirement ID)

---

## 2024-01-01 — Initial Architecture & Planning

|| Field | Value |
||-------|-------|
|| **Date** | 2024-01-01 |
|| **Change** | Created initial architecture documentation and planning system for Laravel Base Project v2 |
|| **Reason** | Need structured documentation/planning foundation before any implementation |
|| **Impact** | All subsequent phases; this establishes the foundation |
|| **Related** | P0-001 through P0-011 (Phase 0 tasks) |

### Components Created

|| Component | Description | Docs |
||-----------|-------------|------|
|| Architecture Principles | Core principles, separation of responsibilities, single source of truth | architecture/principles.md |
|| Application Architecture | High-level layer diagram, API-first, dependency rules | architecture/architecture.md |
|| Application Boundaries | Transaction boundaries, security boundary, feature availability | architecture/application-boundaries.md |
|| Folder Structure | Recommended directory layout for Laravel app | architecture/folder-structure.md |
|| Dependency Rules | Package governance, Redis compatibility, secret management | architecture/dependency-rules.md |
|| Naming Conventions | Code, DB, table, column naming standards | architecture/naming-conventions.md |
||| ADRs (18 architecture + 7 dependency) | Architecture Decision Records (see decisions.md + decision-records/) | architecture/architecture-decisions.md |
|| Security Baseline | Overall security posture, headers, secrets, error handling | security/security-baseline.md |
|| Authentication | Login, session, lockout, verification requirements | security/authentication.md |
|| Authorization | RBAC, superadmin protection, feature availability | security/authorization.md |
|| Password Security | IM8 policy, history, expiration, reset flows | security/password-security.md |
|| Session Security | Token strategy, single-session-per-device, revocation triggers | security/session-security.md |
|| Rate Limiting | Per-endpoint limits, Redis compatibility, separation from failed-login | security/rate-limiting.md |
|| Web Security | HTTPS, HSTS, CSP, CSRF, CORS, security headers | security/web-security.md |
|| Data Protection | Encryption, storage, backup, retention | security/data-protection.md |
|| API Architecture | Versioned API, resources, response conventions, error contract | api/api-architecture.md |
|| API Versioning | Versioning strategy, deprecation policy | api/versioning.md |
|| API Response Contract | Envelope structure, pagination, filtering, sorting | api/response-contract.md |
|| API Error Contract | HTTP status codes, error codes, security in errors | api/error-contract.md |
|| API Documentation | Scramble approach, required sections, tools | api/documentation.md |
|| Database Conventions | ID strategy, naming, constraints, soft-delete, enums | data/database-conventions.md |
|| Soft Delete Strategy | When to use, decision matrix, implementation | data/soft-delete.md |
|| Relationships | Eloquent patterns, loading strategy, constraints | data/relationships.md |
|| Concurrency | Transaction boundaries, race conditions, retry | data/concurrency.md |
|| Queue | Database queue, Redis compatibility, job patterns | infrastructure/queue.md |
|| Redis Compatibility | When Redis is optional/required, abstractions | infrastructure/redis-compatibility.md |
|| Cache | Cache strategy, invalidation, TTL | infrastructure/cache.md |
|| Storage | Disk strategy, public/private/tmp, cloud | infrastructure/storage.md |
|| Logging | Channels, levels, correlation IDs, security | infrastructure/logging.md |
|| Observability | Monitoring structure, AUDIT vs Telescope separation | infrastructure/observability.md |
|| Backup & DR | Backup types, verification, restore procedure, RPO/RTO | infrastructure/backup-disaster-recovery.md |
|| User Management | User state model, operations, lifecycle | features/user-management.md |
|| Registration | Configurable registration, default role | features/registration.md |
|| Roles & Permissions | RBAC via Spatie, permission model, superadmin | features/roles-permissions.md |
|| Feature Flags | Feature availability enforcement, backend-first | features/feature-flags.md |
|| Settings | Structured settings, two-layer config, change management | features/settings.md |
|| Notifications | Channels, categories, mail config, queue integration | features/notifications.md |
|| Audit Trail | Audit package, abstraction, async export, metadata | features/audit-trail.md |
|| Monitoring | Audit/logs/Telescope/health check separation | features/monitoring.md |
|| Testing Strategy | Types, principles, coverage matrix | testing/testing-strategy.md |
|| Testing Matrix | Coverage grid per feature | testing/testing-matrix.md |
|| Definition of Done | 13 criteria checklist | testing/definition-of-done.md |
|| Deployment | Deployment steps, CI/CD, rollback, zero-downtime | operations/deployment.md |
|| Environment | Env vars, config files, secrets management | operations/environment.md |
|| Retention | Data type-specific retention schedule | operations/retention.md |
|| Troubleshooting | Common issues, debugging tools, log locations | operations/troubleshooting.md |
|| Planning README | Planning system overview | planning/README.md |
|| Requirements | Functional & non-functional requirements | planning/requirements.md |
|| Feature Matrix | Feature coverage map with priority/phase | planning/feature-matrix.md |
|| Implementation Roadmap | Phases 0-17, dependency map | planning/implementation-roadmap.md |
|| Task Tracker | Full task list (100+ items) with status | planning/task-tracker.md |
|| Dependency Map | Feature-level dependency graph | planning/dependency-map.md |
|| QA Tracker | All QA scenarios organized by feature | planning/qa-tracker.md |
|| AI Execution Guide | Rules for AI agents working on repo | planning/ai-execution-guide.md |

---

## 2025-09-14 — Logging Strategy Specification

|| Field | Value |
||-------|-------|
|| **Date** | 2025-09-14 |
||| **Change** | Rewrote `infrastructure/logging.md` with the full Application Logging Requirements spec: five-tier logging model (Audit Trail / Application Logs / Security Logs / Server Logs / Telescope separation), failure classification (expected vs unexpected), structured event/action names, context envelope, transaction-failure semantics, request/correlation ID propagation, centralized exception handling, logging privacy/redaction, and retention. Added ADR-013 and logging QA scenarios (QA-LOG-001 through QA-LOG-008) to the QA tracker. |
|| **Reason** | Establish single source of truth for Phase 1 implementation; prevent ad-hoc logging that conflates audit/application logs, logs sensitive data, or creates false audit records on rollback. |
|| **Impact** | All subsequent phases that emit logs or audit records; Phase 1 (correlation ID middleware), Phase 10 (audit trail), Phase 11 (Telescope). |
|| **Related** | ADR-013, FOUND-008, AUDIT-001/AUDIT-003, MONITOR-001, RETAIN-001, QA-LOG-* |

---

## 2026-10-15 — Phase 11 breakdown restructured into Groups A–F

| Field | Value |
|-------|-------|
| **Date** | 2026-10-15 |
| **Change** | `phase-11-monitoring-observability.md` restructured to the Phase 10 group pattern: **Group A** = views/UI/sidebar (Pulse entry, system health page), **Groups B–F** = permissions, gates, routing, logic, audit (N/A, stated), queue conventions, verification/docs. New tasks: `health.view` + `health` flag + `/health` web route + its seeder row (P11-B3), `HealthController@index` (P11-C1), queue conventions renumbered from the colliding "MON-P11-05" to P11-E1..E3. The access-gating decision table, slow-query diagnosis ladder and the VM runbook moved OUT of `implementation-roadmap.md` INTO §3/§7 of the breakdown — the roadmap keeps a status summary + link only. Cross-refs in `phase-10-audit-trail.md` (§6 → Group B, MON-P11-05 → P11-E1..E3) updated. |
| **Reason** | Two docs held the same Phase 11 detail, so neither was the single source of truth; MON-P11-05 named two different tasks (docs sweep AND queue conventions) in one file; and the roadmap carried runbook-level operational detail no other phase's roadmap section carries. The user also asked that any view/UI work land in Group A with the sidebar, and that permissions/feature flags be explicit per task. |
| **Impact** | `implementation-roadmap.md` (Phase 11 section slimmed), `phase-10-audit-trail.md` (two cross-refs), task IDs P11-*. No code, no behaviour change. |
| **Related** | P11-A1..A4, P11-B1..B4, P11-C1..C2, P11-E1..E3, P11-F1..F6; MON-P11-01..08 (renumbered); P10-E13, P10-E14 |

---

## 2026-10-16 — Queue conventions promoted to a base standard (docs)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | The Group E conventions moved from "how this repo's jobs happen to be written" to a normative standard in three places: `docs/base/infrastructure/queue.md` gains § *The standard every job follows* (named queue from `QueueName` via `onQueue()`, explicit `$tries`/`$timeout`/`$maxExceptions`/`backoff()`, `failed()` with `request_id` via `ReadsCorrelationId`, after-commit dispatch, idempotency) plus the two ways to break the correlation contract on purpose; `docs/planning/ai-execution-guide.md` rule 35 and `AGENTS.md` hard rules now point every future change at it. |
| **Reason** | The conventions were only enforced by a test and described in prose; without a normative section a new job could be reviewed as "fine, different style" and drift — and the two silent failure modes (a job on a queue nobody listens to, `request_id` read from a container binding that does not exist in the worker) look like working code until they are needed. |
| **Impact** | Every future job in `app/Jobs`; docs only, no behaviour change. `JobConventionTest` remains the enforcement. |
| **Related** | P11-E1..E3, `phase-11-monitoring-observability.md`, `docs/base/infrastructure/queue.md` |

---

## 2026-10-16 — Phase 11 Groups A–C and E shipped

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | Groups A–C: `pulse.view`/`health.view` in `PermissionCatalog`, the `viewPulse` gate override (permission, not `environment('local')`), the `health` flag + `GET /health` → `health.index` web dashboard (`HealthController@index`, one card per check, read-only), a **Monitoring** sidebar group (Pulse + System Health, each behind its own permission AND flag), and 16 gate/render tests. Group E: `App\Support\QueueName` (named queues `exports`/`sweeps`, set via `onQueue()` in the constructor — a `public $queue` property is a class-load fatal against the `Queueable` trait that `php -l` misses), `$maxExceptions` on the export job, the dispatch-time `request_id` propagated into every job payload (`Queue::createPayloadUsing` in `AppServiceProvider`, read by `Jobs\Concerns\ReadsCorrelationId` in every `failed()`), `bin/run-workers.sh` now listens `default,exports,sweeps`, and `JobConventionTest` asserts the conventions by reflection over every class in `app/Jobs`. |
| **Reason** | The permission half and both dashboards were the phase's core; Group E is upstream of every dashboard — a job that silently rides `default`, declares no retry chain and logs no correlation id is invisible in review and only shows up as a stuck queue or a failure nobody was told about. The API twin `/api/v1/health` stays deliberately ungated (uptime monitor caller). |
| **Impact** | Existing installs MUST run `php artisan db:seed --class=FeatureFlagSeeder` (fail-closed Pennant store) and restart queue workers with the new `--queue` list, or named-queue jobs wait forever. Worker deployments that overrode `QUEUE_NAME` keep their override. |
| **Related** | P11-A1..A4, P11-B1..B4, P11-C1, P11-E1..E3, P11-F1, P11-F6 |

---

## 2026-10-14 — Phase 10: the audit export handed a spreadsheet a formula

| Field | Value |
|-------|-------|
| **Date** | 2026-10-14 |
| **Change** | `GenerateAuditExport::row()` now runs every cell through a `cell()` helper that prefixes a `'` when the first non-whitespace character is `=`, `+`, `-` or `@` (CWE-1236). New `tests/Feature/Audit/CsvInjectionPentestTest.php` — 4 probes. |
| **Reason** | The `actor` and `target` columns carry `users.name`, and `name` is free text on `PUT /profile` — `UserUpdateAction` runs `strip_tags()`, which drops markup and not a leading `=`. Any authenticated account could set its display name to `=cmd\|'/c calc'!A0`, act, and have that string sitting in the extract the incident responder opens on their own workstation. `fputcsv()` does not help: it quotes fields containing a comma or a quote, and neither is what makes a cell a formula. It survived review because the web viewer escapes the same payload correctly, so nothing in the UI looks wrong — the defect is in the file, not the page. |
| **Impact** | The `=` `+` `-` `@` prefix is only ADDED where it is needed, so an ordinary name reads as it always did — pinned by `test_an_ordinary_name_is_not_rewritten`, because a fix that stripped the payload instead of quoting it would pass the security probe and silently destroy the evidence the extract exists to provide. Measured cost: **+0.0115 ms/row** against the 0.245 ms/row baseline, so ~4.7%; the 80-second timeout still covers roughly 290 000 rows. Applied to every cell rather than an allowlist of the two or three that carry user text, so the next user-controlled column cannot be forgotten. The EMAIL fallback in `causerLabelFor()` was investigated and is **not** a second vector — the `email` rule requires `local@domain` on every write path. |
| **Related** | CWE-1236, P10-E3, `phase-10-audit-trail.md`, `docs/security-audit-report.md` |

---

## 2026-10-13 — Phase 10: export row build and viewer option lists

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | Two performance fixes from a measured pass. **(1)** `GenerateAuditExport::row()` read the `properties` attribute four times per row; `properties` is cast `'collection'` and Eloquent does not memoise a primitive cast — `HasAttributes::transformModelValue()` calls `castAttribute()` on every read and the `collection` case is a fresh `json_decode` each time (warm read 16.3µs vs cold 15.1µs, ratio 1.02×; `classCastCache` empty). Now read once into a local. **(2)** The four filter dropdown option lists in `AuditIndexAction` are cached (TTL 3600, busted on write by `Auditable::audit()` and `auditBulk()`), because `sourceOptions()` is `DISTINCT json_extract(properties, …)` — the one filter no index can serve on any engine. New test `test_a_new_event_name_reaches_the_cached_filter_dropdown` pins the invalidation. |
| **Reason** | The export was 99.5% PHP time (12.4ms of SQL across 2466ms at 5000 rows), so the database was never the constraint — the cast was. The option lists ran four unindexed `DISTINCT` scans on every page load and grew with the table (4.1ms at 2k rows → 12.2ms at 20k). Caching them does **not** contradict `AuditIndexAction`'s "no cache" note: that argues about the per-viewer ROW LIST, and option lists are global and identical for everyone. |
| **Impact** | Interleaved same-process A/B: option lists −90% (2k rows) / −96% (20k rows); index page −19%, queries 10 → 6; `row()` build −31% (0.3075 → 0.2128 ms/row), ~470ms saved on a 5000-row export. Output verified byte-identical. New staleness window: a writer that bypasses `Auditable` would leave a stale dropdown for up to an hour — no such writer exists today. Full suite 1924 passed / 6482 assertions. |
| **Related** | P10-C4, P10-C7, P10-E3, `phase-10-audit-trail.md`, `audit-trail.md` § Reader |

---

## 2026-10-13 — Phase 10: the audit export silently dropped rows

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | `GenerateAuditExport` ordered `created_at DESC, id DESC` and then chunked with `chunkById(500)`. `chunkById` cursors on the primary key — it filters `WHERE id > <last id of the page>` and rewrites the ordering to `id ASC` — so the sort key and the cursor disagreed. Measured on 1200 rows: the file held **999 lines, 500 of them distinct**. 200 rows never reached the CSV and 499 appeared twice. Now orders by `id` alone via `chunkByIdDesc`/`chunkById` on `$direction`. `$sort` removed from the job and from `AuditExportRequestAction::run()` (and its two controller call sites) rather than left as a parameter that no longer reaches the query. New regression test `test_the_export_holds_every_row_once_past_the_chunk_boundary`. |
| **Reason** | Found by a performance pass, not by a test. `AuditExportTest::test_the_export_query_count_does_not_grow_with_the_row_count` measures 3 and 40 rows — both inside a single 500-row chunk, so it is structurally blind to the cursor and passed green over a job that was losing rows. A compliance extract that drops rows without saying so is the worst failure this feature has, and the only defense is a test that crosses the boundary. |
| **Impact** | Export file contents for any table over 500 rows. Row **order** in the file is unchanged on an append-only audit table: `id` is already the tiebreaker `ActivityQueryRequest::SORTABLE` sorts on, so `id` order and `created_at` order are the same sequence. Docs updated in `audit-trail.md` § Asynchronous Export. Full suite 1923 passed / 6480 assertions. |
| **Related** | P10-C8, P10-E3, `phase-10-audit-trail.md`, `audit-trail.md` § Asynchronous Export |

---

## 2026-10-13 — Phase 10 Group F closed

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | `tests/Feature/Audit/AuditPentestTest.php` (62 probes) — the file P10-F2 named and which did not exist until now. Its probes were previously scattered across five files, and two of the seven named were thin: authorization ran at three privilege levels rather than four (the missing one being NO ROLE AT ALL, which takes a different path through `Gate::before()`), and "unbounded export row count" had no assertion whatsoever. Injection is now split by how each field actually reaches the query — `sort`/`direction` reach `orderBy()` as an IDENTIFIER and must be refused; `event`/`source`/`subject_type` reach `where()` as a bound VALUE and are correctly NOT refused, so demanding an error there would have been testing a rule the application does not claim. Also: `implementation-roadmap.md` Phase 10 → DONE, `qa-tracker.md` audit rows reconciled with the test that proves each. |
| **Reason** | A pentest pass that asserts the right thing in the wrong way is worse than none, because it reads as coverage. Splitting the two families is what makes the file say something true about this module rather than something true about itself. |
| **Impact** | P10-F2, P10-F3, P10-F6. `AuditViewerAccessTest`, `AuditExportTest` and `AuditViewerFilterTest` keep their cases — the pentest adds the adversarial angle, not a replacement. |
| **Related** | P10-F2, P10-F3, P10-F6, AUDIT-004, AUDIT-005 |

---

## 2026-10-13 — Phase 10: the audit trail reached the API

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | `Api\V1\Audit\AuditController` + `ActivityResource` + `AuditExportResource`. The reader and the export existed on ONE channel: every WRITE had gone through the shared Actions since day one, but `GET /activity-logs`, the detail route and the whole export surface had no API counterpart. Also `{activity}` constrained to `whereNumber` on BOTH channels — `/activity-logs/exports` is one segment shorter than the download route, so an unconstrained parameter swallowed it depending on registration order. |
| **Reason** | ADR-001 ("all core capabilities have a clean API boundary") failing silently. It is not a rule a review can hold an implementation to; it is only visible when the two route tables are diffed. |
| **Impact** | P10-F2 gained a second surface to probe. `retention.md` and the base feature doc gained an API surface table. |
| **Related** | ADR-001, ADR-002, P10-F2, P10-F3, `phase-12-api-v1.md` |

---

## 2026-10-13 — Audit metadata derived from whether a request is real

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | `Auditable::auditContext()` now derives `source`/`ip`/`user_agent` from whether a route is actually being handled. Outside one they are `system`/`null`/`null`; before, a scheduler's row said `source => web` and an IP of `127.0.0.1`, because both are DEFAULTS of Laravel's synthetic console request rather than observations. `App\Support\SafeUrl` extracted from a private method on `NotificationInboxAction` when the API inbox put the same same-origin question in a second place. |
| **Reason** | `source` was a CONVENTION — two writers used `AuditsSystemActivity` and nothing stopped the next job from forgetting. The audit page filters on `source`, so a mislabelled row puts scheduler work under "web" and vice versa. |
| **Impact** | Two obvious discriminators do not work and are documented so nobody retries them: `runningInConsole()` is true for the test runner, and `server('REQUEST_METHOD')` is `'GET'` in console too. |
| **Related** | P10-D1, P10-D2 |

---

## 2026-10-13 — Phase 10 follow-ups E9–E12 implemented

| Field | Value |
|-------|-------|
| **Date** | 2026-10-13 |
| **Change** | E9 `audit-export` ceiling moved from a literal into `system_settings` (and the Export tooltip now reads it, so the number shown and the number enforced are one fact). E10 the export table resolves in place — a fragment endpoint returning the same partial, polling only while a row is `pending`. E11 the success flash was flashed under `success` and rendered by no admin view; `status` is the house key. E12 `$tries` 1 → 3 with a backoff and a per-attempt log line. Alongside: `failed()` now unlinks the COMPUTED export path as well as the recorded one, because the recorded one is written after the write lands. |
| **Reason** | All four were visible as "I clicked the button and nothing happened". E9 also carried a live defect: a hardcoded label beside a configurable number would have become a lie the first time the setting was edited. E12 introduced the orphan-file case that the `failed()` fix then closed — found in review, before it shipped. |
| **Impact** | Export files are keyed `audit-exports/{user_id}/{id}.csv`, so a retry rewrites rather than orphans. Legacy UUID-path rows keep working untouched; nothing was backfilled. |
| **Related** | P10-E9, P10-E10, P10-E11, P10-E12 |

---

---

## 2025-09-14 — Dependency Documentation

|| Field | Value |
||-------|-------|
|| **Date** | 2025-09-14 |
|| **Change** | Created `docs/base/dependencies/` directory with `overview.md`, `dependency-matrix.md`, `docs/base/governance/dependency-governance.md`, and 7 dependency ADRs in `docs/base/architecture/decision-records/` (DEP-001 Sanctum, DEP-002 Spatie Permission, DEP-003 Activitylog, DEP-004 Telescope, DEP-005 Native Laravel First, DEP-006 Database Queue + Redis, DEP-007 API Documentation/Scramble). Updated `docs/base/README.md` to add Dependencies as section 2. |
|| **Reason** | Establish single source of truth for all dependency decisions; distinguish installed vs planned packages; govern package selection before any `composer require` is permitted. |
|| **Impact** | All phases that introduce Composer packages (Phase 1 FOUND-004/005/006/007, Phase 12 API-003, Phase 14 BACKUP-001); the dependency governance rules. |
||| **Related** | FOUND-004, FOUND-005, FOUND-006, FOUND-007, API-003, BACKUP-001, QUEUE-001, CACHE-001, MONITOR-001 |

---

## 2025-09-14 — Architecture Gap-Closing Pass

||| Field | Value |
||-------|-------|
||| **Date** | 2025-09-14 |
||| **Change** | Closed architecture documentation gaps: created ADRs-001..018 in `planning/decisions.md`, and `docs/base/architecture/decision-records/` (DEP-001 Sanctum, DEP-002 Spatie Permission, DEP-003 Activitylog, DEP-004 Telescope, DEP-005 Native Laravel First, DEP-006 Database Queue + Redis, DEP-007 API Documentation/Scramble). Enhanced: `application-boundaries.md` (transaction/after-commit model + rollback behavior), `audit-trail.md` (transaction boundaries), `queue.md` (after-commit dispatch + idempotency + rollback), `logging.md` (5-way observability classification + correlation ID generation/propagation/propagation to jobs), `observability.md` (5-way classification + security logs), `retention.md` (security logs 90d), `user-management.md` (lifecycle transitions + state distinctions), `authentication.md` (session revocation triggers + last_activity NULL policy), `rate-limiting.md` (precedence + endpoint categories + race conditions), `authorization.md` (system role protection + superadmin bypass table), `soft-delete.md` (deletion policy + cascade-when-justified update to ADR-012), `roles-permissions.md` (system role protection), `monitoring.md` (5-way classification + Security Logs + ADR-008 reference), `settings.md` (`last_activity_at` inactivity grace config), `security-baseline.md` (`last_activity_at` policy), `ai-execution-guide.md` (dependency-aware + transaction + source-of-truth rules), `progress.md`, `base/README.md` (sections index). Updated `planning/decisions.md` (ADR-008  5-way, ADR-013 5-tier, added ADR-014..018). Updated `architecture-decisions.md` (count + all ADR entries 008-018). Updated `task-tracker.md` (RBAC-005 description). |
||| **Reason** | Resolve contradictions between documented and approved architecture decisions; ensure all components, transaction semantics, account state, rate-limit precedence, cascade rules, system-role protection, UI architecture, and observability classification are consistently documented before implementation begins. |
||| **Impact** | All Phase 1-17 implementation tasks; architecture compliance tests; Definition of Done verification. |
||| **Related** | - ADR-001 through ADR-018, DEP-001 through DEP-007, FOUND-008, CORR-001, AUDIT-001/AUDIT-003, MONITOR-001, RETAIN-001, RBAC-001/RBAC-005, SEC-001/002/003, DB-001/DB-002, CACHE-001/002, SET-001, UI-001, FEATURE, QA-LOG-*, QA-AUDIT-*, QA-SEC-*, QA-API-* |