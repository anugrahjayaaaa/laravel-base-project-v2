# Progress

> Allows a new AI session to understand project state without reading the entire conversation.

## Current Phase

| Phase | Title | Status |
|-------|-------|--------|
| 0 | Architecture & project conventions | DONE |
| 1 | Laravel foundation & environment | DONE |
| 2 | Database foundation | DONE |
| 3 | Authentication foundation | DONE (reconciled 2026-11-04: task-tracker ledger shows 24/24 tasks DONE; Phases 12 and 13 built and tested on this foundation) |
| 4 | User lifecycle & user management | DONE |
| 5 | Password/security lifecycle | DONE — Groups A, B, and C verified |
| 6 | RBAC & authorization | DONE (reconciled 2026-11-04: ledger 42/42 DONE; role write routes carry route-level `can:`, `PermissionCatalog` is the live source — see task-tracker RBAC rows) |
| 7 | Feature availability / feature flags | DONE — Groups A–F. The kill switch is real: a flag off 403s its routes (62/62 across web + API) and drops its menu item, with no superadmin bypass |
| 8 | Settings | DONE — Groups A–E closed 2026-10-03 on `feature/phase-8-settings`. Groups A–D were already shipped by Phase 4F/5 (`SystemSettingRequest`, `SystemSettingsUpdateAction`, the two-column page, `feature:settings` on web + API, `@can` read-only, audit inside the transaction). Group A fixed four design-system defects (error messages invisible on 18 of 22 fields — `input-group` breaks Bootstrap's sibling selector) and added a render gate. Group E closed both gaps: `P8-E1a` proves all 40 numeric bounds reject out-of-range, `P8-E1b` proves the API audit row and partial-key semantics. Added since: `SettingsBenchmarkTest` (read path healthy at 1 query; save is 39 constant queries, **not** an N+1 — scales with whitelist length, not rows) and `SettingsPentestTest` (23 adversarial tests — authorization, role escalation, policy sabotage, unknown-key injection, mass assignment, audit integrity — all pass). Full suite **1015 passed / 3874 assertions**. See `phase-8-settings-management.md` |
| 9 | Notification/mail/queue | **DONE** — Groups A–E complete, every task verified, plus three post-ship passes (code audit, copy rewrite, performance, security). Mail transport in `system_settings` with `.env` as fallback, `mail_password` encrypted and never sent to a view; `bindMailConfig()` rebinds `config('mail')` after commit; test-mail probe; Web + API. In-app engine on Laravel's own primitives: `make:notifications-table`, `/notifications/inbox` (flag + auth, **no permission**) with mark-read and mark-all-read scoped through the viewer's relation, a cached bell badge invalidated on delivery and on mark-read, and `NotificationAudience` resolving the Target Audience Rule through the permission that performs each change. **Five corrections to the brief, each caught by a test rather than by reading:** the unread count cannot live on the `layouts.app` composer (`@include` shares scope, so a child renders first); the audience map must use per-action permissions because `users.manage`/`roles.manage` do not exist and an undeclared permission routes an event to nobody; `config/mail.php` has `scheme`, not `encryption`, so `none` is stored as empty and binds as null; `bindMailConfig()` belongs after the commit, not at boot; and the bell's count is cached because reading it live costs a query on every authenticated page. **D6 closed too** — `NotificationUiRenderTest` no longer fakes permissions through a `Gate::before` override; it builds four real Spatie roles. That took three things a role swap is not: `syncRoles` rather than `assignRole` (Spatie's adds, so narrowing a test's permissions would not narrow them), a `PermissionRegistrar` forget per swap, and clearing `User::can()`'s own `canMemo` — a **separate** cache from Spatie's, and forgetting only the registrar leaves `can('…manage')` answering true for a user who no longer holds it, which makes the test pass for the wrong reason. Verified by removing the memo clear and watching the send-test gate go red. Two bugs found and fixed: a null config default passed to `SystemSetting::getString(string, string)` raised a TypeError the catch swallowed, binding **no** key at all — save returned 200, row written, transport unchanged; and a rename script added a `User` type hint to a class that never had one, 500-ing every email-change path. **Re-audited 2026-10-08 — seven findings, all closed:** the SMTP ciphertext was reachable through the Phase 8 settings module (`GET /api/v1.settings`, `settings.view` is a wider audience than `notifications.view`) — now redacted in `SystemSetting::getAll()`; three actions dispatched notifications INSIDE a transaction against `after_commit => false` queues — moved out, and every notification now declares `$afterCommit = true` because that alone does not survive a caller that owns the transaction; the bell's count was invalidated while its five-row list was not, so badge and dropdown disagreed for five minutes; the `notifications` table had no retention at all (daily sweep, read rows only); send-test-mail had no rate limit; `app/Models/Notification.php` was dead code; and the global mail switch could break the four flows it has no alternative to — verification, email-change, account state, temporary password — which now bypass it via `NotificationChannel::for(essential: true)`. **Copy rewritten** on researched guidance: every class repeated its own title in the body ("Role was updated" over "Role was updated by Ana Silva."), and `sprintf('Your roles%s were changed.', $by)` produced a broken sentence; subjects now stand alone because the bell shows subjects only, and `NotificationCopyTest` pins that across all seven classes and both audiences. **Performance measured:** no N+1, nothing over 2.85 ms, warm bell cache costs 0 queries, inbox is bounded by pagination not by size, audience resolution is 2 queries at 1 or 21 administrators. **Pentest: 11 probes / 108 assertions, no findings** — authorization at four privilege levels on both surfaces, inbox isolation four ways, the credential through four read paths, eight configuration-injection payloads, five channel-switch shapes, relay abuse, stored XSS, and flag enforcement. Tests: **1590 passed / 5396 assertions**, Pint clean. See `phase-9-notifications-mail.md` |
| 10 | Audit Trail | **DONE — Groups A–F closed 2026-10-13.** The module that started as a write path is now a read path on **both** channels. Write: one entry point (`App\Models\Concerns\Auditable`) called from Actions, jobs and two services — zero controllers, zero observers, with `Auditable::auditContext()` as the single place `source`/`ip`/`user_agent`/`request_id` is derived, and **null outside a request** rather than `127.0.0.1` (the console Request's default, which had a scheduler's row claiming a browser pressed a button). Read: a filtered, allowlisted, paginated viewer on web AND API, with `audit.view` and `audit.export` kept separate — reading the trail is not the same act as extracting it. Export: a job that assembles the CSV in `php://temp` and hands `writeStream()` one complete file, on the private disk, reclaimed by a nightly sweep and by `failed()` on the path it can **compute**. **Three defects were found in review rather than shipped:** the success flash was rendered by no admin view (`success` where the house key is `status`), the rate limit was a literal beside a hardcoded tooltip quoting the same number, and raising `$tries` 1→3 made a retry orphan a complete CSV past its own retention window. **Pentest: `AuditPentestTest`, 63 probes** — four privilege levels (superadmin / admin / user / **no role at all**, which takes a different path through `Gate::before()`), both surfaces, and injection split by how each field reaches the query: `sort`/`direction` reach `orderBy()` as an *identifier* and must be refused; `event`/`source`/`subject_type` reach `where()` as a bound *value* and are correctly not. **A fifth defect, found by a fresh pentest pass on 2026-10-14 that probed the FILE rather than the page** (`CsvInjectionPentestTest`, 4 probes): the export handed a spreadsheet a formula. The `actor` and `target` columns carry `users.name`, and `name` is free text on `PUT /profile` — `strip_tags()` drops markup, not a leading `=` — so any authenticated account could set its display name to `=cmd|'/c calc'!A0`, act, and have that string sitting in the extract the incident responder opens on their own workstation. `fputcsv()` is no defence; it quotes a comma or a quote, and neither is what makes a cell a formula. Every cell now passes through `GenerateAuditExport::cell()`, which prefixes `'` when the first non-whitespace character is `= + - @` (CWE-1236) — measured +0.0115 ms/row against 0.245, so ~4.7%. It survived every earlier review because **the web viewer escapes the same payload correctly**, so the defect was in the file and not on screen: nothing in the UI looked wrong. The EMAIL fallback in `causerLabelFor()` was investigated and is not a second vector — the `email` rule requires `local@domain`. **A fourth defect, found by a performance pass rather than a test:** the export ordered `created_at DESC, id DESC` and chunked with `chunkById`, which cursors on `id` — the sort key and the cursor disagreed, so on 1200 rows the file held **999 lines of which only 500 were distinct** (200 rows dropped, 499 duplicated). Now ordered by `id` alone; `$sort` removed from the job rather than left dead. The existing query-count test was structurally blind to it — it measures 3 and 40 rows, both inside one 500-row chunk. **Then two performance fixes from a measured pass.** The export was **99.5% PHP** (12.4ms of SQL across 2466ms at 5000 rows), and the cause was `row()` reading `properties` four times per row: the `'collection'` cast is NOT memoisable (every read is a fresh `json_decode`, warm 16.3µs vs cold 15.1µs), so it is now read once into a local — build cost −31%, output byte-identical. And the four filter dropdown option lists are cached (TTL 3600, busted by `Auditable::audit()`/`auditBulk()`) because `sourceOptions()` is `DISTINCT json_extract(properties, …)`, the one filter **no index can serve on any engine**. That cache does not contradict the "no cache" note in `AuditIndexAction` — that argues about the per-viewer ROW list; option lists are global and identical for everyone. Interleaved A/B: option lists −90%/−96% (2k/20k rows), index page −19%, queries 10 → 6. Full suite **1924 passed / 6482 assertions**. See `phase-10-audit-trail.md`, `phase-12-api-v1.md` |
| 11 | Monitoring/observability | **DONE — Groups A–F closed 2026-10-16** (C2 deferred by decision, D N/A by design). Pulse `^1.8` and the system health dashboard both sit behind the same two independent gates — `feature:pulse`/`feature:health` (deploy-time kill switch) + `pulse.view`/`health.view` (per-role), both failing 403 so a caller cannot tell which fired; superadmin comes through `Gate::before`, `admin` inherits via `PermissionCatalog::all()`. `viewPulse` overrides Pulse's own `environment('local')` definition (overwrite-by-name, our provider boots later — measured, F1 in the breakdown). The web `/health` page and the ungated `GET /api/v1/health` twin answer the same `HealthCheckService` at two authorization levels: the API stays open because its caller is an uptime monitor, and its response shape is the ceiling on what it may ever expose — still ungated after **ADR-023**, which bounded its *cost* rather than its audience: `throttle:health` at `health_rate_limit_per_minute` (120/min per IP) and the check set cached for 10s. **Group E** made the queue conventions enforceable instead of habitual: `QueueName` (`exports`/`sweeps`, set with `onQueue()` in the constructor — a `public $queue` property is a class-load fatal against `Queueable` that `php -l` misses), `$tries`/`$timeout`/`$maxExceptions`/`backoff()` on every job, dispatch-time `request_id` in every payload (`Queue::createPayloadUsing` → `Jobs\Concerns\ReadsCorrelationId` in `failed()`), worker listening `default,exports,sweeps`, and `JobConventionTest` asserting all of it by reflection so a fourth job is covered the day it is written. Full suite **2025 passed / 6987 assertions**, Pint clean. Ten gate cases, not eight: the fifth per route pins D-3 — superadmin respects the flag, because `Gate::before` answers `can:` and `EnsureFeatureIsEnabled` never consults the actor. Horizon evaluated out (Redis-only). **Vantage (MONITOR-003) reversed the D-1 deferral on 2026-10-16**: `storviaio/vantage` `^0.1.25` is the queue monitor, behind the same two gates as Pulse and health — `feature:vantage` kill switch + `queues.view` per-role (`queues.retry` for the retry POST only). The package's provider calls `loadRoutesFrom()` bare, so the app declares the six `vantage.*` web routes itself (`config/vantage.php` → `routes => false`) to get the middleware, and overrides the package's `viewVantage` gate (whose default admits any authenticated user) as a safety net. `store_payload => false` — the package default `true` persists every job payload. `vantage:prune` runs daily on the scheduler behind `config('vantage.enabled')`. Pinned by `VantagePermissionGateTest` (7 cases), plus the two channel-parity / catalogue guards updated for `queues.*` and the six routes. **Follow-up closed 2026-10-17 (recorded here earlier as "reported not fixed"):** `AddSecurityHeaders::policy()` is scoped to `vantage.*` like `pulse` — the two CDN origins and `'unsafe-eval'` travel only with the Vantage routes, and the four script-bearing vendor views are overridden to nonce them; pinned by `ContentSecurityPolicyTest`. See `phase-11-monitoring-observability.md` |
| 12 | API V1 | **DONE (owner-closed 2026-10-21)** — breakdown has 27 tasks in five groups (A docs interface → B infrastructure → C routes → D security → E tests); A is implemented and committed on `feature/p12-a-api-docs`. Docs were a live route with no gate: `RestrictedDocsAccess` consults `Gate::allows('viewApiDocs')` and nothing defined it, so `/docs/api` 403'd for everyone outside `local` — now `api_docs.view` + `AuthServiceProvider::configureApiDocsGate()`, nullable user so a guest is redirected to login (or `401` on the JSON document) rather than 500. Visibility is operator-controlled: the `api_docs_public` setting (off by default, on `/settings`, audited) ORs into the same gate, so the *API Documentation* sidebar entry and the route cannot disagree; Scramble's own middleware is replaced because its `environment('local')` bypass would ignore the setting. Version is `v1` from `API_VERSION`, title follows `APP_NAME`, try-it credentials are `same-origin` (the default `include` would have attached this origin's cookies to a host typed in the panel). Scramble is a production requirement, not `require-dev`: `/docs/api` is a live route and the generated document is served outside `local` too. **The breakdown is audit-and-close, not greenfield:** ~70 routes, the `Resources/Api/V1` transformers and the whole error envelope already ship. Six brief-vs-code conflicts recorded as D-1..D-6, the load-bearing one being **flags return 403, not 404** — Phase 7's decision, pinned by tests, not to be "fixed" into a bug. **Group B (committed 2026-10-20 in four layer commits)** — audit-and-close, and it found a live defect rather than a stale table: the handler's blanket `HttpException 4xx` branch sat **above** `NotFoundHttpException` and `ThrottleRequestsException`, so every route-level 404 and framework-level 429 answered `HTTP_ERROR` while `NOT_FOUND` and `RATE_LIMITED` were documented and unreachable. Fixed by ordering, pinned by `ApiErrorEnvelopeTest`. `meta.request_id`/`timestamp` now ride every handler error — including the `LAST_SUPERADMIN` 409 and CSRF 419 emitted ahead of the `api/*` block — through one `App\Support\ApiError` that `Controller::respond()` also uses. The `! app.debug` gate is gone: the envelope is unconditional and debug only **adds** `debug.exception`, because a body whose keys depend on an env var is not a contract. Eight resources carry explicit `@return array{…}` shapes, `SessionResource` replaced a token map living in `SessionController`, and four hand-built success envelopes collapsed into `respond()` (`UserStateController` had a private copy byte-for-byte identical to it). Doc side: the contract was wrong, not the code — no `Link` header, no top-level `meta.pagination`; a collection is `data.<plural>` plus `data.pagination` with four keys, `per_page` defaults 10, and the inbox is fixed at 10, and the real query-parameter names are not `filter[field]`. `CONFLICT` dropped (nothing emits it), 503 re-attributed to the health probe, which answers with its own unwrapped body. No `SystemSettingResource`/`FeatureResource`: those controllers return a key→value map and an action result, so a transformer would be a passthrough **Group C (2026-10-20)** — routes and controllers verified module by module, and the one real defect was where the breakdown suspected it. `POST /api/v1/auth/unlock` was a redirect closure reading `request()->route('user')` on a route with no `{user}`, so URL generation threw: a probe test measured `500 SERVER_ERROR` for every caller, and the comment beside it was wrong twice (Scramble *did* document the closure, as a 200 with no body, and a redirect cannot be a compat layer when the id lives in the address it redirects to). Removed, not repaired — v1 never shipped — and `UnlockShimTest` pins the 404 envelope plus the absent route name. The `respond($message, 4xx)` refusals the error contract deferred to this group are all migrated: six sites, `data.message` gone from every error body. Login 401 `UNAUTHENTICATED` / 403 `FORBIDDEN` / 429 `RATE_LIMITED`+`retry_after_seconds`; register 404 `NOT_FOUND` (camouflage message kept, code not lied); resend 403/422 `EMAIL_ALREADY_VERIFIED`/429/500, carried as `error_code` on the action payload so the controller translates nothing; verify-change 400 `INVALID_TOKEN`; test-mail 502 `BAD_GATEWAY`. Annotations went where Scramble could not infer: `#[Response(409)]` on the three reachable `LastSuperadminException` routes (delete, deactivate, bulk — force-delete has no guard, only a trashed row is accepted), audit download 404/**410** and retry 404/409 (three refusals share the 409; the body says which), and the settings methods now state the flat key→value map and that `mail_password` is **absent** rather than null. `permissions.index` was the one controller outside the FormRequest rule (`$request->query()`), so its allowlist was invisible to the document and an unknown `sort` fell back silently instead of 422 — it now takes the existing `PermissionQueryRequest`. **C5 closed the gap** after the analysis said it could not be closed by reuse: `FeatureIndexAction` is a view model (`toggle_url` from the *web* route name, viewer-scoped `manageable`, per-flag `permissions`), so the new `GET /api/v1/features` (`features.view`, no `feature:*`) serves its rows **projected** to six fields — slug/label/group/description/enabled/pending — with the projection pinned by test so the page model cannot leak back. No new resolver, no `FeatureResource`: the action already owns the single `activeMap()` read and the snapshot. Coherence is pinned by a toggle-then-read test, so a writer that stops flushing `SNAPSHOT_KEY` fails instead of serving a 30-second-old state. **C8 verified structurally:** zero `DB::transaction`/Eloquent mutations in `Api/V1`, and all 45 Actions imported by an API controller are also imported by a web controller. **Group D (2026-10-20)** — the security surface was audited rather than rebuilt: one real `can:` gap closed (`POST /features/bulk-action` carried no route gate on either channel while its comment claimed an action-dependent permission; both flag actions are `features.manage`), token abilities kept `['*']` with the reason written down (a token is a session in another transport; authority is RBAC re-read per request), the API rate ladder documented (no `api` limiter — the protected group's 15/min is tighter), the audit row's `request_id` now pinned to the response's `X-Request-ID`, and users/roles response parity pinned with the API-only keys as an exact declared list. **Group E (2026-10-20)** — the four per-module API files own the sibling-permission gate in both directions (roles view/create, settings view/manage, features view/manage; the catalogue holds everything except `permissions.view`), each with a happy path and the 422 envelope. `ApiDocumentationTest` is the drift gate: route→path+method, 401 on `auth:sanctum`, 403 on `can:`, 422 on FormRequest actions, the bearer scheme, and no status outside the contract. `ApiErrorEnvelopeTest` now covers all eight codes (400/401/403/404/409/422/429/500) through the handler, with `errors` only on 422 and no `debug` when `APP_DEBUG=false`. Measured against the generated document before writing the gate, the plan's "all four on every operation" was false for health, public auth and input-less reads, so reachability is asserted instead. **Post-review pass (2026-10-21), owner-closed:** `FeatureController::toggle` validated inline on both channels and the web docblock argued for it — now `ToggleFeatureRequest`, shared; the email-change token was read with `$request->query()` on both channels — now `VerifyEmailChangeRequest` (`nullable`, so a missing token stays the documented 400); the three E4 middleware 403s and the docs 401/403 now build through `ApiError`, and `ThrottleJsonResponseTest` asserts `meta` on the **limiter's own** answer, the path that bypassed the handler. 429 is documented as a rule for every throttled operation rather than 50 repeated attributes. Full suite and lint measured after the fixes — see § Test Status. **Post-close document audit (2026-10-24):** the generated document now publishes the contract envelope (`ApiErrorResponseExtension`) and each route's `can:`/`feature:` (`AuthorizationDescriptionExtension`), the scheme is `bearerAuth`, operations are tagged by module, and three endpoints that had no API test are covered. No route, status or success shape changed — see `phase-12-api-v1.md` § *Post-close audit*. |
| 13 | Security hardening | **DONE (owner-closed 2026-10-27)** — five groups, two of them construction and three audit-and-close, across `feature/phase-13-security-hardening`. **A (headers/CSP)** pinned the existing header set from both sides: the CSP nonce matches the document, `'unsafe-inline'` is absent from script-src, HSTS survives redirect and JSON responses, and the `{!! !!}` allowlist is exactly the three users-index sort icons plus the two vendor Pulse emitters. **B (CSRF)** found a live ordering defect: the middleware was *appended* to the `web` group, so `auth` ran ahead of it, and the 419 branch did not match the exception the framework actually throws. `ValidateCsrfToken` now replaces `PreventRequestForgery` in the same group and the 419 page is reachable; `CsrfProtectionTest` pins 419 per verb, the JSON envelope, and the empty `except` list. **C (CORS)** built `config/cors.php` (it did not exist): default-deny, `api/*` only, credentials derived from the origin list rather than a second env key (D-6), refusal pinned against the middleware with two configured origins; `CorsPolicyTest` 8/41. **D (input safety)** changed no production code — the sinks and whitelists already held (only `orderByRaw` is a constant, no `$guarded = []`, every index allowlists `sort`/`direction`), so `InputSanitizationTest` 6/31 fills the gaps the other suites left: index/show escaping of a stored hostile name, mass-assignment refusal on profile and admin update, web sort refusal, undeclared query key absent from the query log. **E (disclosure/limits)** is verification: the HTML 500 branch now proves no exception class, message, source path or stack trace reaches a browser (`SecurityAuditTest`), and `CompositeRateLimitKeyTest` 4/20 pins what each throttle bucket is keyed on — including the first 429 probes for `reset-password` and `email-verification`. Group E2's brief claimed one email from two IPs shares a bucket; the code keys on identifier + IP, so a second address is a second budget and `login-ip` is the bucket that sees a spray — measured, documented in `rate-limiting.md`, and left as shipped. All reported findings are closed (2026-10-27): the action-level login lock's 429 answers `RATE_LIMITED` + `retry_after_seconds`, `X-Request-ID` is accepted on CORS preflight, `CORS_ALLOWED_ORIGINS=*` is honoured only uncredentialed, `RolesChangedNotification::lines()` flattens line breaks so a role name cannot fabricate a mail line, and the leftover `echo "DEBUG Request …"` lines are gone. Full suite 2241/8034, Pint clean. Detail: `docs/planning/phase-13-security-hardening.md`. |
| 14 | Storage/backup/retention | **DONE (owner-closed 2026-11-03)** — Groups A–F shipped on `feature/phase-14-storage-backup-retention` (card/driver binding/capacity guard/507/secure media, async backup engine, retention + cleanup, RBAC/flag/audit, verification + docs, global timezone `app_timezone` + `timezone:rebase`), then a pentest remediation pass the same day closed 8 findings (#1–#8: production refuses plaintext archives, secure route serves downloads, guard coverage mapped, world-writable modes refused, S3 urls https-only, backup job single-flight, health probe residual recorded, rebase `--force` in production). API/web capability sync checked per the phase-12 method — the three web-only surfaces are deliberate and documented. Full suite **2366 passed / 8524 assertions**, Pint clean. **Runtime verification deferred**: the owner has not exercised backup/restore, real S3 or a live rebase by hand; real-world use is the pending evidence. Detail: `docs/planning/phase-14-storage-backup-retention.md` |
| 15 | Comprehensive testing | **DONE (owner-closed 2026-11-04, merged as PR #41)** — breakdown written 2026-11-04: 17 tasks in five groups (A view/render, B unit, C feature/API lifecycles, D static analysis + security audits, E performance/coverage/CI). **Group A DONE** (implemented 2026-11-04, owner-reviewed 2026-11-04): 23 tests, `tests/Feature/UI/{BladeComponentRenderTest,FormViewRenderTest,ResponsiveUiTest}.php`, plus one defect the sweep found — the sessions table had no `.table-responsive` wrapper. **Group B DONE** (owner-reviewed 2026-11-04): 236 tests / 889 assertions across `tests/Unit/{Actions,Requests,Rules,Policies,Support}`; one live defect found and fixed — `PersistsRole` stripped every permission on an update whose payload omitted the `permissions` key. **Group C DONE** (owner-reviewed 2026-11-04): audit-and-close done with four coverage maps; 10 new test methods fill only the rows the maps showed missing — session revocation ×3 proven through the real `account.state` stack (`WebSessionRevocationTest`), deactivated login, tampered verify-hash, force-change loop, bulk last-superadmin delete/force_delete, flag-off 403-not-404 on a real api.v1 route, pagination four-keys/`per_page` 10/no-`Link`, real `scramble:export` integrity, tampered storage signature. Recorded not faked: web verify has no signature/expiry check (design question), real S3 and backup→restore deferred, API list N+1 belongs to E1. **Group D DONE** (implemented 2026-11-04, owner-reviewed 2026-11-04): D1 installed the analyser the repo never had — ADR-030 + changelog first, Larastan ^3.14/PHPStan 2.3.5 at level **8** over `app/`, `composer analyse`; the first run's 332 findings committed as a 310-entry annotated debt ledger (`reportUnmatchedIgnoredErrors`: fails on new errors **and** stale ignores; both sabotage-verified; deviation D-12 — mostly own-code PHPDoc precision debt, not vendor noise). D2 flipped the style gate to `pint --test` over the full tree (484 files; `fetch-depth: 0` removed with its `--dirty`-only reason). D3 mapped coverage first and added only the missing rows: 507 web branch message-only, guest-with-valid-signature never serves bytes, cross-path signature replay 403, 507 into the shared envelope matrix (nine codes, not eight), rebase refusal message + by-design production `--dry-run`; `GenerateAuditExport`'s attack path and the backup fail-open attack recorded unprobed. D4 shipped `.github/workflows/testing.yml` — weekly Mondays `composer audit --locked` + `npm audit --audit-level=high`, same thresholds as the unchanged PR run; actionlint clean; caveat: `schedule` only fires from the default branch. Group E in REVIEW (2026-11-04): E1 extended `PerformanceGuardTest` audit-and-close — web lists, the bell warm path and the 3-vs-40 export were already pinned (recorded, not duplicated); three new guards, each sabotage-verified (API list 3→40 rows identical query counts, eager-load sabotage red 23 vs 9; chunk-straddle 501-vs-502 export probe in `AuditExportTest`, chunk 500→1 red 1510 vs 1507; peak memory 24 MB bar, measured 7.6 MB, 40 MB allocation red). Wall clock stays recorded (D-8). E2 made the thresholds real: one clover run feeds `--min=85` plus `bin/coverage_gate.php` (Actions ≥ 95, Policies = 100, Requests ≥ 95 — bar assignment recorded as D-13); first run had Requests at 94.26 % FAIL, closed with `RequestGuardsTest` (8 refusal/cooldown branch tests) to 98.09 %, final global 94.87 % (5031/5303), gate sabotage-verified (zeroed Action → 94.73 % FAIL, exit 1); the dead auth-request accessors (12 statements) are recorded as delete-candidates for the owner, not deleted by a testing phase. E3 split `testing.yml` by trigger — `phpstan` per PR, `coverage` on schedule + release (pcov), `dependency-audit` weekly — `ci-cd.yml` untouched, actionlint clean. Groups C–D are audit-and-close over suites that already exist; the new work is the unit layer, PHPStan level 8, and coverage thresholds. Thirteen brief-vs-code conflicts recorded as D-1..D-13 (no Pest, no Psalm/Enlightn, no `SanitizesInput`, no `RolePolicy`/`SystemSettingPolicy`, `testing.yml` must not duplicate `ci-cd.yml`, the type ledger holds own-code precision debt). Detail: `docs/planning/phase-15-comprehensive-testing.md` |
| 16 | Documentation verification | **DONE (closed 2026-11-05 by P17-D2 on the Phase 17 run logs; E1/E2/E3 flipped in `task-tracker.md`)** — breakdown written 2026-11-04: 14 tasks in five groups (A docs interfaces/Markdown compliance, B schema + Action-naming + setting-key mappings, C security/RBAC/backup/retention docs, D audit-event map + runbooks, E link-integrity gate + regression + tracker closure). Audit-and-close: the properties mostly already have owner tests; seven brief-vs-code conflicts recorded as D-1..D-7 (no in-app Markdown renderer, no duplicate docs render test, doc paths live under `docs/base/`, ~60 setting keys not 37). **Group A DONE (owner-reviewed 2026-11-04)**, **Groups B–D DONE (owner-reviewed 2026-11-04)**. Group A: A1 ran the owners green (22/70 incl. real export diff) and measured the exported doc (48 paths/59 ops, statuses all inside the contract) — findings: JR001 `HealthCheckResource` inference (B1 closed it as cosmetic — the health schema exports complete) and recorded as by-design (507 per-op absence, web-only storage surfaces); A2 scripted sweep of 96 files fixed 1 broken link + 1 heading skip and recorded the fences-with-info baseline (46 % → house style, not gated); A3 produced no code — no render gap existed, and the brief's new test file would duplicate `ApiDocsUiRenderTest` (D-4). **B1** walked the exported doc: every response carries a schema, zero statuses outside the contract, all 8 resource shapes hold — zero fixes, proof not assumption. **B2** found the drift inside naming-conventions.md itself (prose "every class" vs its own BulkActionProcessor example): scoped to Action classes + named handler exception family, dead example fixed, domains table 6 to 9 rows; enforcement already owned by ActionNamingConventionTest, no new gate. **B3** measured 75 validated keys (66 + 9 mail), 0 undocumented, settings.md block diffs 66-66 exact. **Group C audited (2026-11-04)**: C1 diffed `PermissionCatalog` against the three RBAC/flag docs — 3 stale spots fixed (invented `roles.manage`, fake names in `dependencies/overview.md`, missing `storage_management`/`api_docs.view` rows in `feature-flags.md`). C2: backup doc verified accurate; the generic Restore Procedure rewritten as a concrete runbook with the zip layout read from the shipped vendor source (db dump plain SQL, absolute-minus-slash paths, ZipCrypto) — round trip stays deferred-as-deferred; retention.md corrected where it promised mechanisms that do not exist (reset token is 15 min not 60, revoked tokens deleted on revoke, no failed-jobs/log prune ships) and its `retention.*` pseudo-keys relabelled design targets. C3 re-derived the limiter table from `RateLimiter::for()` (15 registered, `system-backup` was undocumented) and fixed `web-security.md`'s "Policy-based" authorization line. `--filter=Security` 139/718 green, Pint clean, no production code changed. **Group D built (2026-11-04)**: D1 built the repo's first complete audit **Event Map** — 48 distinct events enumerated from `->audit()`/`auditBulk()` call sites (not strings), grouped Auth 13 / User 16 / Role 5 / Feature 2 / SystemSetting 12, each row citing its owner, SYSTEM-marker semantics recorded as implemented, pinned reference `ActionFirstAuditTest` (green). D2 walked `docs/base/operations/*` cold and fixed four runbook defects: a phantom `audit.enabled` triage step, a malformed tools table, mail triage starting at the `.env` fallback instead of `system_settings`, and a deployment checklist with **no process-start step at all** (now names `bin/run-workers.sh local/backups/cron` + the fail-closed seeder warning); every command verified to resolve as written, gap list empty. Also repaired 17 pre-existing broken table rows in `task-tracker.md` (leading `||`, inherited from `main`). Pint clean. **Group E closed 2026-11-05 by P17-D2 (was in REVIEW 2026-11-04)**: E1 shipped the docs tree gate (`tests/Arch/DocumentationLinkIntegrityTest.php` — relative links resolve, heading hierarchy never skips, no table row begins with a double pipe; fence-aware, 3/3 green <1 s, each gate sabotage-verified). The pipe gate caught repo-wide `||` corruption inherited from `main`; 400+ rows swept across `docs/`. E2 ran the three gates on the final tree: Pint PASS, PHPStan exit 0, `php artisan test` **2665 passed / 9834 assertions** exit 0 (+4 tests = the E1 gate alone). E3 reconciled the trackers: roadmap phases 0–15 → DONE, progress rows 3/6 flipped on ledger evidence (24/24, 42/42), Documentation Status re-marked with per-area evidence, qa-tracker verified 0 open. Detail: `docs/planning/phase-16-documentation-verification.md` |
| 17 | Full regression / final review | **DONE (Groups A, B and C run 2026-11-04; Group D run 2026-11-05; closed by P17-D2/D3 on the run logs — nothing committed, owner review pending)** — breakdown written 2026-11-04: 12 tasks in four groups (A full suite + coverage bars + UI render → B Pint, PHPStan, dependency audits → C asset build, `optimize`, scheduler → D code-to-docs reconciliation, tracker closure, release summary). **Group A run 2026-11-04**: `XDEBUG_MODE=coverage php artisan test --coverage --min=85` exit 0 at **2665 passed / 9834 assertions, 9 risky** (763.70 s); `bin/coverage_gate.php` exit 0 at global **94.87 %**, Actions **97.11 %**, Policies **100 %**, Requests **98.09 %**; UI render owners green at **137 tests**, with the per-page query-count gates and the unproven remainder listed in `docs/base/testing/testing-matrix.md` § *UI render & view-query coverage*.  **Group B run 2026-11-04**: Pint exit 0 (PASS 487 files), PHPStan exit 0 (`[OK] No errors`, 208 files at level 8, ledger still 310 entries), `composer audit --locked` exit 0, and `npm audit --audit-level=high` first **exit 1** on `vite 8.3.0` (6 high advisories, dev-only class) → owner-decided bump to **8.3.5** lockfile-only → **exit 0, found 0 vulnerabilities**, verified by `npm ci` + `npm run build` and recorded in `docs/planning/changelog.md`. **Group C run 2026-11-04**: `npm ci` + `npm run build` exit 0 (vite 8.3.5; `app-*.css` 49.62 kB / `app-*.js` 15.00 kB; D-8 measured — the built stylesheet and the font CSS are referenced by no layout, `@viteFonts()` never used, so the Tailwind input stays an open owner decision and nothing was deleted); `php artisan optimize` **first exit 1** on a non-serializable `scramble.security_strategy.1.scheme` → fixed with `App\Support\OpenApi\BearerAuthSecurityStrategy` + scalar config + the new `tests/Arch/SerializableConfigTest.php` gate → **exit 0**, then **16 pages 200** with config/events/routes/views warm in one login session, and Pennant + `SystemSetting` writes proven still runtime-mutable under a cached config (`optimize:clear` restored dev state, exit 0); `schedule:list` exit 0 with the six-entry set cross-checked against `routes/console.php` — the 00:00 question answered safe (sweeps → `sweeps` queue vs backup → `backups`, both listened by `bin/run-workers.sh`, defaults not even the same minute). Suite re-measured after C2's code change: `php artisan test` exit 0 at **2668 passed / 9837 assertions, 9 risky** (+3 = the new gate plus two `NoInlineFqnTest` per-file cases; Pint PASS 489 files, PHPStan exit 0 at 209 files, ledger still 310). **Group D run 2026-11-05**: D1 re-dated every `docs/base/` area in § *Documentation Status* including Testing and UI (the two Phase 16 left bare), wrote the enforced coverage bars and the measured figures into `docs/base/testing/testing-strategy.md`, and filed — did not delete — the D-8 Tailwind conflict in `docs/base/ui/ui-adminlte-setup.md`; D2 reconciled all 99 `qa-tracker.md` scenario rows against the suite (**77 DONE / 8 PARTIAL / 13 UNPROVEN / 1 N/A**, every gap naming the assertion it is missing), closed Phase 16's E rows, flipped this phase's twelve rows on the run logs, and reconciled the 15 stale entries in this file's JSON ledger; D3 wrote `docs/planning/final-release-summary.md`. `tests/Arch/DocumentationLinkIntegrityTest` green 3/3 over the Group D tree. Framed as run-and-record, not build: every A/B gate exists and is sabotage-verified from Phase 15 (`bin/coverage_gate.php` bars, PHPStan level 8 + ADR-030 ledger, full-tree Pint, `composer audit`/`npm audit` in CI) and Phase 16; the new work is C2 (warm-cache boot), C3 (six scheduler entries audited as a set) and D1–D3. Twelve brief-vs-code conflicts recorded as D-1..D-12, the load-bearing ones being **Psalm not installed — one analyser owns the ledger**, **no tree-wide "zero queries in Blade" gate exists (proven per page by query-count deltas)**, **`qa-tracker.md` carries 58 of 99 rows unreconciled with the suite, so a blanket CLOSED stamp would make the tracker lie** (settled by P17-D2 on 2026-11-05: 77 DONE / 8 PARTIAL / 13 UNPROVEN / 1 N/A, the 21 open rows each naming its missing assertion), and **the Vite build ships a Tailwind stylesheet no layout loads while `AGENTS.md` says "No Tailwind"** (owner decision, reported not deleted). Hard predecessor: Phase 16 Group E must leave REVIEW before P17-D2 — satisfied 2026-11-05, E1/E2/E3 closed on the Phase 17 run logs. Detail: `docs/planning/phase-17-full-regression.md` |
| 18 | General membership & loyalty system | **Group A DONE (shipped 2026-11-05, row flipped in P18-E2) — five `pages/members/*` Blade views, `member_loyalty` declared in `config/pennant.php`, `MemberUiRenderTest` 10 tests with flag-guard and no-DB-in-Blade gates sabotage-verified; the voucher view moved to `pages/vouchers/index.blade.php` under its own `vouchers` flag per owner decision — its gate is `VoucherUiRenderTest`, recorded as P20-A6; full suite 2687 passed / 10043 assertions, Pint clean); **Group B read-only slice in REVIEW (same day, pulled forward by the owner so the sidebar has routes to point at — D-4 amendment): B1 in full (four migrations, four models + four factories, `User::member()`, four `MorphMap` aliases), the catalogue half of D1 (`members.view`/`manage`/`notify` in `PermissionCatalog` + `FEATURE_BY_RESOURCE`, seeder and role matrix pick them up with no new rows), and the read half of B6 (four GET routes behind `feature:member_loyalty` + `can:members.view`, three thin web controllers, four read Actions, three `AppMenuComposer` entries (Members, Membership Dashboard, Tiers)), proven by `MemberWebTest` — real-data 200s for superadmin, 403 for staff, 403 with the flag off even for a superadmin, sidebar entry drops. Write URLs arrive as `null` from the Actions, so the views remove the button rather than link at a missing route; `ChannelParityTest` holds the four reads as a declared one-sided capability `membership screens` until `Api/V1/Member` lands; every write path stays in B2–B5 and the API twins in B6; full suite 2709 passed / 10162 assertions / 9 risky, Pint PASS 512 files, PHPStan `[OK] No errors`**; **same-day follow-up slice in REVIEW (owner asked six things): the `member` system role (`SystemRole::MEMBER`, seeded with no permissions) + the Add-new-member flow (`MemberCreateRequest`/`MemberCreateAction` → `UserCreateAction`, so a member is a verified login account, 403 without `users.assign_roles`); manual points (`MemberAdjustPointsRequest`/`MemberAdjustPointsAction`: signed delta or a `purchase_amount` priced by the System Setting earn rule, ledger row + balance under `lockForUpdate()`, negative balance refused); four System Setting keys replacing the hard-coded values — `member_expiry_alert_window_days` (the dashboard's 14-day constant, read with a 14 fallback), `member_points_expiry_date` (empty = never expire; `MemberExpirePointsAction` + `MemberPointsExpirySweepJob`, the seventh `schedule:list` entry, advances itself a year), `member_points_earn_amount` / `member_points_earn_value` — all four declared under `member_loyalty`'s `settings` group and rendered in a new Membership card on `/settings`; sidebar moved to its own **Membership** label and the Tiers entry fixed (it had named `members.tier`, a route that never existed, and `tiers.view`, a permission the catalogue never had — `AppMenuComposer` filters `Route::has()` first, so the row vanished silently); `MemberDemoSeeder` (10 members + tiers + subscriptions + ledger, runnable, deliberately NOT wired into `DatabaseSeeder`). Gates run targeted per the new `agents.md` rule: `tests/Feature/Member` 31 passed / 203 assertions, `tests/Feature/Settings tests/Feature/Queue tests/Arch` 639 passed, `tests/Feature/Role tests/Feature/Console ChannelParityTest` 272 passed, `tests/Feature/User tests/Feature/UI` 154 passed; Pint PASS 480 files, PHPStan `[OK] No errors` (the full suite was NOT re-run — owner instruction 2026-11-05)**; **same-day tier slice in REVIEW (owner asked for three default tiers, no superadmin in the tier role picker, a real currency on every price, and create/edit/delete screens modelled on the users feature): `currencies` reference table + `CurrencySeeder` — a static list, not an HTTP pull like `TimezoneSeeder`, because a screen that prints a price must not depend on a service being up — plus `currency_code` as a global setting on the Localization card (owned by no flag; default `IDR`, `exists:currencies,code`) and `App\Support\Money`, which prints symbol and separators per currency so `Rp 35.000,00` is right and a `$` amount does not inherit that convention. `MemberTierSeeder` ships Bronze 30 d / Silver 60 d / Gold 100 d at 35.000 / 70.000 / 100.000 with ×1.00 / ×1.50 / ×2.00, called from `DatabaseSeeder` as starter configuration and idempotent through `withTrashed()->firstOrNew()` + `restore()` because a soft-deleted slug still holds the unique index. The tier inline row editor is replaced by `pages/members/tier-form.blade.php` (one page serving create and edit) behind five new routes — `members.tiers.{create,store,edit,update,destroy}`, each `can:members.manage` inside `feature:member_loyalty` — with `MemberTierRequest` and Create/Update/Delete Actions auditing `member_tier.created` / `.updated` (changed fields only, `UserUpdateAction`'s shape) / `.deleted`; a delete is refused while any subscription sits on the tier, so the answer there is deactivate. `MemberTier::assignableRoleOptions()` hides `superadmin` unconditionally (not viewer-scoped like `visibleTo()`) and the FormRequest validates against that same set, so a payload naming it gets a field error. `MemberDemoSeeder` now calls the tier seeder and derives each subscription's status from `expires_at`, so lapsed rows appear without anything asserting them; the sidebar Tiers entry matches `members.tiers*` to stay lit on the form pages. `ChannelParityTest`'s one-sided `membership screens` entry now holds thirteen web routes. Gates targeted: `tests/Feature/{Member,Settings,Role}` + `ChannelParityTest` = 392 passed / 2336 assertions, Pint PASS 533 files, PHPStan `[OK] No errors`**; **same-day trash + bulk slice in REVIEW (owner report: a trashed tier never came back on screen — the list read the default scope, so soft delete was a one-way button): Active / Inactive / Trash nav pills on both membership lists (`status` validated, default `active`, tabs built by `MemberIndexAction` / `MemberTierIndexAction` because D-4 keeps `route()` out of Blade), six new web routes (restore, permanent delete, bulk — for members and for tiers), `members.delete` / `.restore` / `.force_delete` split out of `members.manage` (D-6 amended for removal only), and the shared `BulkActionProcessor` + `AuthorizesBulkAction` wired to `MemberBulkActionHandler` / `MemberTierBulkActionHandler`. Proven by `tests/Feature/Member/MemberTrashTest.php` (14 tests) plus the re-granted `MemberDeleteTest` / `MemberTierTest` and a manage-alone-refused regression test. Gates targeted: `tests/Feature/Member` 69 passed / 392 assertions, `tests/Feature/Role` 261 passed / 1103 assertions, `ChannelParityTest` (one-sided entry now nineteen routes) + `PermissionCatalogTest` + `PermissionFeatureMapTest` green, Pint PASS 543 files, PHPStan `[OK] No errors` — `tests/Feature/CrossCutting/ActionNamingConventionTest` is **red on this branch for two names shipped by the earlier points slice** (`MemberAdjustPointsAction`, `MemberExpirePointsAction` are verb-first inside the `Member/` folder); not renamed here, awaiting the owner**; **same-day owner revision in REVIEW (two asks: make members and tiers switch on/off like users, and make every row action an icon with its name on hover): `members.is_active` (new column, the member's own switch, shaped like `users.is_active`) plus `member_subscriptions.paused_at` — a non-punitive deactivation freezes the clock, a violation cancels the period — with `MemberActivateAction` giving the paused days back and `MemberDeactivateAction` writing `last_tier` / `points_balance` / `remaining_days` / `violation` / `reason` into the audit row so an admin can rebuild the state by hand. `members.activate` / `members.deactivate` joined `PermissionCatalog::MEMBERS` and the four new routes; the deactivate modal carries a violation switch that reveals a required reason (hidden **and** disabled on every other action, so no other confirm submits a field it never had). Both lists render icon-only actions with `title` (View / Edit / Activate / Deactivate / Move to Trash), and the members summary card puts the tier badges on their own row under Summary instead of floating between the two labels. Inactive members now have their own tab content: the Active tab counts `is_active` **and** a live subscription. Proven by `tests/Feature/Member/MemberStateTest.php` (11 tests). Gates targeted: `tests/Feature/Member` 80 passed / 440 assertions, `tests/Feature/Role tests/Arch` 854 passed, `tests/Feature/CrossCutting tests/Feature/Audit` 379 passed with the pre-existing `ActionNamingConventionTest` red unchanged — `tests/Feature/Member/MemberUiRenderTest` fixtures updated for the two new row keys**; **subscribe/cancel + API twins slice in REVIEW (2026-11-06, owner chose the remaining Group B scope): `members.subscribe` / `members.subscriptions.cancel` put the P18-B3 subscription Actions behind a route for the first time (`MemberSubscribeRequest`, both gated `members.update` — no new permission, the gate the detail page already assumed), and `MemberShowAction` now hands real `subscribeUrl` / `tierOptions` / `cancel_url`, which is why not one Blade line changed: the buttons were already conditional on those keys. `Api/V1/Member/{MemberController,MemberTierController}` + four resources and 21 `api.v1.members*` routes under `feature:member_loyalty` — same Actions, same Form Requests, same per-route `can:`, so the module meets the three-layer standard and the API is independently usable. The two list queries went public and the web decoration moved into `run()` rather than being copied, which is what `ResponseParityTest` now diffs. `ChannelParityTest`: one-sided `membership screens` deleted, 21 two-sided capabilities in its place, `membership dashboard` the sole declared exception. Proven by `tests/Feature/Api/V1/Member/{MemberApiTest,MemberTierApiTest}.php` (23) + five new `MemberSubscriptionTest` cases. The four resources read `$this->resource->…` instead of magic `$this->…` to keep 33 PHPStan ledger entries out of the debt file (ADR-030 size unchanged). Group B closes except `BroadcastMemberExpiryRequest`, which rolls into P18-C2 with the broadcast route it guards (owner 2026-11-06). Gates targeted: `tests/Feature/Api/V1/Member tests/Feature/Member` 121 passed / 619 assertions, `ChannelParityTest` + `ResponseParityTest` 9 passed / 40 assertions, `ApiDocumentationTest` + `OpenApiExportTest` + `ApiDocsUiRenderTest` 22 passed / 70 assertions (the export documents every new route), Pint PASS 561 files, PHPStan `[OK] No errors`; full suite not run (owner rule)**; **Group B DONE 2026-11-06** — exit gate re-run on the committed tree: `tests/Feature/Member tests/Feature/Api/V1/Member tests/Feature/CrossCutting` 192 passed / 1168 assertions, Pint PASS 561 files, PHPStan `[OK] No errors`; tracker rows B1–B4 + B6 flipped on that evidence**; **Group C DONE (2026-11-06, rows C1–C3 flipped in P18-E2): `MemberSubscriptionExpireAction` + `MemberSubscriptionExpirySweepJob` (sweeps queue, chunkById 500, daily 00:30 — staggered off the points sweep's midnight, the collision question answered) expire lapsed unpaused periods, revoke only the role that row applied through the `RevokesSubscriptionRole` trait now shared with the cancel Action, audit `member.expired` with `causer = SYSTEM`; `SendMemberExpiryNotificationJob` + `MemberExpiryWarningNotification` (mail + in-app, D-3, `$afterCommit`) send one earliest-expiry warning per member and the request that triggers them returns after pushing one job — 1 000 members become 1 000 queued mail deliveries, zero SMTP in the web process; the trigger ships two-sided: the broadcast gate lives on the route — `can:members.notify` (D-6 — `members.update` alone 403s, proven; the planned request class was deleted same-day per owner: empty `rules()` behind a route that already names the permission), `membership.broadcast-expiry` + `api.v1.members.broadcast-expiry`, parity capability `expiry broadcast`. Eighth `schedule:list` entry verified. Proven by `MemberExpirySweepTest` (6) + `MemberExpiryNotificationTest` (9) — the latter overrides `shouldFakeNotifications(): false`, because the global fake in `Tests\TestCase` would otherwise make the queue-out assertion count nothing. Gates targeted: `tests/Feature/Member` 113 passed / 531 assertions, `ChannelParityTest` + `NotificationCopyTest` + `tests/Feature/Queue` 142 passed / 722 assertions, `tests/Arch` 620 passed, API docs gates 13 passed, Pint PASS 570 files, PHPStan `[OK] No errors`; **uncommitted, awaiting owner REVIEW**. Docs: `queue.md` SWEEPS row, `audit-trail.md` `member.expired`, `notifications.md` category row, `membership.md` two new sections**; **owner revision 2026-11-06 (uncommitted, same tree): the web Adjust Points card + `members.points` route deleted** — a balance move belongs to the Phase 20 checkout, so the manual form on the profile went; `MemberAdjustPointsAction`/`Request`, the API endpoint and its Scramble docs are untouched; `MemberPointsTest` now posts to `api.v1.members.points`, `ChannelParityTest` declares the capability one-sided `api`, and the `member points adjust` fixture keys left `MemberUiRenderTest`. Gates targeted: `tests/Feature/Member tests/Feature/Api/ChannelParityTest.php tests/Feature/Api/V1/Member` 139 passed / 650 assertions, Pint PASS 570 files, PHPStan `[OK] No errors`**; **Group D DONE (2026-11-06, rows D1–D3 flipped in P18-E2): the group was already shipped slice-by-slice through B and C — what D owns is the proof, and the proof was thin on the web side. `MemberWebTest` now loops all 25 web member/tier routes twice: zero permissions (staff) and flag-off-for-superadmin, every address 403; `MemberApiTest`'s existing loops gained `api.v1.members.broadcast-expiry`, the one C2 route that had slipped out of them. Audit events were enumerated from `->audit()` call sites per the P16-D1 method: 19 events, all 19 with Event Map rows, refusal paths write nothing. No new `MemberAuthorizationTest`/`MemberAuditTest` files — the route loops and the per-Action suites are the acceptance; the two planned filenames were a shape, not a requirement. Gates targeted: `tests/Feature/Member tests/Feature/Api/V1/Member tests/Feature/Api/ChannelParityTest.php tests/Feature/Role` 400 passed / 1842 assertions, `tests/Feature/Audit` 309 passed, Pint PASS 570 files, PHPStan `[OK] No errors`**; **Group E DONE 2026-11-06 — closure run `XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit`: 2923 tests / 11152 assertions / 9 risky, exit 0, 17:56. Four bars held on that run's own clover (global 94.31 %, Actions 96.62 %, Policies 100 %, Requests 98.10 %, `bin/coverage_gate.php` exit 0), the PHPStan ledger stayed at 310, and the run returned three defects that were fixed rather than explained away — the two-dot `members.tiers.*` slugs renamed `member_tiers.*` (catalogue, routes, requests, views, menu, seed test, data migration) and the member-deactivation fields leaking out of the globally `@include`d confirmation modal, now `@push`ed from the member list only and pinned by a regression test. **Phase 18 is closed on evidence; the tree is still uncommitted, awaiting the owner.** (breakdown written 2026-11-05, voucher rows moved to Phase 20 the same day)** — 18 tasks in five groups executed strictly sequentially (`A → B → C → D → E`): A Blade interfaces + flag declaration/seeder + fixture render gate → B four tables/`member_tiers`,`members`,`member_subscriptions`,`member_point_histories` + Form Requests + Actions (subscription activate/cancel with the tier role synced onto the **`User`**, points ledger) + thin web/API controllers and the sidebar wiring → C `MemberSubscriptionExpirySweepJob` on `QueueName::SWEEPS` + chunked `SendMemberExpiryNotificationJob` + the seventh scheduler entry → D `members.view`/`members.manage`/`members.notify` on the route, `feature:member_loyalty`, four audit events into the Event Map → E lifecycle/sweep suites + the four gates. **The voucher engine left this phase on 2026-11-05 (owner): vouchers are their own domain — own flag `vouchers`, own menu, validated at payment time — shipped as Phase 20 rows A6/B6/B7**. **Seven brief-vs-code conflicts recorded as D-1..D-7 before any row is built** — flag-off answers **403, not the brief's 404** (settled in `EnsureFeatureIsEnabled`, pinned by 62 tests); **`members` is a loyalty profile keyed to `users.id`, not a second identity** (Spatie roles resolve on `User`); **no WhatsApp channel exists** (`NotificationChannel::for()` is mail + database — a driver needs a package + ADR, open decision O-2); **Group A cannot ship the sidebar entries** (a menu item must point at a route that exists → A4a in A, A4b with B6); **`lockForUpdate()` is inert on the SQLite test connection**, so the points-ledger test proves balance arithmetic while the row lock stays deferred runtime evidence needing a MySQL two-session probe (the voucher half of that deferred proof travelled to Phase 20 with the domain); a bulk POST needs its own permission (`AuthorizesBulkAction`); the new `Member` Action domain goes into the `naming-conventions.md` domains table. Not in scope: any spend/checkout path (Phase 20), i18n, new packages. Four owner decisions open (§ 8). Detail: `docs/planning/phase-18-membership-loyalty.md`. **Amended 2026-11-06 (roadmap resequencing): Group F — Membership Analytics Dashboard — added as P18-F1..F4 (PLANNED, no code written)**, six more brief-vs-code conflicts recorded against it as D-8..D-13 (the demographic card has no columns → O-5; `GetMembershipDashboardStatsAction` is verb-first; the expiring-soon table and its broadcast modal are already shipped, so F extends rather than rebuilds), and the member-facing `/dashboard` that shipped on 2026-11-06 moves from the ID it held to **P18-E3**. **Group F shipped 2026-11-06 into REVIEW**: `MemberAnalyticsStatsAction` + `MemberAnalyticsQueryRequest` + `MemberAnalyticsObserver` extend the A3 screen in place — no new route, no new permission, `broadcastExpiry()` untouched — growth-vs-expiration, tenure, renewal rate, DAU/MAU and the at-risk list all answered by conditional aggregates (one statement per series, bindings only), cached as primitives per timeframe key and busted `afterCommit`; the demographic card stays cut (O-5a) and the redundant *Expiring soon* counter card is gone, because Group F prints that number under the table it counts. Gates targeted: `MembershipDashboardTest` 12 passed / 124 assertions, `tests/Feature/Member` 131 / 734, `tests/Arch` + `ChannelParityTest` + `GateDUiGatingTest` 653 / 1006, Pint PASS 577 files, PHPStan `[OK] No errors` with **one new ledger entry** (the framework's `literal-string` requirement on an assembled select list — reason in the entry comment). **Measured** on 60 members: cold p95 11.5 ms, cached read 0.052 ms, against the brief's 100 ms. **Group F IN_PROGRESS 2026-11-08** — UI dropdown revision: timeframe filter refactored from GET preset links to Stockbit-style date-range picker; aggregation gates still green (`tests/Feature/Member` 131/734, Pint PASS, PHPStan clean). **Open**: dead preset code (`PRESETS`, `timeframes()`, preset cache keys), JS untested, visual QA pending. Phase 18 is therefore **A–E DONE, F IN_PROGRESS, uncommitted, owner moving to Phase 19** |
| 19 | General app dashboard & platform health metrics | **PLANNED (breakdown written 2026-11-06; no task started, no code written)** — 12 tasks in four groups (`A → B → C → D`): A dashboard page + timeframe filter partial + render gate → B `Dashboard\GeneralDashboardStatsAction` + a health/session read + a thin controller → C `dashboard.view`, the `general_dashboard` flag (declared **and** seeded, off → 403), and the read-audit decision → D feature tests, the four gates, tracker reconciliation. Split out of the POS phase on 2026-11-06 by owner decision; **member analytics stayed with membership as Phase 18 Group F**, so this phase reads `users` / `sessions` / `activities` / `failed_login_attempts` only and depends on Phase 17 alone. **Eight brief-vs-code conflicts recorded as D-1..D-8 before any row is built**, the load-bearing ones: **`dashboard.view` cannot sit on `/dashboard`** — that is the post-login landing route and the `user`/`member` roles hold no permission at all, so the gate goes on the overview route and `/dashboard` chooses content by caller (ADR-034; it shares that branch with the shipped P18-E3 member screen); **profile completeness has no fields to score** — `users` has no avatar / date of birth / address (O-1); **no chart library is installed and CSP blocks inline JS** (O-3, server-rendered bars are the default); **health, sessions and the security feed already have owners** (`/health`, `/sessions`, Pulse, `FailedLoginAttempt`), so B2 reads them rather than re-probing; **nothing in this app audits a read**, so `dashboard.viewed` is an owner decision carrying one write per landing (O-2); and `GetGeneralDashboardStatsAction` is verb-first against `ActionNamingConventionTest`. Detail: `docs/planning/phase-19-general-dashboard.md` |
| 20 | Basic POS engine & omnichannel payment gateway | **PLANNED (breakdown written 2026-11-05; no task started, no code written)** — 22 tasks in five groups, strictly sequential (`A → B → C → D → E`), hard predecessor Phase 18 (B2 spends the points Phase 18 deliberately left unspent; the **voucher domain moved here from Phase 18 on 2026-11-05** — owner decision: its own flag, menu and permissions, validated at payment time, shipped as rows A6/B6/B7): A cashier UI + gateway settings card + financial dashboard + the shipped voucher admin view (A6, REVIEW) + the three flags → B `products`/`pos_shifts`/`pos_transactions`/`pos_transaction_items`/`stock_movements`/`payment_logs`/`vouchers`/`voucher_usages` + `ProcessPosTransactionAction` (stock, change, points, voucher, audit in one transaction) + shift open/close + ESC/POS byte stream → C `CreatePaymentOrderAction` (native `Http::timeout`/`retry` + the one new class, `GatewayCircuitBreaker`) + `ProcessPaymentWebhookAction` (HMAC before any query, then an atomic conditional update) + encrypted-credential writer + the public webhook route → D seven permissions (incl. `vouchers.view`/`vouchers.manage`), flag enforcement, nine audit events → E lifecycle/race/financial suites + the four gates. **Thirteen brief-vs-code conflicts recorded as D-1..D-13 before any row is built** — the load-bearing ones: **no product/stock table exists anywhere in the schema**, so B1 must create `products` and how a product gets created is open decision O-1; **Livewire/Alpine are not installed and CSP+the stack forbid them**, so the cart is vanilla JS and the server recomputes every figure; **`Illuminate\Support\CircuitBreaker` is absent from this install** (retry/timeout are native) and a retry against a gateway is only safe with our own deterministic `order_id`; **the webhook shield cannot be `lockForUpdate()` alone** (inert on SQLite) — conditional update + unique `(gateway, event_id)` is the portable half; **gateway keys stay out of the settings whitelist and inside `SystemSetting::SECRET_KEYS`** with one writer, because `settings.manage` must not be able to repoint production money; **Net Profit has no cost data** (O-2); a void is a reversing row; **`mike42/escpos-php` needs ADR-031 before `composer require`** and only its byte stream is testable; flag-off is **403, not 404**; and **"100 % coverage" is not the enforced bar**. Deferred after closure: real printer hardware, a live gateway sandbox, the MySQL two-session probe. Five owner decisions open (§ 8). Detail: `docs/planning/phase-20-pos-and-payment.md` |

## Current Task

**Phase 18 Groups A–E closed by the E2 run (2026-11-06); Group F in progress — dropdown
UI revision landed 2026-11-08, gaps open. The Phase 18 tree is uncommitted, and the
Phase 17 branch still awaits merge. Owner moving to Phase 19.** The phase table above is the authority
for what is open: rows 0–2, 4–5 and 7–11 DONE (Phase 10 Audit Trail closed 2026-10-13, Phase 11
Monitoring/Observability closed 2026-10-16), row 12 is DONE — capability gap audit, the
five-group breakdown, and the post-review fixes, closed 2026-10-21; row 13 DONE (owner-closed
2026-10-27), row 14 DONE (owner-closed 2026-11-03, runtime verification deferred to real-world
use); rows 3 and 6 reconciled to DONE (2026-11-04, ledger-proved: 24/24 and 42/42 tasks DONE),
row 15 DONE (owner-closed 2026-11-04, merged PR #41),
row 16 IN PROGRESS (Groups A–D DONE, Group E in REVIEW, 2026-11-04), **row 18 DONE
(Group A–E closed 2026-11-06 by P18-E2: closure run 2923 tests / 11152 assertions / 9 risky
exit 0, four coverage bars held, three defects found by the run fixed with regression tests,
D-5 row-lock proof and the stale dev `permissions` table carried to § *Known Issues*;
Group F — the analytics dashboard added 2026-11-06 — **IN_PROGRESS 2026-11-08** (UI
dropdown revision: timeframe filter refactored from GET preset links to a Stockbit-style
date-range picker; aggregation tests green, JS untested, dead preset code noted as cleanup):
`tests/Feature/Member` 131 / 734, `tests/Arch` 625 / 904, four cross-cutting gates 43 / 230,
Pint PASS 577 files, PHPStan `[OK] No errors`, read time measured (cold p95 13.2 ms, cached
0.061 ms) — no full-suite run, owner rule)**, row 17 IN PROGRESS
(Groups A, B and C run and in REVIEW 2026-11-04 — 2665 passed / 9834 assertions exit 0, coverage bars
green off the same clover run, 137 UI render tests green, Pint/PHPStan/audits all exit 0 after the
owner-decided vite 8.3.5 lockfile bump, build + warm-cache boot green after fixing a non-serializable
config value that made `php artisan optimize` exit 1, six scheduler entries audited as a set; Group D
not run —
`docs/planning/phase-17-full-regression.md` § *As implemented*).
**Phase 15 Group A implemented 2026-11-04** — the render harness the breakdown
said did not exist: `tests/Feature/UI/` with 23 tests / 309 assertions over the
layout shell, the menu, the UI components, the four form pages that had no
render gate, and three source sweeps (no `bg-white`/`bg-light`, every footer on
`bg-body-tertiary`, every table in `.table-responsive`, every inline script
`@nonce`d). The sweeps are sabotage-verified. One real defect fell out: the
sessions table rendered with no `.table-responsive` wrapper. Owner-reviewed
2026-11-04; Group B implemented, owner-reviewed and committed the same day;
Group C (audit-and-close maps + gap tests) implemented, owner-reviewed and
committed the same day; Group D (PHPStan ledger, Pint full-tree gate, security
probes, weekly audit workflow) implemented, owner-reviewed and committed the
same day; Group E (query-count/chunk-straddle/memory guards, coverage
thresholds + `bin/coverage_gate.php`, trigger-split `testing.yml`) implemented
2026-11-04 and left in REVIEW — all gates green, work uncommitted for the owner.
**Phase 15 breakdown written 2026-11-04** — 17 tasks in five groups, all
`PLANNED` when written, no code changed. It records what the repository actually contains:
`tests/Feature/UI` does not exist yet, there is one policy (`UserPolicy`) and
one custom rule, no static analyser is installed, and CI runs Pint `--dirty`
with `coverage: none`. Groups C–D are therefore audit-and-close over existing
suites; the new work is the render harness, the unit layer, PHPStan level 8 and
the coverage gate. Detail: `docs/planning/phase-15-comprehensive-testing.md`.
**Phase 16 breakdown written 2026-11-04** — 14 tasks in five groups, all
`PLANNED` when written, no code changed: an audit-and-close phase that diffs
`docs/` against the shipped code (schemas, 50 Action names, the full setting-key
list, the permission catalog, every audit event, the runbooks) and pins what
must stay true (link integrity). It records what the repository actually
contains: no in-app Markdown renderer, `/docs/api` already owned by
`ApiDocsUiRenderTest`/`ApiDocumentationTest`/`OpenApiExportTest`, and the
brief's doc paths moved under `docs/base/`. Detail:
`docs/planning/phase-16-documentation-verification.md`.
**Phase 16 Group A DONE (owner-reviewed 2026-11-04), Group B implemented 2026-11-04 (REVIEW)** — A1 verified `/docs/api`
by running its three owner tests (green, 22/70) and diffing the exported
OpenAPI document against the contract (48 paths / 59 operations, no status
outside it); A2 scripted the `docs/` Markdown baseline and fixed the two real
defects it found (one moved-path broken link, one heading skip), recording the
46 %-compliant fence convention as house style rather than mass-reformatting;
A3 added nothing, because neither A1 finding was a render property and the
brief's new test file would have duplicated `ApiDocsUiRenderTest`. No PHP/JS
changed; docs edits only. Group B: B1 walked the exported document (every
response schema present, zero statuses outside the contract, all 8 resource
`@return` shapes hold, JR001 closed as cosmetic, 507 recorded as the web-only
exception — zero fixes); B2 corrected three drifts inside
`docs/base/architecture/naming-conventions.md` (the "every class" overclaim vs
its own `BulkActionProcessor` example, a dead `SystemUpdateSettingsAction`
example, a 6-row table for 9 domains) and cited the existing
`ActionNamingConventionTest` as the enforcement owner; B3 measured 75 validated
keys (66 settings + 9 mail), all documented, the `settings.md` block diffing
66↔66 exact both directions.

**Phase 16 Group C implemented 2026-11-04 (REVIEW)** — C1 diffed the 36-permission
catalog against the RBAC/flag docs and fixed the three stale spots (an invented
`roles.manage`, fake permission names in `dependencies/overview.md`, missing
`storage_management` + `api_docs.view` rows in `feature-flags.md`). C2 verified the
backup doc mechanism-by-mechanism and replaced its generic Restore Procedure with a
concrete operator runbook — zip layout read from the shipped `spatie/laravel-backup`
source, not invented (plain `db-dumps/mysql-<db>.sql`, absolute-minus-slash file
paths, ZipCrypto `unzip -P`); the restore round trip stays deferred and is stated
as deferred (P15-C4). `retention.md` was corrected where it promised mechanisms
that do not exist: reset tokens are 15 min (`PASSWORD_RESET_EXPIRE_MINUTES`),
revoked tokens are deleted on revocation, no failed-jobs or log prune ships, and
the `retention.*` config keys were relabelled design targets; audit-logs-never-pruned
is now cited to its enforcing null in `config/activitylog.php`. C3 re-derived the
limiter table from `RateLimiter::for()` — 15 registered, `system-backup` (3/hour
composite, on `POST /storage/backup` outside the `user-state-actions` group) was
undocumented — and corrected `web-security.md`'s "Policy-based" line to the
route-level pattern. `--filter=Security` 139/718 green; Pint clean; no production
code changed.
**Phase 13 breakdown written 2026-10-26** — 14 tasks in five groups (A headers/CSP,
B CSRF, C CORS, D input safety, E disclosure/limits). **Group A DONE** (implemented
2026-10-26, owner-reviewed 2026-10-27; `--filter=Security` 94 passed). **Group B
DONE** (implemented and owner-reviewed 2026-10-27): the CSRF middleware now replaces
the framework's `PreventRequestForgery` in the `web` group (appending had put `auth`
ahead of it), and the 419 branch matches the prepared `HttpException` so
`CSRF_TOKEN_MISMATCH` is reachable; `CsrfProtectionTest` 10 passed. **Group C DONE**
(implemented and owner-reviewed 2026-10-27): `config/cors.php` now exists, default-deny
and scoped to `api/*` with credentials derived from the origin list (D-6);
`CorsPolicyTest` 8 passed. **Group D DONE** (implemented and owner-reviewed
2026-10-27): audit-and-close, no production code changed — the sinks and sort/filter
whitelists already held, so `InputSanitizationTest` fills only the uncovered gaps
(6 tests / 31 assertions). **Group E DONE** (implemented and owner-reviewed 2026-10-27):
verification only — the HTML 500 masking branch and the composite throttle keys, with
`reset-password` and `email-verification` getting their first 429 probe;
`CompositeRateLimitKeyTest` 4 tests / 20 assertions; full suite 2241/8034.
**Phase 13 is complete; the branch awaits merge.**
Detail: `docs/planning/phase-13-security-hardening.md`.

**Phase 14 Group B is DONE (owner-accepted 2026-11-02)** on
`feature/phase-14-storage-backup-retention`, committed. Owner has not exercised
the backup flow by hand yet; runtime verification is deferred, the automated
suite is the current evidence. Group A remains REVIEW.
A1–A5 implemented: Storage Management card, `bindStorageConfig()`,
encrypted S3 credentials, `StorageCapacityGuard` → 507 `STORAGE_FULL` + hourly
`CleanTemporaryStorageJob` dispatch, signed `storage.secure` behind
`can:storage.view` + `feature:storage_management`, `StorageDiskTest` green.
B1–B3 implemented (ADR-026): `spatie/laravel-backup` → dedicated `backups`
disk, `SystemBackupSchedule` + settings `backup_enabled`/`backup_schedule`/
`backup_retention_daily|weekly|monthly`, `ExecuteSystemBackupJob` on the
`backups` queue **and connection** (retry window 2000s > the job's 1800s
timeout; `bin/run-workers.sh backups` is its worker), pre-flight size check →
`backup.aborted_storage_full` or `backup.triggered`, notifications to
`backup.execute`, `POST /storage/backup` trigger (throttled `system-backup`,
"Run Backup Now" on the settings card), `BackupStrategyTest` green (16 tests).
Audit follow-up 2026-11-01/02 also made the MySQL session zone follow
`app_timezone` (every connection, so a SQL client reads the same wall clock the
UI shows) and fixed the migration's `down()`. **Group C is DONE
(owner-accepted 2026-11-02, committed)**: `temp_file_retention_hours` (1–8760,
default 24) seeded, validated, whitelisted and exposed on the Storage card;
`CleanTemporaryStorageJob` scheduled daily on `sweeps` on top of the guard's
threshold dispatch; `DataRetentionTest` 8 tests / 24 assertions pins TTL purge,
the boundary, preserved audit rows and soft-deleted models, audit contents and
second-run idempotence. System-initiated audit rows now carry
`properties.causer = 'SYSTEM'` (scheduled backup, capacity guard, inactivity
lock, account lock, temp cleanup). Full suite 2338 passed / 8439 assertions,
Pint clean. **Group D is DONE (2026-11-02, uncommitted)**: an audit-and-close
pass — the permission catalog (`storage.view`, `storage.manage`,
`backup.execute`), route gates, `storage_management` flag and the five audit
events had already shipped with Groups A–C, so D added the missing acceptance
gate: `StorageAuthorizationTest` (7 tests) pins superadmin `Gate::before` on
both storage routes, a read-only settings viewer's 403, flag-off writes not
persisted and absent from the storage audit key list, disk binding + upload
guard surviving the flag, and the storage audit row discarded with its
transaction. It also derives `$storageChanged` after the feature-flag strip and
corrects the `PermissionCatalog` docblock to the D1 decision (`storage.manage`
has no route consumer yet). **Group E is DONE (2026-11-02, uncommitted)**: the
verification gate ran `--filter=Storage` (65 tests / 178 assertions) plus
`JobConventionTest` and `SettingsPersistenceTest` (12 tests / 107 assertions),
full suite 2346 passed / 8451 assertions, Pint clean. The doc pass confirmed
`retention.md`, `backup-disaster-recovery.md`, `queue.md`, `error-contract.md`,
`settings.md` and `audit-trail.md` against the code and corrected
`storage.md`: the `storage_management` flag sentence now names both Settings
cards and the backup trigger, and a new `## Permissions` table maps
`storage.view`, `storage.manage` and `backup.execute` to what each actually
gates.

**Phase 14 is closed (owner-closed 2026-11-03, committed on
`feature/phase-14-storage-backup-retention`).** After Groups A–F, a pentest
remediation pass shipped findings #1–#8 (2026-11-03): production refuses
plaintext archives and deletes the temp dir on failure, the secure route
serves downloads, guard coverage is mapped per disk, world-writable permission
modes are refused, S3 endpoint/url require https, the backup job is
single-flight by lock, the health probe's anonymous write is recorded as an
accepted residual, and `timezone:rebase` requires `--force` in production.
API/web capability sync was checked by the phase-12 method: settings
read/write and health status are synced through shared Request/Action/Service;
secure media, backup trigger and capacity metrics are web-only by documented
decision. Full suite **2366 passed / 8524 assertions**, Pint clean. What is
NOT yet evidence: a real `mysqldump`+restore round-trip, a real S3 bucket, a
live `timezone:rebase`, and the archive-encryption `queue:restart` flow — the
owner runs those against real infrastructure when the phase meets production.
Detail:
`docs/planning/phase-14-storage-backup-retention.md`.
`feature/phase-11-monitoring-observability` carries Phase 11 (Groups A–F, full suite 2078/7265,
Pint clean) — committed through the Vantage queue monitor
(MONITOR-003, added 2026-10-16, reversing the D-1 deferral), ADR-023, and the §15 security
pass (ADR-024: four findings fixed, docs re-synced, plus the same-day follow-up that made the
flag modal state the revoke), all committed 2026-10-19.
`feature/phase-10-audit-trail` is **merged into `main`** (PR #34, `61484e6`) — Phase 10 plus its
pentest remediation: nonce CSP, the four response headers, the re-verified audit report,
pint/import housekeeping. Its finding tracker closed 2026-10-15: 10/10 findings FIXED, with
`.env.example APP_DEBUG=true` skipped as an owner decision.

**Phase 9 — Notifications & Mail: DONE** (re-audited 2026-10-08)
The phase did not simply close on a green suite. Four passes ran after Groups A–E shipped, and each
found something the task tables could not see:

- **Code audit** — 7 findings, all closed. Four were boundary defects rather than mistakes inside
  the module: the SMTP ciphertext was readable through Phase 8's settings API to anyone holding
  `settings.view`; three actions dispatched notifications inside a transaction against
  `after_commit => false` queues; the bell's cached count was invalidated while its five-row list
  was not; and the global mail switch could break the four flows with no alternative channel
  (verification, email-change, account state, temporary password). The remaining three: no retention
  on the `notifications` table, no rate limit on send-test-mail, and a dead model class.
- **Copy rewrite** — every class repeated its own title in the body, and one `sprintf()` produced a
  broken sentence. Rewritten against researched guidance, with the rule that matters most here
  pinned: the subject must stand alone, because the bell dropdown shows the subject and nothing else.
- **Performance** — no N+1, nothing over 2.85 ms, warm bell cache at 0 queries, inbox bounded by
  pagination rather than by size.
- **Pentest** — 11 probes, 108 assertions, no findings.

Two things were left open after that pass rather than quietly fixed, and both were closed on request
in the same session: a permission granted directly to a person (rather than through a role) passed
`can()` but resolved to no notification audience, and the inbox page held the module's last uncached
read, so the bell and the inbox could report different unread counts for the same inbox. The audience
resolver now reads both grant paths, and both surfaces read one cached number.

Full detail: `docs/planning/phase-9-notifications-mail.md`.

---

## Previous Task

> Historical log, kept in the order it was current. Phases 7, 8 and 9 each closed with their own
> audit; their detail lives in their phase documents.

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
- Spatie ActivityLog ^4.8 installed AND integrated (Phase 10 done: one entry point,
  `App\Models\Concerns\Auditable`, and `Auditable::auditContext()` as the single
  place its context is derived — the package is behind the abstraction, not beside
  it)
- Laravel Pulse ^1.8 installed (config + migrations published), replacing
  Telescope ^5.0 and Periscope v0.3 in commit `85384b4`. The `pulse` feature
  flag gates /pulse through `pulse.middleware` (`b08b8b4`). The `pulse.view`
  permission + `viewPulse` gate override + sidebar entry shipped 2026-10-15
  (phase-11 Groups A–B), together with the `health.view` / `health` web
  dashboard (`GET /health` → `health.index`).
- Scramble ^0.13 installed
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
| Phase 4C Group C (P4-C1 through P4-C6) — Activate/Deactivate/Lock/Unlock: DONE ✅ (approved, closed) |

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
|- Deferred runtime evidence (carried into closure so it does not vanish —
  `docs/planning/final-release-summary.md` § 3): a real S3 bucket, the
  backup → restore round trip, and a live `timezone:rebase` have never been
  executed; 9 risky tests are accounted for by count only; the web
  email-verification link has no signature/expiry check (Phase 15 C design
  question); sensitive-data redaction in log *files* is a policy, not a
  mechanism (QA-LOG-003).
- The **dev MySQL database is stale on permissions** (found by P18-E2, 2026-11-06, not
  fixed because reseeding an environment is the owner's act): `permissions` holds 39 rows,
  still the original `members.view` / `members.manage` / `members.notify` triple, with none
  of the Phase 18 slugs and no tier rows at all. Consequence: the
  `2026_11_06_000000_rename_member_tier_permissions` migration matched zero rows here, so
  it was proven a different way — inside a rolled-back transaction with two planted rows
  (`up` renames both, `down` reverses, the count returns to 39). A `db:seed` of
  `PermissionSeeder` plus a `PermissionRegistrar` cache clear is what a dev box needs
  before the membership screens authorize.
- Deferred runtime evidence from Phase 18: the `lockForUpdate()` on a member's points
  balance (D-5) is inert on the SQLite test connection, so `MemberPointsTest` and
  `MemberApiTest::test_over_redemption_is_refused_before_any_ledger_row` prove the
  arithmetic and the refusal but not the lock itself — a MySQL two-session probe has
  never been run. The voucher half of that proof travels with the domain to
  Phase 20 § 7.
|- `qa-tracker.md` keeps 21 rows open after the P17-D2 reconciliation: 13
  UNPROVEN and 8 PARTIAL, each naming the assertion it still lacks; three
  (QA-AUDIT-004, QA-AUDIT-008, QA-MON-010) are `PARTIAL — needs a human`.
|- Delete-candidates recorded, not deleted (Phase 15 E2): the dead
  `password()` / `remember()` accessors on three auth Form Requests — 12
  statements.
|- D-8 open owner decision: the Vite build emits `app-*.css` (Tailwind) and the
  self-hosted font CSS, and no layout loads either, while `AGENTS.md` says "No
  Tailwind" — options recorded in
  `docs/base/ui/ui-adminlte-setup.md` § *Asset Pipeline — Vite*.
|- Findings filed by Group C, not fixed: `bin/fetch-page.sh`'s default
  credentials are stale (`LocalTest#2026` vs the seeded `#Password123`), and the
  local dev DB reports a Pending migration
  (`2026_11_03_000000_remove_duplicate_expiry_settings`) the phase deliberately
  did not run.

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
- ADR-022: Vantage queue monitor, gated like every other module
- ADR-023: the ungated health endpoint gets a ceiling and a cached check set
- ADR-024: the flag reaches the writes, and the permission follows the flag
  (Phase 11 security pass)

## Test Status

**2923 tests / 11152 assertions, 9 risky, 0 failed** (2026-11-06, `feature/phase-18-membership-loyalty`, P18-E2 closure run: `XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml`, **exit 0**, Time 17:56.503, Memory 325.50 MB). **Phase 18 closes on this run.** It took three runs to get here: the first two were `php artisan test --coverage` at 512M and died at the report step (`Allowed memory size … exhausted` in `php-code-coverage/src/Report/PHP.php`), because `artisan test` did not pick up `-d memory_limit=2G` — running PHPUnit directly did. `build/coverage/clover.xml` is now this tree's, not the 2026-11-04 file.

Coverage measured on **this** clover, all four bars held: global **94.31 %** (6154/6525) over the `--min=85` bar, and `php bin/coverage_gate.php build/coverage/clover.xml` exits 0 with **Actions 96.62 %** (2170/2246, bar 95), **Policies 100 %** (31/31), **Requests 98.10 %** (567/578, bar 95). Actions reads lower than Phase 17's 97.11 % because the module grew the denominator (1593 → 2246 statements), not because a gate lost coverage.

The run's first green-looking pass was red, and the three failures were Phase 18's own — recorded in `changelog.md` and `qa-tracker.md` § *Membership QA*, fixed, none by weakening an assertion:

- `PermissionCatalogTest::test_every_permission_is_a_resource_dot_action_name` — `members.tiers.*` and its seven siblings were the only two-dot slugs in the catalogue. Owner chose the rename: **`member_tiers.*`** (one dot, table-shaped), with a `FEATURE_BY_RESOURCE` entry mapping the new prefix to the same `member_loyalty` flag, `PermissionSeedTest`'s group list, every route/FormRequest/view/menu reference, and a data migration for existing `permissions` rows. Route *names* (`members.tiers.*`, `api.v1.members.tiers.*`) deliberately stayed.
- `FeatureFlagUiRenderTest::test_a_viewer_without_manage_gets_badges_and_no_switch` and `NotificationUiRenderTest::test_a_viewer_without_manage_gets_no_channel_switches` — both assert a read-only page contains no switch markup, and both found one. Root cause: the member-deactivation `violation` block had been hardcoded into `layouts/partials/modals/confirmation.blade.php`, which `layouts/app.blade.php` `@include`s, so **every authenticated page carried a membership control**. Owner chose the move: the block is now `@push('confirmModalFields')` on the member list and `@stack`ed by the partial. `MemberWebTest::test_the_deactivation_fields_ship_only_on_the_member_list()` pins it (present on `/members`, absent on `/users` and `/features`).

Fast gates re-run on the final tree: `vendor/bin/pint --test` **exit 0, PASS 571 files**; `vendor/bin/phpstan analyse --memory-limit=1G` **`[OK] No errors`**, ledger **310 entries** (unchanged, `reportUnmatchedIgnoredErrors` on); `route:list --path=member` **47 routes** (25 web, matching `membership.md` § Routes row for row); `schedule:list` **eight entries**, `member-subscription-expiry` at 00:30 staggered off `member-points-expiry` at midnight; `npm run build` **exit 0**; `tests/Arch` **616 passed / 888 assertions** (re-run after the doc edits, since the Arch suite is the docs-link gate). P18-E1's targeted gate: `tests/Feature/Member tests/Feature/Api/V1/Member` **136 passed / 698 assertions**, and the points-ledger guard is sabotage-verified — disabling `if ($after < 0)` in `MemberAdjustPointsAction` turns `test_deducting_more_than_the_balance_is_refused` red, restoring it turns it green.

Previous live figure: **2668 passed / 9837 assertions, 9 risky** (2026-11-04, `feature/phase-17-full-regression` — the live figure, re-measured after P17-C2 changed code: `php artisan test` **exit 0**, Duration 498.58 s. The +3 tests / +3 assertions over the P17-A1 figure are attributed by a per-class diff of the two run logs: `Tests\Arch\SerializableConfigTest` 0 → 1 (the new config-serialisability gate) and `Tests\Arch\NoInlineFqnTest` 424 → 426 (one case per PHP file under `app/` + `tests/`, and C2 added two files) — nothing else moved and no count fell. The coverage-bearing run is P17-A1/A2 on the tree before that change, quoted from its own output: `XDEBUG_MODE=coverage php artisan test --coverage --min=85 --coverage-clover=build/coverage/clover.xml`, **exit 0**, Duration 763.70 s. The counts are identical to the Phase 16 Group E figure (2665/9834): a run-and-record phase changed no code, so no suite movement is expected and none happened; previous figures 2661/9830 in Phase 15 Group E, 2649/9798 after Group D, 2644/9778 after Group C, 2394/8832 after Group A). Coverage measured on **that** clover file, not carried: global **94.87 %** (5031/5303) over the `--min=85` bar, and `php bin/coverage_gate.php build/coverage/clover.xml` exits 0 with **Actions 97.11 %** (1547/1593, bar 95), **Policies 100 %** (31/31), **Requests 98.09 %** (461/470, bar 95) — each unchanged from P15-E2, which is what a docs-only interval should produce. The local driver is xdebug (CI runs pcov on the scheduled + release coverage job), so the 12-minute wall clock is the cost of the measurement, not a defect. UI render owners re-run green (P17-A3): `tests/Feature/UI/` 23 tests (12 + 7 + 4) plus the seven page render suites, 114 tests (`SettingsUiRenderTest` 14 · `RbacUiRenderTest` 20 · `NotificationUiRenderTest` 19 · `AuditLogUiRenderTest` 27 · `FeatureFlagUiRenderTest` 14 · `HealthUiRenderTest` 11 · `ApiDocsUiRenderTest` 9); which pages carry a query-count gate and which do not is listed in `docs/base/testing/testing-matrix.md` § *UI render & view-query coverage*. `vendor/bin/pint --test` was re-run on this tree (P17-B1, 2026-11-04): **exit 0, PASS 487 files**,
nothing reformatted and no ignore added. `vendor/bin/phpstan analyse --memory-limit=1G` likewise
exits 0 — **`[OK] No errors`, 208 files at level 8**, with the ADR-030 ledger measured at **310
entries** (identical to P15-D1: no debt grew and none was silently paid, and
`reportUnmatchedIgnoredErrors` would fail a stale entry). Dependency audits read at the release
tree (P17-B3, not on CI's schedule): `composer audit --locked` exit 0 — no advisories;
`npm audit --audit-level=high` **exit 1 on the first read** — `vite 8.3.0 - 8.3.2`, six high
advisories (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm, GHSA-9jrq-w75r-8gcw,
GHSA-rq7h-c2jc-7f22, GHSA-vfpm-58rq-9qcg). Risk class: **dev-only** — the repo has no runtime npm
dependencies and every issue is dev-server / `launch-editor` behaviour that cannot exist in a built
asset — but the step is inside the `test` job and `deploy` is `needs: test`, so it blocked release.
Owner decision **upgrade**: `vite 8.3.0 → 8.3.5` in `package-lock.json` only (`package.json`'s
`^8.0.0` already allowed it), verified by `npm ci` exit 0 → `npm run build` exit 0 → `npm audit
--audit-level=high` **exit 0, found 0 vulnerabilities**; recorded in `docs/planning/changelog.md`.
Group C ran the build and the boot: `npm ci` **exit 0** → `npm run build` **exit 0** (vite 8.3.5, `✓ 9
modules`, 4.12 s; `app-*.css` **49.62 kB** gzip 9.29, `app-*.js` **15.00 kB** gzip 4.62, `fonts-*.css`
2.35 kB + 6 font files, manifest 9 entries). D-8 is measured, not assumed: all three layouts `@vite`
only `resources/js/app.js`, no manifest entry carries a `css` array, `Paginator::useBootstrap()` means
Laravel's Tailwind-classed pagination views never render, and `@viteFonts()` appears nowhere — so the
built stylesheet **and** the self-hosted font CSS are dead bytes and `--lbp-font: 'Instrument Sans'`
resolves to `system-ui` today. Nothing was deleted; the choice (drop the CSS input + the two
Tailwind devDependencies, or load it and amend `AGENTS.md` by ADR) is the owner's.
`php artisan optimize` then **exited 1** — `config:cache` serialises with `eval(var_export())` and
rejected `scramble.security_strategy.1.scheme`, a live `SecurityScheme` object in config: the
documented deploy steps 6–8 were unrunnable on this tree. Fixed in-group (new
`App\Support\OpenApi\BearerAuthSecurityStrategy` supplies the scheme as a constructor default,
`config/scramble.php` is scalar, new `tests/Arch/SerializableConfigTest.php` gates any object config
leaf without `__set_state`, sabotage-verified), re-run **exit 0** (config 44.78 ms · events 1.92 ms ·
routes 67.70 ms · views 1 s, all DONE), and **16 pages returned 200** with caches warm from one login
session. DB-backed mutability holds under a cached config: Pennant `notifications` OFF → inbox **403**
→ ON → **200**; `api_docs_public` OFF → guest `/docs/api` **302** → ON → **200** → restored. `env()`
outside `config/` re-scanned at zero live calls; `optimize:clear` **exit 0** restored the dev state.
`schedule:list` **exit 0** with exactly the six `routes/console.php` entries; the 00:00 collision is
safe because the two every-minute closures dispatch to different queues (`sweeps` for
`PasswordExpirySweep`/`InactivityLockSweep`, `backups` for `ExecuteSystemBackupJob`) and
`bin/run-workers.sh` listens on both, while the stored defaults are not even the same minute
(sweeps `00:00`, backup `02:00`, `backup_enabled` false locally). Post-C2 code-change gates: Pint
**PASS 489 files** (487 + the two new), PHPStan **exit 0** at 209 files with the ledger still at
**310 entries**. Two findings filed, not fixed: `bin/fetch-page.sh`'s default credentials are stale
(`LocalTest#2026` vs the seeded `#Password123`), and the local dev DB reports a **Pending** migration
(`2026_11_03_000000_remove_duplicate_expiry_settings`) this phase deliberately did not run.
actionlint (`rhysd/actionlint:1.7.12`, the pin CI runs) was last clean on both workflow files in the
P16-E2 run — not re-run here. PHPUnit 12, `test_` prefix, SQLite
`:memory:`. The full run reports **9 risky** cases (all pre-existing `tests/Feature` skips,
incomplete-marked benchmarks and one warning — the Phase 15 B/C/E work added none; the P17-A1 run
reports the same **9 risky** — matched by count, since that run's printer emitted the summary line
only and no per-test risky detail; attribution belongs to P15-E1). This is the LIVE
figure; the counts inside the phase rows above are dated gate measurements, deliberately left at
what each gate actually saw.

The suite is grouped by domain (19 folders under `tests/Feature/`; the only root-level
files are the two Pulse gate tests, `PulsePermissionGateTest` and `PulseQueryCacheTest`, which
predate the grouping and are the one standing exception to it) and governed by
one architectural gate that was added after the grouping exposed how far the convention had drifted:
**no inline FQCN anywhere** — enforced by `tests/Arch/NoInlineFqnTest.php`, which token-scans all 400
PHP files in `app/` and `tests/` (one data set per file, so a violation names its own file). See `docs/base/architecture/naming-conventions.md`.

| Area | File | Tests |
|---|---|---|
| UI render gate (Phase 15 A) | `BladeComponentRenderTest` 12 · `FormViewRenderTest` 7 · `ResponsiveUiTest` 4 | 23 |
| Domain unit layer (Phase 15 B) | `tests/Unit/{Actions,Requests,Rules,Policies,Support}` | 236 |
| Static analysis & security scan (Phase 15 D) | `SecurityAuditTest` +3 · `ApiErrorEnvelopeTest` +1 (507) · `RebaseTimezonesCommandTest` +1 (plus the pinned refusal message) · `phpstan analyse` 310-entry ledger, sabotage-verified · `testing.yml` weekly audit | — |
| Notifications — module | `NotificationUiRenderTest` 19 · `NotificationAccessTest` 14 · `NotificationSettingsTest` 20 · `NotificationInboxTest` 26 | 79 |
| Notifications — rules | `NotificationCopyTest` 5 × 27 cases · `AccountStateNotificationTest` 8 · `AdministrativeEventDispatchTest` 10 | 23 |
| Notifications — measurement & attack | `NotificationBenchmarkTest` 8 · `NotificationPentestTest` 11 | 19 |
| Phase 8 spillover | `SettingsPentestTest` 15 (incl. the SMTP-credential disclosure probe) | 15 |

Phases 15 and 16 are DONE (15 owner-closed as PR #41; 16's Group E closed
2026-11-05 by P17-D2 on Phase 17's logs), and Phase 17 ran every gate once on the
final tree. The suite is large but not exhaustive per surface, and the remainder is
named rather than averaged away: 21 open rows in `docs/planning/qa-tracker.md`
(13 UNPROVEN, 8 PARTIAL) and the pages without a query-count gate in
`docs/base/testing/testing-matrix.md` § *UI render & view-query coverage*.
Closure document: `docs/planning/final-release-summary.md`.

## Documentation Status

| Area | Status |
|------|--------|
| Architecture | Verified against code 2026-11-04 (Phase 16, naming-conventions.md reconciled to the 54 Action classes; docs tree gate `DocumentationLinkIntegrityTest`). Path note honoured (Phase 16 D-2 / P17 D-9): there is no `docs/base/architecture.md` — it lives at `docs/base/architecture/architecture.md`, and briefs citing the root path are read against that file |
| Dependencies | Verified against code 2026-11-04 (Phase 16, `dependencies/overview.md` invented permission names removed against `PermissionCatalog`) |
| Security | Verified against code 2026-11-04 (Phase 16, limiter table re-derived from `RateLimiter::for()` (15 registered), headers/CSP/CSRF/CORS claims cite their suites) |
| API | Verified against code 2026-11-04 (Phase 16, every exported response carries a schema (48 paths/59 ops walked), statuses all inside the contract) |
| Data | Verified against code 2026-11-04 (Phase 16, 75 validated `SystemSetting` keys enumerated from the requests, `settings.md` block diffs 66-66 exact) |
| Infrastructure | Verified against code 2026-11-04 (Phase 16, backup doc + restore runbook verified against shipped vendor source; retention rows corrected to shipped mechanisms) |
| Features | Verified against code 2026-11-04 (Phase 16, RBAC/flag docs diffed against the catalogs, 48-event audit map built from `->audit()` call sites) |
| Testing | Verified against code 2026-11-04 (Phase 17: bars in `bin/coverage_gate.php` — Actions 95 / Policies 100 / Requests 95, global `--min=85` — match `testing-strategy.md` and `definition-of-done.md` exactly, so the docs quote the enforced numbers, not aspiration; measured off one clover run: global 94.87 %, Actions 97.11 %, Policies 100 %, Requests 98.09 %; PHPStan level 8 exit 0 with a 310-entry ledger; Pint PASS 489 files; per-page render/query-count coverage and its named gaps in `testing-matrix.md` § *UI render & view-query coverage*; Phase 17 added one gate — `tests/Arch/SerializableConfigTest.php`) |
| Operations | Verified against code 2026-11-04 (Phase 16, runbooks walked cold, 4 defects fixed, every command verified to resolve) |
| UI | Verified against code 2026-11-04 (Phase 17: design-system sweeps re-run green, 137 render tests across `tests/Feature/UI/` + the seven page suites; **one doc-vs-config conflict named, not deleted** — `AGENTS.md` says "No Tailwind", `docs/base/ui/ui-adminlte-setup.md` claimed Vite compiles "JS + Tailwind CSS", and P17-C1 measured that no layout loads the built stylesheet (`@vite` passes only the JS in all three layouts, `@viteFonts()` never used, `Paginator::useBootstrap()` kills the Tailwind pagination path). `ui-adminlte-setup.md` now carries the conflict note and the two options; D-8 stays an owner decision) |
| Planning | Verified against code 2026-11-04 (Phase 16 reconciled the trackers to the task ledger; Phase 17 D2 then reconciled `qa-tracker.md` **row by row** — 55 unreconciled scenario rows now read 36 DONE / 5 PARTIAL / 13 UNPROVEN / 1 N/A, whole file 77/8/13/1 of 99, every DONE citing an assertion that was opened and read — and closed the four planning surfaces on the run logs, with `docs/planning/final-release-summary.md` as the closure document) |

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