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

## 2026-10-16 — CSP: `'unsafe-eval'` scoped to the Pulse route

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | `AddSecurityHeaders::policy()` gained a `bool $pulse` parameter (`$request->routeIs('pulse')`): only that route's `script-src` carries `'unsafe-eval'` beside the nonce; every other response's policy is byte-identical. `handle()` now also calls `Vite::useCspNonce($nonce)` so Livewire's `@livewireScriptConfig` prints the same nonce the policy carries. Two Pulse views overridden in `resources/views/vendor/pulse/components/`: the layout nonces the two `Pulse::js()` bundle tags (anchored `preg_replace` — a blanket replace would corrupt the `<script>` literals inside the bundles), the theme-switcher gained `@nonce`. `x-pulse` is re-routed to that override by `Blade::component('pulse::components.pulse', 'pulse')`, because the package resolves the root layout through an `anonymousComponentPath` hash namespace that never sees `resources/views/vendor/`. |
| **Reason** | `/pulse` rendered as a 200 that never loaded: all four of its inline `<script>` elements were blocked by the nonce-only policy, and even nonced the inlined Livewire build compiles every `x-data`/`wire:*` expression with `new Function`, which needs `'unsafe-eval'` — so `#[Lazy]` cards stay on their skeletons forever. The relaxation is scoped by route name (Livewire is used nowhere else in this application) rather than weakening the policy application-wide. |
| **Impact** | `/pulse` only; nonce stays mandatory and `'unsafe-inline'` stays absent everywhere. Enforced by `ContentSecurityPolicyTest::test_only_the_pulse_route_grants_unsafe_eval` and `::test_every_script_on_the_pulse_page_carries_the_policys_nonce` (DOM-parsed — a raw-HTML regex counts the Sfdump literals inside the inlined bundles as tags). The two overridden vendor views can drift on a Pulse upgrade; the tests catch nonce regressions, not other drift. |
| **Related** | `docs/base/security/web-security.md`, `app/Http/Middleware/AddSecurityHeaders.php`, P11-A1 |

---

## 2026-10-16 — Pulse cards on a warm cache: objects out of the persistent store

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | `config/pulse.php` now defaults `pulse.cache` to the array store — `env('PULSE_CACHE_DRIVER', 'array')` (was `env('PULSE_CACHE_DRIVER')`, i.e. `null` → the default store). Pulse's `RemembersQueries` caches `[$collection, $duration, $start]` through `CacheStoreResolver`, and `cache.serializable_classes => false` (phase-1 hardening) makes every persistent store hand a cached object back as `__PHP_Incomplete_Class`. The env var stays overridable per environment and is documented in `.env.example`, but any persistent driver reintroduces the crash. Regression test `PulseQueryCacheTest`: forces the default store to the serializing `file` driver, mounts `Livewire\Queues` cold then warm (`Livewire::withoutLazyLoading()` before each mount — the cards are `#[Lazy]` and Livewire's `flush-state` re-enables the placeholder between mounts), and fails at `Queues.php:35` without the fix. |
| **Reason** | The CSP fix let the page's JS run for the first time, so `wire:poll` started exercising the cache-hit path inside the 5-second recall window — every card then died with "The script tried to call a method on an incomplete object" (reported at `vendor/laravel/pulse/src/Livewire/Queues.php:35`). The suite could never see it: `phpunit.xml` pins `CACHE_STORE=array`, and the array store never serializes. Pulse is the one component that caches objects, so it gets the one store where nothing serializes — the project's "cache primitives, instantiate objects outside `Cache::remember`" rule (AGENTS.md trap) stays enforced for everything else. |
| **Impact** | `/pulse` renders on a warm cache again; `cache.serializable_classes` stays `false` — no security policy loosened. Costs: Pulse's cross-process state (Servers heartbeat throttle, `pulse:check` restart signal/locks) becomes per-process, and card queries recompute per request instead of once per 5s across all viewers. Stale `laravel:pulse:*` Redis keys expire on their own (5s TTL). |
| **Related** | P11-A1, `tests/Feature/PulseQueryCacheTest.php`, `config/pulse.php` |

---

