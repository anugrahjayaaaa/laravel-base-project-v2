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

| Field | Value |
|-------|-------|
| **Date** | 2024-01-01 |
| **Change** | Created initial architecture documentation and planning system for Laravel Base Project v2 |
| **Reason** | Need structured documentation/planning foundation before any implementation |
| **Impact** | All subsequent phases; this establishes the foundation |
| **Related** | P0-001 through P0-011 (Phase 0 tasks) |

### Components Created

| Component | Description | Docs |
|-----------|-------------|------|
| Architecture Principles | Core principles, separation of responsibilities, single source of truth | architecture/principles.md |
| Application Architecture | High-level layer diagram, API-first, dependency rules | architecture/architecture.md |
| Application Boundaries | Transaction boundaries, security boundary, feature availability | architecture/application-boundaries.md |
| Folder Structure | Recommended directory layout for Laravel app | architecture/folder-structure.md |
| Dependency Rules | Package governance, Redis compatibility, secret management | architecture/dependency-rules.md |
| Naming Conventions | Code, DB, table, column naming standards | architecture/naming-conventions.md |
| ADRs (18 architecture + 7 dependency) | Architecture Decision Records (see decisions.md + decision-records/) | architecture/architecture-decisions.md |
| Security Baseline | Overall security posture, headers, secrets, error handling | security/security-baseline.md |
| Authentication | Login, session, lockout, verification requirements | security/authentication.md |
| Authorization | RBAC, superadmin protection, feature availability | security/authorization.md |
| Password Security | IM8 policy, history, expiration, reset flows | security/password-security.md |
| Session Security | Token strategy, single-session-per-device, revocation triggers | security/session-security.md |
| Rate Limiting | Per-endpoint limits, Redis compatibility, separation from failed-login | security/rate-limiting.md |
| Web Security | HTTPS, HSTS, CSP, CSRF, CORS, security headers | security/web-security.md |
| Data Protection | Encryption, storage, backup, retention | security/data-protection.md |
| API Architecture | Versioned API, resources, response conventions, error contract | api/api-architecture.md |
| API Versioning | Versioning strategy, deprecation policy | api/versioning.md |
| API Response Contract | Envelope structure, pagination, filtering, sorting | api/response-contract.md |
| API Error Contract | HTTP status codes, error codes, security in errors | api/error-contract.md |
| API Documentation | Scramble approach, required sections, tools | api/documentation.md |
| Database Conventions | ID strategy, naming, constraints, soft-delete, enums | data/database-conventions.md |
| Soft Delete Strategy | When to use, decision matrix, implementation | data/soft-delete.md |
| Relationships | Eloquent patterns, loading strategy, constraints | data/relationships.md |
| Concurrency | Transaction boundaries, race conditions, retry | data/concurrency.md |
| Queue | Database queue, Redis compatibility, job patterns | infrastructure/queue.md |
| Redis Compatibility | When Redis is optional/required, abstractions | infrastructure/redis-compatibility.md |
| Cache | Cache strategy, invalidation, TTL | infrastructure/cache.md |
| Storage | Disk strategy, public/private/tmp, cloud | infrastructure/storage.md |
| Logging | Channels, levels, correlation IDs, security | infrastructure/logging.md |
| Observability | Monitoring structure, AUDIT vs Telescope separation | infrastructure/observability.md |
| Backup & DR | Backup types, verification, restore procedure, RPO/RTO | infrastructure/backup-disaster-recovery.md |
| User Management | User state model, operations, lifecycle | features/user-management.md |
| Registration | Configurable registration, default role | features/registration.md |
| Roles & Permissions | RBAC via Spatie, permission model, superadmin | features/roles-permissions.md |
| Feature Flags | Feature availability enforcement, backend-first | features/feature-flags.md |
| Settings | Structured settings, two-layer config, change management | features/settings.md |
| Notifications | Channels, categories, mail config, queue integration | features/notifications.md |
| Audit Trail | Audit package, abstraction, async export, metadata | features/audit-trail.md |
| Monitoring | Audit/logs/Telescope/health check separation | features/monitoring.md |
| Testing Strategy | Types, principles, coverage matrix | testing/testing-strategy.md |
| Testing Matrix | Coverage grid per feature | testing/testing-matrix.md |
| Definition of Done | 13 criteria checklist | testing/definition-of-done.md |
| Deployment | Deployment steps, CI/CD, rollback, zero-downtime | operations/deployment.md |
| Environment | Env vars, config files, secrets management | operations/environment.md |
| Retention | Data type-specific retention schedule | operations/retention.md |
| Troubleshooting | Common issues, debugging tools, log locations | operations/troubleshooting.md |
| Planning README | Planning system overview | planning/README.md |
| Requirements | Functional & non-functional requirements | planning/requirements.md |
| Feature Matrix | Feature coverage map with priority/phase | planning/feature-matrix.md |
| Implementation Roadmap | Phases 0-17, dependency map | planning/implementation-roadmap.md |
| Task Tracker | Full task list (100+ items) with status | planning/task-tracker.md |
| Dependency Map | Feature-level dependency graph | planning/dependency-map.md |
| QA Tracker | All QA scenarios organized by feature | planning/qa-tracker.md |
| AI Execution Guide | Rules for AI agents working on repo | planning/ai-execution-guide.md |

---

## 2025-09-14 — Logging Strategy Specification

| Field | Value |
|-------|-------|
| **Date** | 2025-09-14 |
| **Change** | Rewrote `infrastructure/logging.md` with the full Application Logging Requirements spec: five-tier logging model (Audit Trail / Application Logs / Security Logs / Server Logs / Telescope separation), failure classification (expected vs unexpected), structured event/action names, context envelope, transaction-failure semantics, request/correlation ID propagation, centralized exception handling, logging privacy/redaction, and retention. Added ADR-013 and logging QA scenarios (QA-LOG-001 through QA-LOG-008) to the QA tracker. |
| **Reason** | Establish single source of truth for Phase 1 implementation; prevent ad-hoc logging that conflates audit/application logs, logs sensitive data, or creates false audit records on rollback. |
| **Impact** | All subsequent phases that emit logs or audit records; Phase 1 (correlation ID middleware), Phase 10 (audit trail), Phase 11 (Telescope). |
| **Related** | ADR-013, FOUND-008, AUDIT-001/AUDIT-003, MONITOR-001, RETAIN-001, QA-LOG-* |

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

| Field | Value |
|-------|-------|
| **Date** | 2025-09-14 |
| **Change** | Created `docs/base/dependencies/` directory with `overview.md`, `dependency-matrix.md`, `docs/base/governance/dependency-governance.md`, and 7 dependency ADRs in `docs/base/architecture/decision-records/` (DEP-001 Sanctum, DEP-002 Spatie Permission, DEP-003 Activitylog, DEP-004 Telescope, DEP-005 Native Laravel First, DEP-006 Database Queue + Redis, DEP-007 API Documentation/Scramble). Updated `docs/base/README.md` to add Dependencies as section 2. |
| **Reason** | Establish single source of truth for all dependency decisions; distinguish installed vs planned packages; govern package selection before any `composer require` is permitted. |
| **Impact** | All phases that introduce Composer packages (Phase 1 FOUND-004/005/006/007, Phase 12 API-003, Phase 14 BACKUP-001); the dependency governance rules. |
| **Related** | FOUND-004, FOUND-005, FOUND-006, FOUND-007, API-003, BACKUP-001, QUEUE-001, CACHE-001, MONITOR-001 |

---

## 2025-09-14 — Architecture Gap-Closing Pass