## 2026-10-16 — Phase 11 post-audit gap closure (docs, tests, one dead env var)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | A read-only audit of the Phase 11 branch found eight gaps, none a code defect; all eight closed. **Docs truth:** the phase header, `progress.md` row 11 + current-task paragraph, `feature-tracker.md` rows 27/28 and the MONITOR-001 note in `task-tracker.md` all still called this work uncommitted or unstarted, and recorded 2009/6907 tests — corrected against `git log` and a fresh full run (**2016 passed / 6940 assertions**, +2 cases from this closure). **One decision closed:** D-3 — superadmin *does* respect the feature flag, now pinned by `test_the_flag_off_refuses_superadmin_too` in `PulsePermissionGateTest` and `HealthPermissionGateTest` (5 cases each, was 4). **One dead config removed:** `phpunit.xml` still set `TELESCOPE_ENABLED=false` for a package deleted in `85384b4`. **New coverage records:** a `Monitoring` section in `qa-tracker.md` (10 scenarios with the test that proves each, one flagged manual), the flag-reseed deploy step in `phase-11-monitoring-observability.md` §7 + `monitoring.md`, and upgrade notes for the two project-side Pulse dependencies (the `array` cache driver and the two nonce-overridden vendor views). |
| **Reason** | The audit's job was to say what was true, and four documents described a state that had already been left behind — "uncommitted" over nine commits, "planned" over shipped work, and a test count five runs old. D-3 had been carried as an open question whose premise (superadmin reaching a flag-off `/pulse`) was never true: `EnsureFeatureIsEnabled` consults no actor, so the answer needed a test, not a redesign. The reseed step and the Pulse upgrade notes belong to the same class — facts a maintainer needs before touching this module, previously only in the breakdown's prose. |
| **Impact** | Docs only, plus two added test cases and one deleted dead env line: no application behaviour changes, no route, permission, flag or gate is altered. `TELESCOPE_ENABLED` was never read (no Telescope in `vendor/`), so removing it changes no test outcome. |
| **Related** | P11-B4, P11-F1, G-1…G-8, `phase-11-monitoring-observability.md` §14, `docs/base/features/monitoring.md`, `qa-tracker.md` |

---

## 2026-10-16 — Vantage queue monitor installed and gated (supersedes Phase 11 D-1)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-16 |
| **Change** | Installed `storviaio/vantage ^0.1.25` and put it behind this project's two gates. `config/vantage.php` (published) sets `routes => false` and `store_payload => false`; `routes/web.php` declares the six web routes under `feature:vantage` with `can:queues.view` on the reads and `can:queues.retry` on the retry POST. `queues.view`/`queues.retry` added to `PermissionCatalog`, a `vantage` flag to `config/pennant.php` (group Monitoring), a `Queue Monitor` sidebar entry, and a `viewVantage` gate override in `AuthServiceProvider` → `queues.view`. `vantage:prune --force --keep-processing` runs daily. New `tests/Feature/Vantage/VantagePermissionGateTest` (7 cases). |
| **Reason** | Owner decision 2026-10-16 reversed the Phase 11 D-1 deferral: the two gaps Vantage closes are real (Pulse's queue card is a throughput graph, not a live backlog count; `failed_jobs` keeps one row per job, so the retry chain is lost). D-2's objection — that Pennant cannot gate a vendor route — dissolves once the app declares the routes itself, which is the option D-1 had listed as an accepted, deferred cost. The package defaults (`viewVantage` admits any authenticated user; `store_payload` true) are its own stated need for an explicit decision. |
| **Impact** | New `vantage_jobs` / `vantage_job_tags` tables in the base schema. Existing installs MUST run `php artisan db:seed --class=FeatureFlagSeeder` (fail-closed Pennant store) or `/vantage` 403s for everyone, superadmin included. **Follow-up closed 2026-10-17:** what this entry deferred — scoping `AddSecurityHeaders::policy()` to `vantage.*` like `pulse` — is now in place. The two CDN origins and `'unsafe-eval'` travel only with the Vantage routes, and the four script-bearing vendor views are overridden to nonce them; pinned by `ContentSecurityPolicyTest`. |
| **Related** | ADR-022, D-1/D-2 (superseded), P11-B1..B4, `phase-11-monitoring-observability.md` §13, `tests/Feature/Vantage/VantagePermissionGateTest.php` |

---

## 2026-10-17 — Phase 11: the ungated health endpoint gets a ceiling and a cached check set (ADR-023)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-17 |
| **Change** | `GET /api/v1/health` now carries `throttle:health`: a new `RateLimiter::for('health')` keyed on the IP, its number from `SystemSetting::getInt('health_rate_limit_per_minute', 120)` (validated `1..600`, seeded at `120`, with a settings-page field), refusing in JSON regardless of the caller's `Accept` header via the new `jsonOnly` argument on `LoginThrottle::responseFor()`. `HealthCheckService::check()` caches its check set for 10s under `health_check:results`; a store that cannot answer is logged as `health_check.results_cache.failed` and the checks run uncached. New tests: three in `HealthCheckEndpointTest` (a stored set is served instead of the checks re-running; a completed set is stored; an unreachable store is reported rather than thrown — the class gained `RefreshDatabase`, because the limiter reads `system_settings`) and one in `ThrottleJsonResponseTest`. |
| **Reason** | A read-only performance pass over Phase 11 (finding A1) measured this route as the only endpoint in the app with no gate of any kind: the `api` group's middleware is `[SubstituteBindings]` and there is no `RateLimiter::for('api')`, so nothing bounded the request count, while one uncached run costs seven round trips — a PDO connect, a cache write **and** a read back, a queue `size()` and three storage operations (0 queries on the harness; 3 queries + 1 write on `database`/`database`). Phase 11 §F3 decided the *permission* question deliberately and left the *cost* question open; closing it had to keep the endpoint answerable by a monitor that cannot log in, so the answer is a ceiling plus a cache, not a gate. |
| **Impact** | The response shape is unchanged. Checks are up to 10s old while `status` and `checks` stay consistent with each other and `timestamp` remains the response time. A database that is down **and** a settings cache that is cold makes the limiter itself 500 before the checks run — the same shape every other limiter in the app already has, and still a non-2xx for the monitor. The one place that answers for a health endpoint must never be less available than the endpoint itself, which is why a failing store is reported as `cache: fail` instead of thrown. `docs/base/security/rate-limiting.md`'s table was re-derived while adding the row: it listed `bulk-action` and `user-state-actions` with no setting key and omitted `send-test-mail` and `audit-export`, and its claim that `user-state-actions` lacks the `expectsJson()` branch was stale. |
| **Related** | ADR-023, Phase 11 §F3 and finding A1, `docs/base/security/rate-limiting.md`, `tests/Feature/CrossCutting/HealthCheckEndpointTest.php`, `tests/Feature/CrossCutting/ThrottleJsonResponseTest.php` |

---

## 2026-10-17 — Vantage retry answers from `failed_jobs` (ADR-022, decision 7)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-17 |
| **Change** | `AppServiceProvider::register()` now binds `Storvia\Vantage\Support\JobRestorer` to the new `App\Support\Vantage\FailedJobPayloadRestorer`. When `vantage_jobs.payload` is empty (this project's `store_payload => false`), the restorer reads the job's raw payload back from `queue.failer` — the `failed_jobs` record `queue:retry` reads — keyed on the payload uuid Vantage already stores per attempt, and hands it to the package's own `JobRestorer::restore()` unchanged, `class_exists`/`allowed_classes`/`instanceof` checks included. New `tests/Feature/Vantage/VantageRetryTest` (3 cases: restored from the framework record; refused with nothing dispatched when the record is gone; the Vantage payload still wins when it exists). ADR-022 gained decision 7, and `config/vantage.php` + `docs/base/features/monitoring.md` record that retry does not depend on `store_payload`. A manual check ships with it: `php artisan queue:probe-failure` dispatches `App\Jobs\QueueFailureProbe`, one job that always throws, so an operator can produce the `failed_jobs` + `vantage_jobs` pair on demand and press Retry to watch the chain. |
| **Reason** | `POST /vantage/jobs/{id}/retry` was shipped, gated by `queues.retry`, rendered by the vendor's own views — and could never succeed: `PayloadExtractor::getPayload()` returns null when `store_payload` is off, so `JobRestorer::restore()` returned null and every press answered "Unable to restore job. Payload might be missing or corrupted." Nothing in the code said so, because every gate on the route passes. The payload the button needs exists one table away: `WorkCommand::logFailedJob()` writes it for exactly the final attempt Vantage marks `failed`, so the retry reuses that copy instead of switching on the column ADR-022 keeps off — which would persist export filters, model ids and mail arguments for every `queues.view` holder to read. |
| **Impact** | Retry works without a second copy of any job payload. No route, permission, gate, flag or `config/vantage.php` value changes; `vantage_jobs.payload` stays empty. New dependency to know about: a retry now needs the failed-job record, so `queue:flush` / `queue:prune-failed` empties it while `vantage_jobs` lives on for `vantage.retention_days` (14) — those rows refuse the retry, logged as `Queue Monitor: no failed-job record for this retry`, because the vendor's button stays visible. Rows whose job class is a queued notification or mailable were already unrestorable (the vendor validates the `SendQueued*` job class while the payload holds the Notification/Mailable) — unchanged. |
| **Related** | ADR-022, Phase 11 §13, `docs/base/features/monitoring.md`, `tests/Feature/Vantage/VantageRetryTest.php`, `app/Support/Vantage/FailedJobPayloadRestorer.php` |

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
---

## 2026-10-17 — Phase 11 security pass: the flag reaches the writes, the permission follows the flag (ADR-024)

| Field | Value |
|-------|-------|
| **Date** | 2026-10-17 |
| **Change** | Four findings from a read-only pass over the Phase 11 surface, all one shape — a control at the edge of a module and not inside it. (1) The `pulse` and `vantage` flags stopped the dashboards and not the recording: `Pulse::filter(fn () => FeatureCatalog::isActive('pulse'))` now answers at INGEST (worker included, and no store read when there is nothing to write), and because Vantage offers no filter hook, an `Event::listen(Looping::class, …)` listener refreshes `config('vantage.enabled')` — the key its recorders re-read inside `handle()` — once per daemon iteration (`JobProcessing` is one job late: the package listens there and registered first). `PULSE_ENABLED` / `VANTAGE_ENABLED` stay as deploy-time master switches. Both gates become `flag AND permission` (`viewPulse`, `viewVantage`), so the vendor's own `Authorize` middleware cannot read an unflagged gate. (2) A flag had no authority over its permission: `PermissionCatalog::FEATURE_BY_RESOURCE` maps resource prefix → flag, `PermissionCatalog::visibleNames()` filters the role matrix and the permission index, and the new `FeatureRevokePermissionsAction` deletes both pivot tables for every held permission of a switched-off flag in two statements flat in the batch size (the bulk action runs one pass over the whole selection), inside the toggle's transaction, recording the names in the audit row's new `revoked` property. `PersistsRole::assignablePermissionIds()` keeps a grant the matrix cannot render and ignores a posted id it cannot offer. (3) The health endpoint's fallback ran uncached: `HealthCheckService::check()` now reads the configured store, then the `file` store, and only then runs the probes, writing to both best-effort — `Cache::remember()` ran its callback BEFORE attempting a store it could not write, so the ten-second ceiling vanished exactly when the cache needed reporting (ADR-023 clause 3, amended). (4) `POST /vantage/jobs/{id}/retry` gained `throttle:queue-retry` (`queue_retry_rate_limit_per_minute`, default 10/min, `1..120`, per user + IP) with `LoginThrottle::flashResponseFor()` — a redirect with a session `error` flash, because the queue monitor is a vendor layout that renders `session('error')` and has no `$errors` bag. Docs: ADR-024, `feature-flags.md` (permission↔flag rules), `monitoring.md`, `rate-limiting.md`, `roles-permissions.md`, phase 11 §15, `qa-tracker.md` (QA-MON-011..015). New tests: `PermissionFeatureMapTest` (7), `FeaturePermissionRevokeTest` (10), `PulseRecordingKillSwitchTest` (2), `VantageRecordingKillSwitchTest` (2), `HealthCheckCacheFallbackTest` (4), `QueueRetryThrottleTest` (2), plus kill-switch cases in both gate test files. Follow-up the same day: the `/features` confirmation modal for a flag that owns permissions now says the revoke is coming and that re-enabling does not grant the permissions back (`FeatureIndexAction` hands each row its `permissions`; the bulk-disable copy in `action-config.js` carries the same warning), both held to two sentences by test, and `feature-flags.md` carries the flag→permission table with the re-assign rule. |
| **Reason** | A flag the operator can flip during an incident that keeps writing, and a permission that outlives its module with nobody able to see it to remove it, are both failures of the same control. Measured: `Pulse::ingest()` recorded an entry with the flag off, a role kept `pulse.view` after the flag went off, and with the configured cache store dead three calls ran three probe sets — the ceiling ADR-023 promises exists for exactly the case it stopped covering. |
| **Impact** | Feature flags now govern the `pulse`/`vantage` write paths and the permission catalogue; `PermissionFeatureMapTest` fails on a resource with no flag entry; the revoke is final (re-enabling grants nothing back); `features` is the one deliberately unmapped resource. No API surface, response shape or route path changed. `queue:listen` and synchronous connections never fire `Looping` — they keep `VANTAGE_ENABLED` as their only switch. |
| **Related** | ADR-024, ADR-022, ADR-023 (clause 3 amended), P11-B4, phase 11 §15, `docs/base/features/feature-flags.md`, `docs/base/security/rate-limiting.md`, `PermissionFeatureMapTest`, `FeaturePermissionRevokeTest`, `PulseRecordingKillSwitchTest`, `VantageRecordingKillSwitchTest`, `HealthCheckCacheFallbackTest`, `QueueRetryThrottleTest` |

## 2026-10-20 — Phase 12 Group B: the API error contract is now the same in every environment

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | Three things the contract promised and the code did not keep. (1) Two documented codes were unreachable: `NotFoundHttpException` and `ThrottleRequestsException` extend `HttpException`, and the handler's blanket `HttpException && 4xx` branch sat above both, so every route-level 404 and framework-level 429 answered `HTTP_ERROR`. Specific branches come first now. (2) The whole envelope sat inside `! config('app.debug')`, so a debug-enabled deployment answered in Laravel's shape — different keys, no `code`, no correlation — and no test noticed because `.env.testing` pins `APP_DEBUG=false`. The envelope is unconditional; debug now **adds** `debug.exception`/`debug.message` and nothing else. (3) Errors emitted outside the `api/*` block — the `LAST_SUPERADMIN` 409 and the CSRF 419 — carried no `meta`; every error body now carries `meta.request_id`/`timestamp`, built by one `App\Support\ApiError` that the handler, `Controller::respondError()` and `FormatsApiErrors` all call. Alongside: eight resources carry explicit `@return array{…}` shapes, `SessionResource` replaces a token map that lived in `SessionController`, four hand-built success envelopes collapsed into `respond()`, and `#[Response]` types both health statuses (200/503) — the export had inferred `string`. |
| **Reason** | An error contract whose body depends on an env var is not a contract: a client cannot branch on a payload a deploy can silently rewrite. And a documented code with no emitter is a promise the API does not keep — measured by `scramble:export`, where `/v1/health` published `schema: {type: string}` and an unknown route answered `HTTP_ERROR` while the docs said `NOT_FOUND`. |
| **Impact** | API clients see `NOT_FOUND`/`RATE_LIMITED` where they used to see `HTTP_ERROR`, and `meta` on every error; in debug the body keeps its keys and gains `debug`. `data.message` on the endpoints listed in `error-contract.md` § *Known shape gap* is unchanged — that migration belongs to Group C. No route, status code or success shape changed. |
| **Related** | P12-B1..B6, `docs/base/api/error-contract.md`, `docs/base/api/response-contract.md`, `docs/planning/phase-12-api-v1.md`, `ApiErrorEnvelopeTest`, `SessionApiTest` |

## 2026-10-20 — Phase 12 Group C: the API stops answering refusals in the success envelope

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | Every endpoint that answered a refusal through `respond($message, 4xx)` now uses `respondError()`, so `data.message` no longer appears in any error body: login (401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 429 `RATE_LIMITED` + `retry_after_seconds`), register (404 `NOT_FOUND`, message kept so a disabled feature cannot be probed for), email/resend (403 `FORBIDDEN`, 422 `EMAIL_ALREADY_VERIFIED`, 429 `RATE_LIMITED`, 500 `SERVER_ERROR` — carried as `error_code` on the action's payload), verify-change (400 `INVALID_TOKEN`), test-mail (502 `BAD_GATEWAY`). `POST /api/v1/auth/unlock` is **removed**: it forwarded `request()->route('user')` on a route with no `{user}`, so URL generation threw and it answered 500 to every caller, and its comment was wrong about Scramble (the document listed it as a 200 with no body). Annotations filled what inference cannot see: `#[Response(409)]` on `DELETE /users/{user}`, `POST /users/{user}/deactivate` and `POST /users/bulk-action` (the three reachable `LastSuperadminException` paths; the bulk transaction rolls the whole batch back), audit download 404/410 and retry 404/409, and the settings methods state the flat key→value map and that `mail_password` is absent rather than null. `permissions.index` now takes the existing `PermissionQueryRequest` instead of reading `$request->query()`, so its allowlist is documented and a bad `sort` answers 422. `GET /api/v1/features` is **added** (`features.view`, no `feature:*`): the feature module was write-only over the API, so a client could change a flag it could not enumerate and had to toggle one to read its state. It serves `FeatureIndexAction`'s resolved rows projected to `slug`/`label`/`group`/`description`/`enabled`/`pending` — not the page model, whose `toggle_url` is a browser form URL and whose `manageable`/`permissions` are UI concerns. |
| **Reason** | Two bodies for one failure is the same defect the error contract was written to prevent: a client either branches on `code` or parses `data.message`, and the endpoints that forced the second option were the auth ones — the paths every client hits first. The unlock shim was a redirect that could not name its target and had never worked; measuring it (a probe test, not a reading) is what turned "should throw" into "returns 500". |
| **Impact** | **Client-visible:** errors on the six migrated endpoints have `message`/`code`/`meta` at the top level instead of `data.message`, and the removed route answers 404 `NOT_FOUND` instead of a redirect that never happened. One new documented code is 422 `EMAIL_ALREADY_VERIFIED`; `BAD_GATEWAY` is now the 502 code. New route: `GET /api/v1/features` (`features.view`), which closes the last one-sided capability in `ChannelParityTest`. No other success shape, status code or route path changed. `LoginControllerTest` asserts the new codes. |
| **Related** | P12-C1..C8, `docs/base/api/error-contract.md` § *Controller refusals*, `docs/planning/phase-12-api-v1.md` Group C, `tests/Feature/Api/V1/Auth/UnlockShimTest`, `tests/Feature/Api/ChannelParityTest` |

## 2026-10-20 — The API standard is a checklist, not folklore

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | The design the API already follows is now written as the standard an endpoint must satisfy, and as the procedure an audit runs. `docs/base/api/api-architecture.md` gains § *API Conventions*: the eight parts every `api/*` endpoint is built from (route → FormRequest → controller → Action → Resource → response helper → Scramble annotation → test), the rule for each, and pointers to the one doc that owns each shape so no endpoint restates it. It also gains § *Auditing conformance*: seven grep/test commands that must print nothing but the documented exceptions, and a *Known non-conformance* list of what is currently open. Two tables keep the audit honest: **deliberate exceptions** — health's bare body and non-extending controller, settings' flat key→value `data`, the self-scoped routes with no `can:`, the "own account **or** `users.update`" rule that only `abort_unless` can express, the three bulk routes whose permission depends on the payload, `whereNumber` on activity ids, the unpaginated feature catalogue — and the reason for each. Corrected while writing it: the error example in `api-architecture.md` was missing the `meta` every error carries, and the file claimed Scramble is "annotation-free" while five controllers carry `#[Response]`; `response-contract.md` called the inbox "the one list without a pagination block" (two more exist) and listed `GET /audit` with a parameter set that belongs to `GET /activity-logs`. |
| **Reason** | A convention that lives only in existing code is followed by whoever read enough of it. The same doc has to serve two readers with opposite jobs: an agent adding an endpoint (what must exist) and an audit asking which endpoints skipped it (what to check). Without the exceptions table the audit reports ten false positives; without the checklist it reports nothing, because "looks consistent" is not a check. |
| **Impact** | Documentation and `AGENTS.md` only — no runtime change, no route, shape or status code touched. `AGENTS.md` § Hard rules now points API work at the checklist, so new endpoints and whole-project audits read the same source. |
| **Related** | P12-C5, `docs/base/api/api-architecture.md`, `docs/base/api/response-contract.md`, `docs/planning/phase-12-api-v1.md`, `AGENTS.md` |