| Field | Value |
|-------|-------|
| **Date** | 2025-09-14 |
| **Change** | Closed architecture documentation gaps: created ADRs-001..018 in `planning/decisions.md`, and `docs/base/architecture/decision-records/` (DEP-001 Sanctum, DEP-002 Spatie Permission, DEP-003 Activitylog, DEP-004 Telescope, DEP-005 Native Laravel First, DEP-006 Database Queue + Redis, DEP-007 API Documentation/Scramble). Enhanced: `application-boundaries.md` (transaction/after-commit model + rollback behavior), `audit-trail.md` (transaction boundaries), `queue.md` (after-commit dispatch + idempotency + rollback), `logging.md` (5-way observability classification + correlation ID generation/propagation/propagation to jobs), `observability.md` (5-way classification + security logs), `retention.md` (security logs 90d), `user-management.md` (lifecycle transitions + state distinctions), `authentication.md` (session revocation triggers + last_activity NULL policy), `rate-limiting.md` (precedence + endpoint categories + race conditions), `authorization.md` (system role protection + superadmin bypass table), `soft-delete.md` (deletion policy + cascade-when-justified update to ADR-012), `roles-permissions.md` (system role protection), `monitoring.md` (5-way classification + Security Logs + ADR-008 reference), `settings.md` (`last_activity_at` inactivity grace config), `security-baseline.md` (`last_activity_at` policy), `ai-execution-guide.md` (dependency-aware + transaction + source-of-truth rules), `progress.md`, `base/README.md` (sections index). Updated `planning/decisions.md` (ADR-008  5-way, ADR-013 5-tier, added ADR-014..018). Updated `architecture-decisions.md` (count + all ADR entries 008-018). Updated `task-tracker.md` (RBAC-005 description). |
| **Reason** | Resolve contradictions between documented and approved architecture decisions; ensure all components, transaction semantics, account state, rate-limit precedence, cascade rules, system-role protection, UI architecture, and observability classification are consistently documented before implementation begins. |
| **Impact** | All Phase 1-17 implementation tasks; architecture compliance tests; Definition of Done verification. |
| **Related** | - ADR-001 through ADR-018, DEP-001 through DEP-007, FOUND-008, CORR-001, AUDIT-001/AUDIT-003, MONITOR-001, RETAIN-001, RBAC-001/RBAC-005, SEC-001/002/003, DB-001/DB-002, CACHE-001/002, SET-001, UI-001, FEATURE, QA-LOG-*, QA-AUDIT-*, QA-SEC-*, QA-API-* |
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

## 2026-10-20 — Phase 12 Group B/C conformance pass: the seven gaps the audit found

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | Seven findings from the conformance audit, all closed. (1) Two refusals still went through `respond()` — verify-email (already verified) and the admin resend — so they carried no `code`; both now use `respondError()` (`EMAIL_ALREADY_VERIFIED` 422, and the action's own `error_code` for 422/429/500). `respond()` now **throws** a `LogicException` on a 4xx status, so the convention is an invariant instead of a habit, and the audit grep's blind spot to non-literal statuses no longer matters. (2) `GET /notifications` published `settings`/`channels` as `string` because both are built as maps in the controller; the 200 body is typed explicitly (`array<string,string>`, `array<string,bool>`, `mailers` as a list). (3) roles and permissions accepted an unknown `sort` and fell back to `name`, contradicting `response-contract.md`; the shared `RoleQueryRequest` / `PermissionQueryRequest` now apply `Rule::in(Action::SORTABLE)` — one request per resource for both channels, one contract — so the API answers 422 `VALIDATION_ERROR` and the web index refuses the submission instead of silently reordering (pinned by `RbacApiTest::test_an_unknown_sort_column_is_rejected` and `RbacUiRenderTest`). (4) Reachable statuses inference cannot see are annotated: 401 on login, 429/500 on both resend endpoints, and 403 on every `feature:*`-gated method (logout-all, sessions, the four inbox routes, verify-change, email verify). (5) `PUT`/`PATCH /users/{user}` are two route objects with distinct names (`users.update`, `users.patch`): Scramble documents one method per route object, so a single `match()` left PATCH out of the spec. `GET /email/verify-change/{user}/{token?}` became `…/{user}` with `?token=`, the address shape the web twin and `ChangeEmailVerificationNotification` already use, documented via `#[QueryParameter]` — the optional segment was published as required and the API read a path token nothing generated. (6) `verification.verify.api` renamed to `api.v1.auth.email.verify` and added to `ChannelParityTest`, which had been skipping it for not matching `api.v1.*`. (7) `error-contract.md` reconciled with the code: 409 is not only `LAST_SUPERADMIN` (three audit-retry refusals arrive as `HTTP_ERROR`), 410 is documented, and a 404 from `abort()` inside an Action is `HTTP_ERROR` while a missing route/model is `NOT_FOUND`. |
| **Reason** | Each finding was a place where the document promised one shape and the generated spec or the runtime answered another — an error body without a `code`, a schema that lies about two keys, a whitelist documented as a 422 that was a silent fallback, statuses a client can receive but cannot look up, a verb and a path the document does not have, and a contract table narrower than the handler. The audit was judged against `docs/base/api/*`, so the fixes make code and spec agree with the document rather than the reverse. |
| **Impact** | **Client-visible:** an unknown `sort` on roles/permissions is now 422 instead of a fallback; `GET /email/verify-change/{user}/{token}` (path token) is now 404 — nothing generated that shape, and the real link carries `?token=` on both channels; `PATCH /users/{user}` keeps working and is now documented. Two error bodies gained `code`/`meta` and lost `data.message`. No success shape changed. Tests: `RbacApiTest` (+1), full suite green. |
| **Related** | P12-B1..B6, P12-C1..C8, `docs/base/api/api-architecture.md` (Known non-conformance), `docs/base/api/error-contract.md`, `docs/base/api/response-contract.md`, `tests/Feature/Api/ChannelParityTest`, `tests/Feature/Role/RbacApiTest` |

## 2026-10-20 — Phase 12 D5: the docs UI can carry a bearer token

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | `config/scramble.php` enables `MiddlewareAuthSecurityStrategy` (it was `null`). One switch, no schema written by hand: the vendor strategy reads `auth` / `auth:*` off each route, publishes `components.securitySchemes.http` (`type: http`, `scheme: bearer`) and applies it as the document-level `security`, then marks every route without those middleware `security: []`. `/docs/api` now renders the Authorize box, so "Try it out" sends `Authorization: Bearer <token>` instead of a 401. `ApiDocsUiRenderTest` asserts the scheme, the global requirement, and that `POST /v1/auth/login` is public. |
| **Reason** | The document described every authenticated route and none of the authentication: `securitySchemes` was empty, so the UI had nowhere to put a token and a reader had to paste a header into a client of their own. The manual alternative — an `afterOpenApiGenerated` transformer — would have been a second hand-kept list of which routes need auth, drifting from the routes themselves. |
| **Impact** | Documentation surface only: no route, status, payload or schema of the API changed. The scheme key is `http` (the vendor's default name for `SecurityScheme::http('bearer')`) — **superseded 2026-10-24:** it is now named `bearerAuth`, so the UI prints that beside the Authorize button. Public routes (login, register, password forgot/reset, health, the signed verify links) are explicitly `security: []`. |
| **Related** | P12-D5, P12-A4, `docs/base/api/documentation.md`, `config/scramble.php`, `tests/Feature/Api/ApiDocsUiRenderTest` |

## 2026-10-20 — Phase 12 Group D: the security surface is now stated, and one route was actually missing its gate

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | Group D closed. (1) **One real `can:` gap:** `POST /features/bulk-action` carried no route gate on either channel while its comment claimed the permission was action-dependent — it is not, both flag actions are `features.manage`, so the route now pins that permission on web and API and `BulkFeatureRequest::authorize()` stays as the payload check. The other five ungated routes are correct (own-account-or-permission, signed link, action-dependent bulk) and each now has a row in `api-architecture.md` § *Deliberate exceptions*. (2) **Token abilities decided:** every token stays `['*']`; a token is a session in another transport and authority is RBAC re-read per request, so a named ability set would be a stale second copy of `can:`. Written up in `api-architecture.md` § *Token abilities* with the condition that would reverse it. (3) **Rate ladder documented:** `rate-limiting.md` gains § *API ladder* mapping every API route group to the limiter(s) that apply and which ceiling actually refuses; no `api` limiter is added because the protected group's 15/min is tighter. (4) **Audit HTTP context proved further:** the cross-channel audit test now asserts the row's `request_id` equals the response's `X-Request-ID`. (5) **Response parity:** `ResponseParityTest` asserts users and roles return the same records for the same query, that every web-rendered fact is in the API row, and that the API-only keys are an exact declared list. |
| **Reason** | Group D asked whether the security surface as documented is the surface that runs. Four checks passed as written; one did not — a route the comments described as deliberately ungated turned out to be gated-able, and the comment was the only thing keeping it open. The rest turns decisions that lived in code comments into one place a reviewer can read, and turns "the API probably returns the same facts" into a test that fails when it stops. |
| **Impact** | **Client-visible:** none. `features.bulk-action` already refused anyone without `features.manage` through the FormRequest; the route gate makes the refusal happen one layer earlier. No path, status, payload or limiter changed. New test file `tests/Feature/Api/ResponseParityTest.php`; `SystemSettingUpdateTest` gained two assertions. Documented open gap: `POST /auth/password/change` is the one authenticated endpoint with no rate limiter of its own (it must stay reachable when a password has expired). |
| **Related** | P12-D1..D7, `docs/base/api/api-architecture.md`, `docs/base/security/rate-limiting.md`, `docs/planning/phase-12-api-v1.md` § Group D, `tests/Feature/Api/ResponseParityTest`, `tests/Feature/Settings/SystemSettingUpdateTest` |

## 2026-10-20 — Phase 12 Group E: the docs drift gate, the per-module gates, and the error matrix

| Field | Value |
|-------|-------|
| **Date** | 2026-10-20 |
| **Change** | Group E closed, mostly by making existing behaviour enforceable. (1) **Per-module API tests** under `tests/Feature/Api/V1/{Role,Permission,Settings,Feature}` — 16 tests. Each module gets its happy path, the 422 envelope, and the sibling-permission 403 in **both** directions where two permissions exist (`roles.view`/`roles.create`, `settings.view`/`settings.manage`, `features.view`/`features.manage`); the permission catalogue holds every permission except `permissions.view`, so a route gated on the wrong sibling cannot pass on an empty grant. Deep behaviour stays in `RbacApiTest`, `SystemSettingUpdateTest` and `FeatureFlagApiTest`; the new files own only the gate. (2) **`ApiDocumentationTest`**, 6 tests: reads `/docs/api.json` and asserts every `api.v1.*` route appears as a path+method (Scramble strips the `api` prefix), every `auth:sanctum` operation documents 401, every `can:` operation documents 403, every FormRequest action documents 422, the bearer scheme and global `security` are declared, and no operation publishes a status outside the contract. (3) **`ApiErrorEnvelopeTest`** gained a data-provider matrix that raises one handler-mapped exception per code — 400 `INVALID_TOKEN`, 401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 409 `LAST_SUPERADMIN`, 422 `VALIDATION_ERROR`, 429 `RATE_LIMITED`, 500 `SERVER_ERROR` — and asserts status, code, `meta.request_id`/`timestamp`, `errors` only on 422, and no `debug` with `APP_DEBUG=false`. (4) `ApiDocsUiRenderTest` is retained unchanged as the access/UI regression owner. |
| **Reason** | The documentation step was aspirational: Scramble omits a route it cannot infer and the UI still renders, so nothing failed. The gate makes that omission red on the commit that introduces it. The per-module files exist because every authorization test in the old files used a caller with *no* permissions — the case a route gated on a sibling from the same module passes. Measuring the generated document before writing E2 also corrected its own wording: 401/403/422 are inferred from middleware and FormRequests, 500 only where annotated, so "all four on every operation" was false for health, public auth and input-less reads; the test asserts reachability instead, and the docs now say so. E4 found one real deviation in the process: three middleware 403s answer without `meta`. |
| **Impact** | **No runtime change and no API contract change.** 30 new tests; full suite **2148 passed / 7574 assertions**, Pint clean, `scramble:analyze` reports only the known `HealthCheckResource` warning. Docs corrected rather than restated: `documentation.md` carries the reachability table and what the drift gate does (and does not) catch; `error-contract.md` gains the three middleware codes and § *Known deviations*; `api-architecture.md` § *Known non-conformance* records the missing `meta`. The middleware fix is deliberately **not** included — it rewrites a refusal every authenticated route can emit and needs its own change. |
| **Related** | P12-E1..E5, `docs/planning/phase-12-api-v1.md` § Group E, `docs/base/api/{documentation,error-contract,api-architecture}.md`, `tests/Feature/Api/ApiDocumentationTest`, `tests/Feature/Api/V1/{Role,Permission,Settings,Feature}`, `tests/Feature/CrossCutting/ApiErrorEnvelopeTest` |

## 2026-10-21 — Phase 12 post-review: one FormRequest per payload, one error builder

| Field | Value |
|-------|-------|
| **Date** | 2026-10-21 |
| **Change** | The owner closed Groups B–E and commissioned an audit pass; four of its findings were defects, not drift. (1) **`ToggleFeatureRequest`** (`app/Http/Requests/V1/Feature/`) now backs `POST /features/{feature}/toggle` and `POST /api/v1/features/{feature}/toggle`. Both controllers had validated the one boolean inline, and the web docblock argued for that — a comment defending the audit's own finding. `authorize()` repeats `features.manage` so a stripped route middleware is not an unauthenticated write. (2) **`VerifyEmailChangeRequest`** replaces `$request->query('token')` on both channels; `nullable` on purpose, because a missing token is the documented 400 `INVALID_TOKEN`, not a 422. The web controller's `?? $request->route('token')` fallback is gone — the route has no such parameter, so it always evaluated to `null`. (3) **Every refusal now builds through `ApiError::make()`**: the three middleware 403s E4 recorded (`ACCOUNT_DISABLED`, `ACCOUNT_LOCKED_INACTIVITY`, `PASSWORD_CHANGE_REQUIRED`), the docs 401/403, and the auth-lockout 429. The last one is the interesting case: `ThrottleRequests` returns the limiter's own `->response()` closure as an `HttpResponseException`, which `bootstrap/app.php` deliberately declines to handle, so that body never touched the error builder. `ThrottleJsonResponseTest` now asserts `meta` on that path — `ApiErrorEnvelopeTest`'s 429 case throws from a throw-away route and had been passing throughout. (4) **Docs reconciled**: the unread-count path was wrong, the unpaginated list said three when the export list is a fourth, the verify-change exception claimed "there is no sign-in" while both channels sit in the authenticated group, and `Admin Resend Verification`, `Audit trail` and `Notifications` were missing their channel ticks in `feature-tracker.md`. |
| **Reason** | Two rules were only true of the code someone happened to grep: "controllers read `validated()` only" and "`meta` is always present". Inline validation is invisible to the generated document, and a hand-built refusal is invisible to the envelope tests. Both are now structural — one FormRequest per payload, one builder per body — instead of comments asserting they exist. |
| **Impact** | **No route, permission or success-response change.** Refusal bodies gain `meta` (additive; `error-contract.md` allows additive keys). `documentation.md` records why 429 is annotated on three operations and not on all ~50 throttled ones: it is one fact, stated once in the table and in the spec description, with the wait in the `Retry-After` header. `api-architecture.md` § *Known non-conformance* drops the two fixed items and keeps the pagination duplication; the conformance checklist gains a `$request->query()` grep and widens the validation grep to the whole controller tree. Two tests bind `request_id` themselves because they invoke middleware directly, outside the global correlation middleware. |
| **Related** | P12-B3, P12-C6, P12-D7, P12-E2, P12-E4; `docs/planning/phase-12-api-v1.md` § *Post-review fixes*; `docs/base/api/{error-contract,api-architecture,response-contract,documentation}.md` |

## 2026-10-21 — The notification inbox paginates at 10, and the pagination block has one builder

| Field | Value |
|-------|-------|
| **Date** | 2026-10-21 |
| **Change** | `NotificationInboxAction::inbox()` pages at 10 instead of 30, and the API response now carries `data.pagination`. The API had been nesting the paginator inside an array, which is exactly where Laravel stops appending pagination metadata: the web inbox already rendered links, while the JSON payload returned the first page with no way to ask for the next one and nothing saying there was one. The inbox takes no `per_page` parameter — an inbox has no client-chosen page size — so the page size is one constant in the action. `Controller::paginationFor()` replaces the four hand-built `{current_page, last_page, per_page, total}` blocks (users, roles, permissions, audit) and the inbox uses it as the fifth caller. |
| **Reason** | A list that stops at an undocumented ceiling is the same defect as an unpaginated one when the client cannot tell which it received. The four copies also meant a contract change (a renamed key, a fifth key) had four places to reach and no test pinning the shape itself. |
| **Impact** | API: **additive** — `data.pagination` on `GET /api/v1/notifications/inbox`; rows per page drop from 30 to 10, which is a behaviour change for a client that relied on 30. Web: the inbox paginates 10 per page, links unchanged. `response-contract.md` now counts three unpaginated lists (sessions, features, exports) instead of four, and `api-architecture.md` § *Known non-conformance* is empty. `NotificationBenchmarkTest`'s label follows the new page size. |
| **Related** | P9-C5, P12-C6, `docs/base/api/response-contract.md` § Collection, `tests/Feature/Api/V1/Notification/NotificationInboxApiTest` |

## 2026-10-21 — The API document is exported at deploy and served from a file

| Field | Value |
|-------|-------|
| **Date** | 2026-10-21 |
| **Change** | `dedoc/scramble` moves from `require-dev` to `require` (ADR-025), so `/docs/api` and `/docs/api.json` exist in every deployment. The deploy runs `php artisan scramble:export --path="storage/app/openapi.<APP_ENV>.json"` and `App\Support\OpenApi\ExportedDocument` (a `CacheableGenerator` decorator bound in `AppServiceProvider::register()`) serves that file to both routes, falling back to live generation when it is missing or unreadable. `bin/deploy-rollback.sh` deletes the file after restoring an older release. |
| **Reason** | Two measurements decided it. First, `scramble:cache` buys nothing: 703/736/707 ms per request uncached vs 738/747/724 ms cached, because it caches the analysis and the cost is `spec()` building the document. Second, `require-dev` meant `composer install --no-dev` removed the docs routes from the only deployment an operator would switch `api_docs_public` on for. Serving the deployed export takes the same page to 11-16 ms. |
| **Impact** | Production installs a documentation package; it performs no application work. Docs content, routes and gate are unchanged — `api_docs_public` (off by default) or `api_docs.view`. The document is regenerated on every deploy, so it cannot describe a previous release; a rollback removes it rather than serving it against different code. DEP-007 is amended, D-4/P12-A1 are marked superseded, and `documentation.md` § *Availability and access* states the arrangement and the measurement. |
| **Related** | ADR-025, DEP-007, P12-A1, D-4, `.github/workflows/ci-cd.yml`, `bin/deploy-rollback.sh`, `tests/Feature/Api/OpenApiExportTest.php` |

## 2026-10-24 — Phase 12 post-close audit: the document now says what the API answers

| Field | Value |
|-------|-------|
| **Date** | 2026-10-24 |
| **Change** | The generated OpenAPI document described a different API than the code. Two registered extensions close the bulk of it: `ApiErrorResponseExtension` publishes the project's `{message, code, meta}` envelope (plus `errors` on 422) for the six exceptions Scramble infers as `{message}` — 148 of 180 documented error responses were wrong before it — and `AuthorizationDescriptionExtension` appends each route's `can:` / `feature:` to the operation description, since Scramble reads neither — 56 of 59 operations were silent before it. The auth scheme is named `bearerAuth` rather than the vendor default `http`, and `Scramble::resolveTagsUsing()` groups operations by module folder instead of by controller class, so the sidebar has six modules rather than 21 peers. Reachable statuses inference cannot see are annotated with `#[Response]` (login 403/429, password reset 400, register 404, resend 403, test-mail 502, cancel-email-change 403, verify-change 400). Three endpoints that had only the drift gate as proof of existence now have feature tests — password reset, email verification, notification channels — plus `PATCH /users/{id}` and cancel-email-change, and `UserFactory` no longer risks repeating a username. Docs reconciled: `api/documentation.md` (extensions, `bearerAuth`, tags, the two new drift-gate assertions), `api/api-architecture.md` (the three corrections, moved out of *Known non-conformance*), `dependencies/{overview,dependency-matrix}.md` and DEP-007 (production dependency, real URLs and commands), `phase-12-api-v1.md` § *Post-close audit*, `task-tracker.md`, `feature-tracker.md`, `implementation-roadmap.md`, `progress.md`. |
| **Reason** | A document that names a body no client receives is worse than no document: it is a contract the API does not keep, and the drift gate was asserting statuses only, so 148 wrong bodies passed. The same gate was also blind to authorization — a reader could not tell a permission-gated endpoint from a public one. The three untested endpoints were only proven to exist by the route-to-path assertion, never to work. |
| **Impact** | **Documentation surface only:** no route, status code, success shape or runtime behaviour changed. `/docs/api` now renders one Authorize scheme named `bearerAuth`, each gated operation states its middleware, and every published error body matches the contract. Seven new API tests and two document assertions. |
| **Related** | P12-A2, P12-A4, P12-D5, P12-E2, F1–F8, `docs/base/api/documentation.md`, `docs/base/api/api-architecture.md`, `tests/Feature/Api/ApiDocumentationTest`, `tests/Feature/Api/V1/{Auth,Notification}`, `App\Support\OpenApi\*` |

## 2026-10-30 — Phase 14 Group B: backups run queued, on their own disk, with a pre-flight size check

| Field | Value |
|-------|-------|
| **Date** | 2026-10-30 |
| **Change** | (1) **Package decision recorded** (ADR-026): `spatie/laravel-backup` (`^10.3`) is added to `composer.json` by this change; `config/backup.php` now backs up MySQL + `storage/app` and writes only to the dedicated `backups` disk, excluding the destination and `backup-temp` from the source. The package's own mail notifier is disabled. (2) **Schedule is data, not config**: `SystemBackupSchedule` reads `backup_enabled` / `backup_schedule` at run time; `routes/console.php` ticks every minute and dispatches `ExecuteSystemBackupJob` when due. (3) **Execution is queued**: `ExecuteSystemBackupJob` on the `backups` queue and its own `backups` connection (`retry_after` 2000 > the job's 1800s timeout — on the shared connection a second worker would re-reserve a running backup and, with `$tries = 1`, fail it), with the standard retry chain and correlation-aware `failed()`; `bin/run-workers.sh backups` is the worker that consumes it. (4) **Pre-flight capacity check**: `SystemBackupSizeEstimator` measures `storage/app` (minus backup artifacts) plus MySQL `data_length + index_length`, and `SystemBackupRunAction` refuses when the estimate plus a 10% reserve does not fit, auditing `backup.aborted_storage_full` and notifying `backup.execute` holders. (5) **Audit + notifications**: `backup.triggered` is written before the command; `backup.completed` / `backup.failed` / `backup.aborted` notify the same audience. (6) **Manual trigger**: `POST /storage/backup` behind `feature:storage_management` + `can:backup.execute`, throttled `system-backup` (3/hour per operator + IP), dispatching after commit, with a "Run Backup Now" button on the settings page. (7) **Settings**: `backup_enabled` (off by default), `backup_schedule` (`HH:MM`, default `02:00`), `backup_retention_daily|weekly|monthly` (7/4/12) are seeded, validated, whitelisted, and exposed on a Backup card; `SystemBackupRunner` applies the retention values before every run and forgets the package's scoped `Config` instance so the next command rebuilds it. |
| **Reason** | A backup that runs inside a request times out; a backup written to the disk it backs up is not a backup; and a schedule frozen from a config value cannot be changed without a deploy. The pre-check exists because filling a destination to the last byte leaves the host unable to write the log that explains why. |
| **Impact** | **Additive.** New job, new `backups` queue and connection, new settings keys, one new POST route. 16 tests in `tests/Feature/Storage/BackupStrategyTest.php`; `JobConventionTest` covers the new job automatically. The package is a new production dependency; its config points at `backups` instead of `local`, and its own notifications are off. |
| **Related** | ADR-026, P14-B1..B3, `docs/base/infrastructure/{backup-disaster-recovery,queue,storage}.md`, `docs/base/operations/retention.md`, `config/backup.php`, `app/Services/Backup/*`, `app/Jobs/ExecuteSystemBackupJob.php`, `app/Actions/V1/System/SystemBackupRunAction.php` |

## 2026-10-31 — Phase 14 follow-up: one global timezone, applied to the process

| Field | Value |
|-------|-------|
| **Date** | 2026-10-31 |
| **Change** | (1) **One setting** (ADR-027): `app_timezone` replaces `password_security_sweep_timezone`; empty means the application default. Migration `2026_10_30_000000_move_sweep_timezone_to_app_timezone.php` copies the legacy value and deletes the old row, busting the settings cache because a query-builder write fires no observer. (2) **Applied, not displayed**: `AppServiceProvider::bindTimezone()` reads the setting at the end of `boot()` and after a save, then calls `date_default_timezone_set()` and `config(['app.timezone' => …])`. The shipped base zone is captured once in a static so the fallback cannot drift after the first mutation; the read is wrapped in `try/catch` because `boot()` also runs before `system_settings` exists. (3) **Sweep and backup follow it**: the minute scheduler compares `now()->format('H:i')` in the global zone; `backup_schedule` is due at the same wall clock. (4) **API timestamps** keep ISO 8601 and now carry the configured offset (`+07:00` for `Asia/Jakarta`) in `meta.timestamp`, health and every resource. (5) **UI**: a System Timezone card with a "Application default ({{ base }})" first option; the sweep-time row is no longer a two-column pair. (6) **History**: `php artisan timezone:rebase {--from=UTC} {--to=} {--dry-run} {--force} {--chunk=500}` rewrites timestamp/datetime columns across the schema, DST-aware per row, skipping tables with no single-column primary key. |
| **Reason** | Three surfaces kept time and only one was configurable: the sweep honoured its own zone, the backup schedule compared a wall clock against UTC, and everything else (audit trail, health/meta, API resources, settings page) rendered in the process zone. An operator scheduling a 02:00 backup in Jakarta got one at 09:00, and no test noticed because nothing asserted that two clocks agree. Changing the process zone makes one answer correct everywhere instead of teaching N call sites to format. |
| **Impact** | **Runtime behaviour changes once an operator picks a zone.** Timestamps written from that point carry the new zone; rows written before it keep their digits and read shifted until `timezone:rebase` converts them (documented cutover order: maintenance → rebase → save the setting → resume). `queue:work` and `schedule:work` keep the zone they booted with until restarted; `schedule:run` from cron is safe. `failed_jobs.failed_at` is DB-side (`useCurrent()`) and follows the same setting: the MySQL session zone is set to the applied offset on every connection, so a SQL client reads the same wall clock the UI shows. Tests reset `date_default_timezone_set('UTC')` in `TestCase::tearDown()` so the process zone cannot leak between tests. |
| **Related** | ADR-027, P14-F1, `docs/base/features/settings.md`, `docs/base/api/response-contract.md`, `docs/base/infrastructure/queue.md`, `docs/base/security/password-security.md`, `app/Providers/AppServiceProvider.php`, `app/Services/Timezone/RebaseTimezones.php`, `tests/Feature/Settings/GlobalTimezoneTest.php`, `tests/Feature/Console/RebaseTimezonesCommandTest.php` |

## 2026-11-02 — Phase 14 Group C: temporary storage is cleaned, and only temporary storage

| Field | Value |
|-------|-------|
| **Date** | 2026-11-02 |
| **Change** | (1) **One new setting**: `temp_file_retention_hours` (default 24, validated 1–8760) is seeded, validated, whitelisted in `SystemSettingsUpdateAction`, exposed on the Storage card, and mapped to `storage_management` in `config/pennant.php` — so the flag closes the edit surface while the cleanup itself keeps running. (2) **Daily schedule**: `routes/console.php` queues `CleanTemporaryStorageJob` daily on `QueueName::SWEEPS`, on top of the existing threshold-triggered dispatch from `StorageCapacityGuard` (one dispatch per hour via `Cache::add()`). (3) **Audit attribution**: the job now writes `temp_storage.cleaned` with no causer and `properties.causer = 'SYSTEM'`, because a cleanup is a system action on both the sync queue and a worker — `auth()->user()` attributed it to whoever happened to be logged in, and a bare null renders as "Anonymous". The same marker is now written by every system-initiated audit path: scheduled backup (manual runs keep the operator), `StorageCapacityGuard` when no user is in scope, `auth.account_locked`, and `InactivityLock` by default; `auth.login_failed` stays unmarked because a person did try. (4) **Tests**: `tests/Feature/Storage/DataRetentionTest.php` (8 tests) pins TTL purge, the setting-driven window, the exactly-at-TTL boundary, preserved audit rows and soft-deleted models, the audit row's count/bytes, and second-run idempotence. |
| **Reason** | `tmp` was the one disk with a documented TTL and no implementation: `retention.md` described a 1-day window for artifacts "nothing writes there". The uploads that will use it are promoted on save, so an old file there is orphaned by definition — but only if something actually deletes it. The daily run makes the policy true without an upload having to trigger it first. |
| **Impact** | **Additive.** One new settings key (rendered on the existing Storage card), one scheduled job entry. The cleanup deletes files on the `tmp` disk only; audit rows and soft-deleted models are asserted untouched. `DataRetentionTest` 8 tests / 24 assertions; `JobConventionTest` already covers the job. |
| **Related** | P14-C1..C3, `docs/base/operations/retention.md`, `docs/base/features/settings.md`, `docs/base/infrastructure/storage.md`, `app/Jobs/CleanTemporaryStorageJob.php`, `routes/console.php`, `app/Http/Requests/V1/System/SystemSettingRequest.php`, `tests/Feature/Storage/DataRetentionTest.php` |

## 2026-11-02 — Phase 14 Group D: the storage surface is closed behind RBAC and the flag

| Field | Value |
|-------|-------|
| **Date** | 2026-11-02 |
| **Change** | **Audit-and-close**, not greenfield: `storage.view`, `storage.manage`, `backup.execute` and their `storage_management` mapping shipped with Group A, the two route gates with Groups A (`storage.secure`) and B (`storage.backup.store`), and all five audit events with Action/Job writers in A–C. Group D adds the acceptance gate none of them had: `tests/Feature/Storage/StorageAuthorizationTest.php` (7 tests) pins superadmin `Gate::before` on the secure route and the backup trigger, a `settings.view` viewer's 403 on a storage-key write, a flag-off write not persisted and **not named in the `storage_setting.updated` key list**, runtime disk binding and the upload guard still active with the flag off, and the storage audit row discarded with its transaction. Two code corrections fell out of that audit: `$storageChanged` is now derived **after** the feature-flag strip (a discarded storage key can no longer be counted as a storage change if the strip map ever covers the disk keys), and the `PermissionCatalog` docblock now states the D1 decision — the Storage card is edited under `settings.manage`, so `storage.manage` has no route consumer yet. |
| **Reason** | The acceptance criteria for D1–D3 were assertions without a test: every gate existed, but nothing failed if a later refactor removed one. Writing them also exposed that the permissions block claimed `storage.manage` covers driver configuration, which D1 deliberately assigns to `settings.manage`. |
| **Impact** | **No runtime change.** Tests and comments only; the `$storageChanged` move is a no-op under today's strip map (the other storage keys are never stripped) and a guard for the day that map grows. `StorageAuthorizationTest` 7 tests / 11 assertions; full suite 2346 passed / 8451 assertions, Pint clean. |
| **Related** | P14-D1..D3, `docs/planning/phase-14-storage-backup-retention.md`, `docs/base/infrastructure/storage.md`, `app/Support/PermissionCatalog.php`, `app/Actions/V1/System/SystemSettingsUpdateAction.php`, `tests/Feature/Storage/StorageAuthorizationTest.php` |

## 2026-11-02 — Phase 14 Group E: the storage docs now say what the code does

| Field | Value |
|-------|-------|
| **Date** | 2026-11-02 |
| **Change** | Verification gate plus doc reconciliation. Gate: `php artisan test --filter=Storage` 65 tests / 178 assertions; `JobConventionTest` + `SettingsPersistenceTest` 12 tests / 107 assertions; full suite 2346 passed / 8451 assertions; `vendor/bin/pint --test` clean. Doc pass: `retention.md`, `backup-disaster-recovery.md`, `queue.md`, `error-contract.md`, `settings.md` and `audit-trail.md` were checked against the code and needed no change; `storage.md` did, on two points — the `storage_management` sentence named only the settings card (it gates both cards, the secure route and the backup trigger), and the permissions block claimed `storage.manage` covers driver configuration (it does not; that is `settings.manage`). A new `## Permissions` table in `storage.md` now maps `storage.view`, `storage.manage` and `backup.execute` to the surface each one actually gates. |
| **Reason** | Group D exposed the same doc error twice (catalog docblock and `storage.md`), which means the permission story was written from intent rather than from the routes. E is the point where the doc is checked against the route table, not the design sketch. |
| **Impact** | **Docs only.** No code change, so no new test. |
| **Related** | P14-E1..E2, `docs/base/infrastructure/storage.md`, `docs/base/operations/retention.md`, `docs/base/infrastructure/backup-disaster-recovery.md`, `docs/base/infrastructure/queue.md`, `docs/base/api/error-contract.md` |

## 2026-11-03 — Phase 14 fix: a flag-off settings save no longer resets the storage and backup config

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `config/pennant.php` now lists every key the Storage and Backup cards own under `storage_management` — disk, S3, capacity, retention and backup keys — instead of only the two retention keys. With the flag off the cards are hidden and post nothing, and the web form's "absent means reset to the default" contract wrote `local`, empty S3 fields, `backup_enabled = false`, `02:00` and the default retention counts on every save. The existing strip map now covers those keys, so the write is skipped and the stored configuration survives; the same map also keeps them out of `getLive()` while the flag is off, closing the read-side inconsistency. `StorageAuthorizationTest` pins the reset with non-default values for a disk key and a backup key. |
| **Reason** | Group D moved `$storageChanged` after the strip and noted the strip map was incomplete ("a no-op under today's strip map — and a guard for the day that map grows"). This is that day: the map was the one place a full-form save could silently discard the production storage and backup configuration, and nothing failed because the existing test only checked the single key the map already named. |
| **Impact** | **Runtime write behaviour changes only while the flag is off.** A settings save no longer touches storage/backup keys in that state; with the flag on the write path is unchanged. `getLive()` (and therefore `GET /api/v1/settings`) hides those keys while the flag is off — the cards are hidden then too. Disk binding and the upload guard still read the database directly and stay active, as before. |
| **Related** | P14-D1..D3, `config/pennant.php`, `app/Support/FeatureCatalog.php`, `tests/Feature/Storage/StorageAuthorizationTest.php`, `docs/base/infrastructure/storage.md`, `docs/base/api/response-contract.md` |

## 2026-11-03 — Phase 14 fix: the S3 driver gets the adapter it was missing

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `league/flysystem-aws-s3-v3` (`^3.0`, resolved 3.35.3) is now a production dependency, per ADR-028; it pulls `aws/aws-sdk-php`, `aws/aws-crt-php`, `mtdowling/jmespath.php` and `symfony/filesystem` transitively — five packages added, no existing version moved. `Storage::disk('s3')` now resolves to `AwsS3V3Adapter`. `StorageDiskTest::test_the_s3_driver_package_is_installed` pins it. `storage.md` records the package requirement next to the `AWS_*` block. |
| **Reason** | Group A shipped S3 as a selectable driver — disk config, encrypted settings, capacity probe, UI fieldset, docs — but the adapter was never required. `laravel/framework` only suggests it, so the gap stayed invisible until someone selected S3, at which point the first operation threw `Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found`. |
| **Impact** | **No behaviour change for local installs**: building the adapter makes no network call, and only operations against the `s3` disk touch AWS. `composer.lock` grows; deploys running `composer install --no-dev` must be allowed to fetch the SDK, and `composer audit` now covers it. The two advisories `composer audit` reports (livewire/livewire, symfony/mime) predate this change and are untouched. |
| **Related** | ADR-028, P14-A1..A5, `composer.json`, `composer.lock`, `docs/base/infrastructure/storage.md`, `tests/Feature/Storage/StorageDiskTest.php` |

## 2026-11-03 — Phase 14 fix: the local root check resolves the path before comparing

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `SystemSettingRequest::after()` no longer compares `storage_local_root` against `storage_path()` as a string prefix. It normalises the path lexically (`.` / `..` resolved without disk access), resolves the deepest existing ancestor through `realpath()` — which turns a symlink into its target — and re-attaches the part that does not exist yet, then compares. Three tests added: a `..` escape, a symlink planted inside the tree pointing out of it, and a valid root that has not been created yet. |
| **Reason** | The guard existed but a prefix comparison is not containment: `/…/storage/../etc` started with the prefix and resolved to `/etc`, and a symlink inside `storage/` did the same through the filesystem. Both let an administrator point the private disk outside the storage tree, which is the disclosure primitive the check was written to prevent. |
| **Impact** | **Only the `storage_local_root` rule changes.** A path that really resolves inside `storage_path()` is still accepted, including one that does not exist yet (the adapter creates it on first write). Unrelated keys are untouched. `StorageDiskTest` 26 tests / 65 assertions. |
| **Related** | P14-A1..A5, `app/Http/Requests/V1/System/SystemSettingRequest.php`, `tests/Feature/Storage/StorageDiskTest.php`, `docs/base/infrastructure/storage.md`, `docs/base/features/settings.md` |

## 2026-11-03 — Security: two advisories reported by `composer audit` are patched

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `livewire/livewire` 4.4.7 → 4.4.8 (GHSA-mhf7-xp6r-6qrc, authorization bypass in `#[Authorize]` via reordered parameter keys, affected `<4.4.8`) and `symfony/mime` 7.4.18 → 7.4.22 (CVE-2026-45070, header injection via non-token characters, affected `<7.4.22`). Patch-level only; `composer audit` is now clean. |
| **Reason** | `composer audit` reported both after the S3 adapter install. Neither comes from the AWS packages — livewire arrives through `laravel/pulse`, `symfony/mime` through `laravel/framework` / `symfony/mailer` — but both are reachable code in this application. |
| **Impact** | **Patch-level vendor change only**: two packages moved, no constraints in `composer.json` changed, no application code touched. 437 tests across notification, Vantage, Pulse and feature-flag suites pass on the updated vendor tree. |
| **Related** | `composer.lock`, `laravel/pulse`, `laravel/framework` |

## 2026-11-03 — Phase 14 fix: the storage audit row is earned by a change, not by a save

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `SystemSettingsUpdateAction` now compares each storage key in the payload against its stored value before writing `storage_setting.updated`, and the row's `keys` payload lists only the keys whose value differs. Credentials are compared as plaintext, because `encrypt()` draws a fresh IV on every call so a resubmitted secret never matches its own ciphertext. |
| **Reason** | The web form posts every field on every save, so a presence check made the row fire on saves that touched nothing in the storage module — and its `keys` payload named all twelve storage keys each time, which turns the module's event history into a log of page views. P14-D3 documents the trigger as "a storage key changes". |
| **Impact** | **Only the storage audit row changes.** A save that alters nothing writes `system_setting.updated` alone; a save that changes one storage value writes `storage_setting.updated` naming that one key. The row is skipped whenever the changed list is empty, including after the flag-off strip removes the keys. |
| **Related** | P14-D3, `app/Actions/V1/System/SystemSettingsUpdateAction.php`, `tests/Feature/Storage/StorageDiskTest.php`, `docs/planning/phase-14-storage-backup-retention.md` |

## 2026-11-03 — Phase 14 docs: correct the storage, retention and phase claims

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `docs/base/infrastructure/storage.md`: local `used` no longer claims to include backups (they sit in `storage/app/backups`, outside the measured root `storage/app/private`); the permission table now says the capacity gauge lives inside the editable Storage card, so it reads under `settings.manage`, while `storage.view` covers the secure route and the read-only table; a line records that both storage routes are web-only. `docs/base/operations/retention.md`: the schedule table was malformed (`\|\|` on most rows, `\|` on one) and rendered as two broken tables; it is one table again. `docs/planning/phase-14-storage-backup-retention.md`: the no-quota wording matches the rendered string ("could not be measured", not "n/a"); P14-D2 no longer promises a sidebar entry that does not exist; the phase records that API endpoints are deliberately out of scope. `docs/planning/progress.md`: Group F1's review date corrected to 2026-10-31, the date the phase doc and tracker already carry. |
| **Reason** | Each claim described a surface or measurement that the code does not have. A reader planning an upload quota would have under-counted available space by the size of the backups; a reader granting `storage.view` would have expected a gauge they cannot see; the malformed table hid three retention rows inside a broken render. |
| **Impact** | **Documentation only** — no runtime behaviour changes. |
| **Related** | P14-D1, P14-D2, `docs/base/infrastructure/storage.md`, `docs/base/operations/retention.md`, `docs/planning/phase-14-storage-backup-retention.md`, `docs/planning/progress.md` |

## 2026-11-03 — Phase 14 fixes: settings API teardown, env template, after-commit cleanup

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `SettingsApiTest` pins that every key owned by `storage_management` disappears from `GET /api/v1/settings` when the flag is off, not just the one key the existing test named. `.env.example` gains `FILESYSTEM_DISK`, the `AWS_*` keys, `BACKUP_QUEUE`, `BACKUP_QUEUE_RETRY_AFTER` and `BACKUP_ARCHIVE_PASSWORD` — all of which `config/filesystems.php`, `config/queue.php` and `config/backup.php` already read. `StorageCapacityGuard` dispatches `CleanTemporaryStorageJob` with `afterCommit()`, so a save that rolls back no longer queues a sweep for a `tmp` it never used. |
| **Reason** | The API read was already fixed by completing the flag's key list in `config/pennant.php` (P14 finding #1); nothing pinned it, so a later edit could reopen the leak silently. The env template is the only place an operator learns which overrides exist, and the three backup keys were undocumented there. Every other job in the repo dispatches after commit (AGENTS.md), and the guard's own comment already said the caller may be inside a transaction. |
| **Impact** | **No behaviour change in the request path.** The cleanup job now reaches the queue after the surrounding transaction commits; the throttle test fakes the bus, so its assertion is unaffected. |
| **Related** | `tests/Feature/Api/V1/Settings/SettingsApiTest.php`, `.env.example`, `app/Services/Storage/StorageCapacityGuard.php` |

## 2026-11-03 — Phase 14 performance: the measured hot paths

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `StorageCapacityGuard` gained `cachedMetrics()` (60 s per disk root, for `/settings`, `/health` and `HealthCheckService`) and now walks the disk root with a raw `RecursiveIteratorIterator`; `metrics()` stays fresh and remains the only input to `assertCanStore()`. `SystemSettingsUpdateAction` compares the normalized payload against one `SystemSetting::getAll()` snapshot and writes only the keys whose value differs, and re-encrypts an S3 credential only when its plaintext changed. `RebaseTimezones` commits one transaction per chunk. `CleanTemporaryStorageJob` reads mtime and size from one local iterator instead of three disk-API calls per file. |
| **Reason** | Measured over 1 000 files: the gauge walk 25.5 → 3.1 ms, a full settings save 62.6 ms / 70 queries → 10.7 ms / 4 queries, the settings page 87.4 → 45.2 ms, the `tmp` cleanup 491 → 154 ms, a 1 128-row rebase 1 546 → 854 ms. The gauge is polled by every health check, and the settings form posts all ~40 keys on every save. |
| **Impact** | **The display gauge can lag by up to a minute** — the guard's refusal never uses it. A settings save that changes nothing now writes no row (and no cache bust); its audit row and notification still fire. A resubmitted S3 credential keeps its ciphertext instead of being re-encrypted. `docs/base/infrastructure/storage.md` no longer claims logs are inside the measured root. |
| **Related** | ADR-029, P14-A5, `app/Services/Storage/StorageCapacityGuard.php`, `app/Actions/V1/System/SystemSettingsUpdateAction.php`, `app/Services/Timezone/RebaseTimezones.php`, `app/Jobs/CleanTemporaryStorageJob.php`, `tests/Feature/Storage/StorageDiskTest.php`, `tests/Feature/Storage/StorageBenchmarkTest.php` |

## 2026-11-03 — Phase 14 pentest #1: production refuses a plaintext backup archive

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `SystemBackupRunner::run()` refuses to run when `backup.backup.password` is empty and the environment is `production`; a failed run deletes `backup.backup.temporary_directory` (confined to `storage/`); `backup.verify_backup` is now `true`. |
| **Reason** | `spatie/laravel-backup` only calls `ZipArchive::setPassword()` when the password is not null, and the archive holds the whole database plus `storage/app` — including `secure_documents`. A default install therefore wrote readable artifacts and reported success. The plaintext dump also sits in `backup-temp` during the run, which the package leaves behind when the process is killed. |
| **Impact** | **Production installs without `BACKUP_ARCHIVE_PASSWORD` stop taking backups** and surface it as `backup.failed`; set the variable, run `config:cache`, then `queue:restart`. Non-production and already-encrypted installs are unchanged. A corrupt archive now fails the run instead of being announced as completed. |
| **Related** | ADR-026, `docs/base/infrastructure/backup-disaster-recovery.md`, `config/backup.php`, `app/Services/Backup/SystemBackupRunner.php`, `tests/Feature/Storage/BackupStrategyTest.php` |

## 2026-11-03 — Phase 14 pentest #2: the secure route never renders a stored file

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `StorageController::show()` serves `secure_documents` with `Content-Disposition: attachment` (`$disk->response($path, null, [], 'attachment')`) instead of Laravel's `inline` default. |
| **Reason** | `secure_documents` holds arbitrary files and the default disposition renders them on the application origin: a stored `.html` or `.svg` would execute with the viewer's session for every `storage.view` holder. The route has no writer yet, so this closes the gap before an upload feature can open it. Inline preview stays unimplemented until a MIME allowlist exists. |
| **Impact** | **The secure route now downloads instead of displaying.** No consumer exists today; a future preview needs an allowlist, not a revert of this line. |
| **Related** | `app/Http/Controllers/Web/V1/StorageController.php`, `docs/base/infrastructure/storage.md`, `tests/Feature/Storage/StorageDiskTest.php` |

## 2026-11-03 — Phase 14 pentest #3: the capacity guard's coverage is per disk, and now it says so

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `docs/base/infrastructure/storage.md` gains § Guard coverage: a per-boundary table (audit export → `local` via `assertCanStore`, backup pre-check → `backups` free space + reserve, health probe → default disk, unguarded by design and failing closed), plus the rule that any new disk-writing feature calls `assertCanStore($bytes, $disk)` and grows a row. Phase invariant 7 now describes enforcement at today's boundaries instead of promising uploads that do not exist. `HealthCheckService::checkStorage()` and the guard's docblock carry pointers so the probe is not "fixed" into the guard later. |
| **Reason** | Invariant 7 read as if a global capacity check existed; in fact each check names one disk and the probe is deliberately outside the guard. An upload endpoint built on the invariant's old wording would have skipped the guard. |
| **Impact** | **Documentation and comments only** — no runtime behaviour changes. |
| **Related** | Phase 14 invariant 7, `docs/base/infrastructure/storage.md`, `docs/planning/phase-14-storage-backup-retention.md`, `app/Services/HealthCheck/HealthCheckService.php`, `app/Services/Storage/StorageCapacityGuard.php` |

## 2026-11-03 — Phase 14 pentest #4 + #5: the two storage fields that accepted too much

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `SystemSettingRequest::after()` gains a second closure refusing `storage_local_permission` modes with the group/other write bits (`0777`, `0666`, `0277` → 422); `storage_s3_endpoint` / `storage_s3_url` gain `starts_with:https://`. The settings form hint names the refusal; `docs/base/infrastructure/storage.md` records both as admin-only trust boundaries; `docs/base/features/settings.md` rule table matches. |
| **Reason** | A world-writable private disk lets any local user rewrite its contents, and a plain-http S3 endpoint carries every object body in cleartext (SigV4 only protects the credentials). Both fields are reachable solely through `settings.manage`, so the residual — an admin aiming a valid https URL at an internal host — is documented as accepted rather than blocked. |
| **Impact** | **A save that previously persisted `0777` (or an `http://` endpoint) now returns 422 on that field.** `0644`, the form's placeholder, stays valid. Values already stored before this change keep applying at runtime — validation guards the entrance, not the stored row. |
| **Related** | `app/Http/Requests/V1/System/SystemSettingRequest.php`, `resources/views/pages/settings/index.blade.php`, `docs/base/infrastructure/storage.md`, `docs/base/features/settings.md`, `tests/Feature/Settings/SystemSettingUpdateTest.php` |

## 2026-11-03 — Phase 14 pentest #6: the backup job is single-flight by lock

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `ExecuteSystemBackupJob` carries `WithoutOverlapping('system-backup')` with `expireAfter(3600)` — twice `$timeout`. |
| **Reason** | Single-flight previously held only because `bin/run-workers.sh backups` starts one worker — an operational accident, not a contract. Adding a second worker, or a supervisor restart racing the scheduler's `onOneServer()` window, would have overlapped two backups onto one destination and a half-written archive. |
| **Impact** | An overlapping dispatch is released back to the queue until the lock frees; the lock expiry (3600s) outlives `$timeout` (1800s) so it cannot free mid-run. Needs a lock-capable default cache store (Redis in production). |
| **Related** | `app/Jobs/ExecuteSystemBackupJob.php`, `docs/base/infrastructure/backup-disaster-recovery.md`, `tests/Feature/Storage/BackupStrategyTest.php` |

## 2026-11-03 — Phase 14 pentest #7: the health probe's anonymous write is now on record

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `docs/base/features/monitoring.md` § Health Check Endpoint states the residual: `checkStorage()` writes and deletes a two-byte probe on the default disk, unauthenticated by design (F3/ADR-023), cost-bounded by the 10 s check-set cache and `throttle:health`, deliberately outside the capacity guard. |
| **Reason** | The proposed mitigation — probing a cheap dedicated disk — would answer the wrong question, and a longer probe TTL is a no-op while the whole check set is already cached for 10 s. What remained was to write the accepted residual down so a later reader does not "fix" the probe into the guard or onto a scratch disk. |
| **Impact** | **Documentation only** — no runtime behaviour changes. |
| **Related** | `docs/base/features/monitoring.md`, `docs/base/infrastructure/storage.md` § Guard coverage, ADR-023 |

## 2026-11-03 — Phase 14 pentest #8: timezone:rebase names its intent in production

| Field | Value |
|-------|-------|
| **Date** | 2026-11-03 |
| **Change** | `timezone:rebase` refuses to write outside `--dry-run` when the environment is `production` and `--force` is absent. |
| **Reason** | The command rewrites every timestamp column and cannot be undone; in production an interactive `confirm()` either hangs an unattended maintenance window or passes on an accidental newline from stdin. The cutover order (maintenance → rebase → save `app_timezone` → resume) was already documented in the command docblock and ADR-027; the explicit flag closes the invocation side. |
| **Impact** | **Production runs now require `--force`.** Non-production keeps the plain confirmation prompt. `settings.md` mentions the refusal next to the ADR-027 link. |
| **Related** | `routes/console.php`, `docs/base/features/settings.md`, `tests/Feature/Console/RebaseTimezonesCommandTest.php` |

## 2026-11-04 — PHPStan level 8 via Larastan becomes the type gate (ADR-030)

| Field | Value |
|-------|-------|
| **Date** | 2026-11-04 |
| **Change** | `larastan/larastan` added as a dev dependency; `phpstan.neon` set to level **8** over `app/`; the first run's 332 type-precision findings committed as `phpstan-baseline.neon` — every entry annotated with its debt class — with `reportUnmatchedIgnoredErrors` on; a `composer analyse` script makes `vendor/bin/phpstan analyse --memory-limit=1G` the one command. |
| **Reason** | Phase 15 Group D (P15-D1). 2644 tests prove behaviour; nothing proved types. Level 8 catches the nullable/mixed return the runtime suite only exercises on its happy branch. Psalm and Enlightn declined per deviation D-3 — one analyser, one baseline, one owner. |
| **Impact** | Every future `app/` change is gated on a clean analyse; the baseline fails on new errors **and** on stale ignores, so it is a debt ledger that cannot grow or rot silently. CI ownership of the run belongs to P15-E3 (`testing.yml`), not this change. |
| **Related** | ADR-030, P15-D1, `docs/planning/phase-15-comprehensive-testing.md` § 6, `phpstan.neon`, `phpstan-baseline.neon` |

## 2026-11-04 — vite 8.3.0 → 8.3.5 (lockfile only) to clear the high-severity advisory

| Field | Value |
|-------|-------|
| **Date** | 2026-11-04 |
| **Change** | `package-lock.json` only: `vite` 8.3.0 → 8.3.5 plus its transitive build deps (20 version entries, 81 lines). `package.json` is untouched — `^8.0.0` already allowed the patched version, and no package was added or removed. |
| **Reason** | P17-B3 dependency audit at the release tree: `npm audit --audit-level=high` exited **1** on `vite 8.3.0 - 8.3.2` — six high advisories (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm, GHSA-9jrq-w75r-8gcw, GHSA-rq7h-c2jc-7f22, GHSA-vfpm-58rq-9qcg). All are dev-server / `launch-editor` issues in a **dev-only** package (the repo has no runtime npm dependencies), but the `npm audit` step lives inside the `test` job and `deploy` is `needs: test`, so the advisory blocked release. Owner decision: upgrade, not accept/defer. |
| **Impact** | **No application code, no manifest, no runtime dependency change.** Verified by the commands CI runs: `npm ci` exit 0, `npm run build` exit 0 (manifest read; `app-*.css` 49.62 kB / `app-*.js` 15.00 kB / fonts css 2.35 kB, built in 4.12 s), `npm audit --audit-level=high` now exit 0 ("found 0 vulnerabilities"). The Tailwind-stylesheet-is-loaded-nowhere question is untouched and still an owner decision (P17-C1, D-8). |
| **Related** | P17-B3, `docs/planning/phase-17-full-regression.md` § *As implemented*, `.github/workflows/ci-cd.yml` (`test` job), `.github/workflows/testing.yml` (`dependency-audit`, weekly) |
