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
| 9 | Notification/mail/queue | **DONE** — Groups A–E complete, every task verified, plus three post-ship passes (code audit, copy rewrite, performance, security). Mail transport in `system_settings` with `.env` as fallback, `mail_password` encrypted and never sent to a view; `bindMailConfig()` rebinds `config('mail')` after commit; test-mail probe; Web + API. In-app engine on Laravel's own primitives: `make:notifications-table`, `/notifications/inbox` (flag + auth, **no permission**) with mark-read and mark-all-read scoped through the viewer's relation, a cached bell badge invalidated on delivery and on mark-read, and `NotificationAudience` resolving the Target Audience Rule through the permission that performs each change. **Five corrections to the brief, each caught by a test rather than by reading:** the unread count cannot live on the `layouts.app` composer (`@include` shares scope, so a child renders first); the audience map must use per-action permissions because `users.manage`/`roles.manage` do not exist and an undeclared permission routes an event to nobody; `config/mail.php` has `scheme`, not `encryption`, so `none` is stored as empty and binds as null; `bindMailConfig()` belongs after the commit, not at boot; and the bell's count is cached because reading it live costs a query on every authenticated page. **D6 closed too** — `NotificationUiRenderTest` no longer fakes permissions through a `Gate::before` override; it builds four real Spatie roles. That took three things a role swap is not: `syncRoles` rather than `assignRole` (Spatie's adds, so narrowing a test's permissions would not narrow them), a `PermissionRegistrar` forget per swap, and clearing `User::can()`'s own `canMemo` — a **separate** cache from Spatie's, and forgetting only the registrar leaves `can('…manage')` answering true for a user who no longer holds it, which makes the test pass for the wrong reason. Verified by removing the memo clear and watching the send-test gate go red. Two bugs found and fixed: a null config default passed to `SystemSetting::getString(string, string)` raised a TypeError the catch swallowed, binding **no** key at all — save returned 200, row written, transport unchanged; and a rename script added a `User` type hint to a class that never had one, 500-ing every email-change path. **Re-audited 2026-10-08 — seven findings, all closed:** the SMTP ciphertext was reachable through the Phase 8 settings module (`GET /api/v1.settings`, `settings.view` is a wider audience than `notifications.view`) — now redacted in `SystemSetting::getAll()`; three actions dispatched notifications INSIDE a transaction against `after_commit => false` queues — moved out, and every notification now declares `$afterCommit = true` because that alone does not survive a caller that owns the transaction; the bell's count was invalidated while its five-row list was not, so badge and dropdown disagreed for five minutes; the `notifications` table had no retention at all (daily sweep, read rows only); send-test-mail had no rate limit; `app/Models/Notification.php` was dead code; and the global mail switch could break the four flows it has no alternative to — verification, email-change, account state, temporary password — which now bypass it via `NotificationChannel::for(essential: true)`. **Copy rewritten** on researched guidance: every class repeated its own title in the body ("Role was updated" over "Role was updated by Ana Silva."), and `sprintf('Your roles%s were changed.', $by)` produced a broken sentence; subjects now stand alone because the bell shows subjects only, and `NotificationCopyTest` pins that across all seven classes and both audiences. **Performance measured:** no N+1, nothing over 2.85 ms, warm bell cache costs 0 queries, inbox is bounded by pagination not by size, audience resolution is 2 queries at 1 or 21 administrators. **Pentest: 11 probes / 108 assertions, no findings** — authorization at four privilege levels on both surfaces, inbox isolation four ways, the credential through four read paths, eight configuration-injection payloads, five channel-switch shapes, relay abuse, stored XSS, and flag enforcement. Tests: **1590 passed / 5396 assertions**, Pint clean. See `phase-9-notifications-mail.md` |
| 10 | Audit Trail | **DONE — Groups A–F closed 2026-10-13.** The module that started as a write path is now a read path on **both** channels. Write: one entry point (`App\Models\Concerns\Auditable`) called from Actions, jobs and two services — zero controllers, zero observers, with `Auditable::auditContext()` as the single place `source`/`ip`/`user_agent`/`request_id` is derived, and **null outside a request** rather than `127.0.0.1` (the console Request's default, which had a scheduler's row claiming a browser pressed a button). Read: a filtered, allowlisted, paginated viewer on web AND API, with `audit.view` and `audit.export` kept separate — reading the trail is not the same act as extracting it. Export: a job that assembles the CSV in `php://temp` and hands `writeStream()` one complete file, on the private disk, reclaimed by a nightly sweep and by `failed()` on the path it can **compute**. **Three defects were found in review rather than shipped:** the success flash was rendered by no admin view (`success` where the house key is `status`), the rate limit was a literal beside a hardcoded tooltip quoting the same number, and raising `$tries` 1→3 made a retry orphan a complete CSV past its own retention window. **Pentest: `AuditPentestTest`, 63 probes** — four privilege levels (superadmin / admin / user / **no role at all**, which takes a different path through `Gate::before()`), both surfaces, and injection split by how each field reaches the query: `sort`/`direction` reach `orderBy()` as an *identifier* and must be refused; `event`/`source`/`subject_type` reach `where()` as a bound *value* and are correctly not. **A fifth defect, found by a fresh pentest pass on 2026-10-14 that probed the FILE rather than the page** (`CsvInjectionPentestTest`, 4 probes): the export handed a spreadsheet a formula. The `actor` and `target` columns carry `users.name`, and `name` is free text on `PUT /profile` — `strip_tags()` drops markup, not a leading `=` — so any authenticated account could set its display name to `=cmd|'/c calc'!A0`, act, and have that string sitting in the extract the incident responder opens on their own workstation. `fputcsv()` is no defence; it quotes a comma or a quote, and neither is what makes a cell a formula. Every cell now passes through `GenerateAuditExport::cell()`, which prefixes `'` when the first non-whitespace character is `= + - @` (CWE-1236) — measured +0.0115 ms/row against 0.245, so ~4.7%. It survived every earlier review because **the web viewer escapes the same payload correctly**, so the defect was in the file and not on screen: nothing in the UI looked wrong. The EMAIL fallback in `causerLabelFor()` was investigated and is not a second vector — the `email` rule requires `local@domain`. **A fourth defect, found by a performance pass rather than a test:** the export ordered `created_at DESC, id DESC` and chunked with `chunkById`, which cursors on `id` — the sort key and the cursor disagreed, so on 1200 rows the file held **999 lines of which only 500 were distinct** (200 rows dropped, 499 duplicated). Now ordered by `id` alone; `$sort` removed from the job rather than left dead. The existing query-count test was structurally blind to it — it measures 3 and 40 rows, both inside one 500-row chunk. **Then two performance fixes from a measured pass.** The export was **99.5% PHP** (12.4ms of SQL across 2466ms at 5000 rows), and the cause was `row()` reading `properties` four times per row: the `'collection'` cast is NOT memoisable (every read is a fresh `json_decode`, warm 16.3µs vs cold 15.1µs), so it is now read once into a local — build cost −31%, output byte-identical. And the four filter dropdown option lists are cached (TTL 3600, busted by `Auditable::audit()`/`auditBulk()`) because `sourceOptions()` is `DISTINCT json_extract(properties, …)`, the one filter **no index can serve on any engine**. That cache does not contradict the "no cache" note in `AuditIndexAction` — that argues about the per-viewer ROW list; option lists are global and identical for everyone. Interleaved A/B: option lists −90%/−96% (2k/20k rows), index page −19%, queries 10 → 6. Full suite **1924 passed / 6482 assertions**. See `phase-10-audit-trail.md`, `phase-12-api-v1.md` |
| 11 | Monitoring/observability | **DONE — Groups A–F closed 2026-10-16** (C2 deferred by decision, D N/A by design). Pulse `^1.8` and the system health dashboard both sit behind the same two independent gates — `feature:pulse`/`feature:health` (deploy-time kill switch) + `pulse.view`/`health.view` (per-role), both failing 403 so a caller cannot tell which fired; superadmin comes through `Gate::before`, `admin` inherits via `PermissionCatalog::all()`. `viewPulse` overrides Pulse's own `environment('local')` definition (overwrite-by-name, our provider boots later — measured, F1 in the breakdown). The web `/health` page and the ungated `GET /api/v1/health` twin answer the same `HealthCheckService` at two authorization levels: the API stays open because its caller is an uptime monitor, and its response shape is the ceiling on what it may ever expose — still ungated after **ADR-023**, which bounded its *cost* rather than its audience: `throttle:health` at `health_rate_limit_per_minute` (120/min per IP) and the check set cached for 10s. **Group E** made the queue conventions enforceable instead of habitual: `QueueName` (`exports`/`sweeps`, set with `onQueue()` in the constructor — a `public $queue` property is a class-load fatal against `Queueable` that `php -l` misses), `$tries`/`$timeout`/`$maxExceptions`/`backoff()` on every job, dispatch-time `request_id` in every payload (`Queue::createPayloadUsing` → `Jobs\Concerns\ReadsCorrelationId` in `failed()`), worker listening `default,exports,sweeps`, and `JobConventionTest` asserting all of it by reflection so a fourth job is covered the day it is written. Full suite **2025 passed / 6987 assertions**, Pint clean. Ten gate cases, not eight: the fifth per route pins D-3 — superadmin respects the flag, because `Gate::before` answers `can:` and `EnsureFeatureIsEnabled` never consults the actor. Horizon evaluated out (Redis-only). **Vantage (MONITOR-003) reversed the D-1 deferral on 2026-10-16**: `storviaio/vantage` `^0.1.25` is the queue monitor, behind the same two gates as Pulse and health — `feature:vantage` kill switch + `queues.view` per-role (`queues.retry` for the retry POST only). The package's provider calls `loadRoutesFrom()` bare, so the app declares the six `vantage.*` web routes itself (`config/vantage.php` → `routes => false`) to get the middleware, and overrides the package's `viewVantage` gate (whose default admits any authenticated user) as a safety net. `store_payload => false` — the package default `true` persists every job payload. `vantage:prune` runs daily on the scheduler behind `config('vantage.enabled')`. Pinned by `VantagePermissionGateTest` (7 cases), plus the two channel-parity / catalogue guards updated for `queues.*` and the six routes. **Follow-up closed 2026-10-17 (recorded here earlier as "reported not fixed"):** `AddSecurityHeaders::policy()` is scoped to `vantage.*` like `pulse` — the two CDN origins and `'unsafe-eval'` travel only with the Vantage routes, and the four script-bearing vendor views are overridden to nonce them; pinned by `ContentSecurityPolicyTest`. See `phase-11-monitoring-observability.md` |
| 12 | API V1 | **DONE (owner-closed 2026-10-21)** — breakdown has 27 tasks in five groups (A docs interface → B infrastructure → C routes → D security → E tests); A is implemented and committed on `feature/p12-a-api-docs`. Docs were a live route with no gate: `RestrictedDocsAccess` consults `Gate::allows('viewApiDocs')` and nothing defined it, so `/docs/api` 403'd for everyone outside `local` — now `api_docs.view` + `AuthServiceProvider::configureApiDocsGate()`, nullable user so a guest is redirected to login (or `401` on the JSON document) rather than 500. Visibility is operator-controlled: the `api_docs_public` setting (off by default, on `/settings`, audited) ORs into the same gate, so the *API Documentation* sidebar entry and the route cannot disagree; Scramble's own middleware is replaced because its `environment('local')` bypass would ignore the setting. Version is `v1` from `API_VERSION`, title follows `APP_NAME`, try-it credentials are `same-origin` (the default `include` would have attached this origin's cookies to a host typed in the panel). Scramble is a production requirement, not `require-dev`: `/docs/api` is a live route and the generated document is served outside `local` too. **The breakdown is audit-and-close, not greenfield:** ~70 routes, the `Resources/Api/V1` transformers and the whole error envelope already ship. Six brief-vs-code conflicts recorded as D-1..D-6, the load-bearing one being **flags return 403, not 404** — Phase 7's decision, pinned by tests, not to be "fixed" into a bug. **Group B (committed 2026-10-20 in four layer commits)** — audit-and-close, and it found a live defect rather than a stale table: the handler's blanket `HttpException 4xx` branch sat **above** `NotFoundHttpException` and `ThrottleRequestsException`, so every route-level 404 and framework-level 429 answered `HTTP_ERROR` while `NOT_FOUND` and `RATE_LIMITED` were documented and unreachable. Fixed by ordering, pinned by `ApiErrorEnvelopeTest`. `meta.request_id`/`timestamp` now ride every handler error — including the `LAST_SUPERADMIN` 409 and CSRF 419 emitted ahead of the `api/*` block — through one `App\Support\ApiError` that `Controller::respond()` also uses. The `! app.debug` gate is gone: the envelope is unconditional and debug only **adds** `debug.exception`, because a body whose keys depend on an env var is not a contract. Eight resources carry explicit `@return array{…}` shapes, `SessionResource` replaced a token map living in `SessionController`, and four hand-built success envelopes collapsed into `respond()` (`UserStateController` had a private copy byte-for-byte identical to it). Doc side: the contract was wrong, not the code — no `Link` header, no top-level `meta.pagination`; a collection is `data.<plural>` plus `data.pagination` with four keys, `per_page` defaults 10, and the inbox is fixed at 10, and the real query-parameter names are not `filter[field]`. `CONFLICT` dropped (nothing emits it), 503 re-attributed to the health probe, which answers with its own unwrapped body. No `SystemSettingResource`/`FeatureResource`: those controllers return a key→value map and an action result, so a transformer would be a passthrough **Group C (2026-10-20)** — routes and controllers verified module by module, and the one real defect was where the breakdown suspected it. `POST /api/v1/auth/unlock` was a redirect closure reading `request()->route('user')` on a route with no `{user}`, so URL generation threw: a probe test measured `500 SERVER_ERROR` for every caller, and the comment beside it was wrong twice (Scramble *did* document the closure, as a 200 with no body, and a redirect cannot be a compat layer when the id lives in the address it redirects to). Removed, not repaired — v1 never shipped — and `UnlockShimTest` pins the 404 envelope plus the absent route name. The `respond($message, 4xx)` refusals the error contract deferred to this group are all migrated: six sites, `data.message` gone from every error body. Login 401 `UNAUTHENTICATED` / 403 `FORBIDDEN` / 429 `RATE_LIMITED`+`retry_after_seconds`; register 404 `NOT_FOUND` (camouflage message kept, code not lied); resend 403/422 `EMAIL_ALREADY_VERIFIED`/429/500, carried as `error_code` on the action payload so the controller translates nothing; verify-change 400 `INVALID_TOKEN`; test-mail 502 `BAD_GATEWAY`. Annotations went where Scramble could not infer: `#[Response(409)]` on the three reachable `LastSuperadminException` routes (delete, deactivate, bulk — force-delete has no guard, only a trashed row is accepted), audit download 404/**410** and retry 404/409 (three refusals share the 409; the body says which), and the settings methods now state the flat key→value map and that `mail_password` is **absent** rather than null. `permissions.index` was the one controller outside the FormRequest rule (`$request->query()`), so its allowlist was invisible to the document and an unknown `sort` fell back silently instead of 422 — it now takes the existing `PermissionQueryRequest`. **C5 closed the gap** after the analysis said it could not be closed by reuse: `FeatureIndexAction` is a view model (`toggle_url` from the *web* route name, viewer-scoped `manageable`, per-flag `permissions`), so the new `GET /api/v1/features` (`features.view`, no `feature:*`) serves its rows **projected** to six fields — slug/label/group/description/enabled/pending — with the projection pinned by test so the page model cannot leak back. No new resolver, no `FeatureResource`: the action already owns the single `activeMap()` read and the snapshot. Coherence is pinned by a toggle-then-read test, so a writer that stops flushing `SNAPSHOT_KEY` fails instead of serving a 30-second-old state. **C8 verified structurally:** zero `DB::transaction`/Eloquent mutations in `Api/V1`, and all 45 Actions imported by an API controller are also imported by a web controller. **Group D (2026-10-20)** — the security surface was audited rather than rebuilt: one real `can:` gap closed (`POST /features/bulk-action` carried no route gate on either channel while its comment claimed an action-dependent permission; both flag actions are `features.manage`), token abilities kept `['*']` with the reason written down (a token is a session in another transport; authority is RBAC re-read per request), the API rate ladder documented (no `api` limiter — the protected group's 15/min is tighter), the audit row's `request_id` now pinned to the response's `X-Request-ID`, and users/roles response parity pinned with the API-only keys as an exact declared list. **Group E (2026-10-20)** — the four per-module API files own the sibling-permission gate in both directions (roles view/create, settings view/manage, features view/manage; the catalogue holds everything except `permissions.view`), each with a happy path and the 422 envelope. `ApiDocumentationTest` is the drift gate: route→path+method, 401 on `auth:sanctum`, 403 on `can:`, 422 on FormRequest actions, the bearer scheme, and no status outside the contract. `ApiErrorEnvelopeTest` now covers all eight codes (400/401/403/404/409/422/429/500) through the handler, with `errors` only on 422 and no `debug` when `APP_DEBUG=false`. Measured against the generated document before writing the gate, the plan's "all four on every operation" was false for health, public auth and input-less reads, so reachability is asserted instead. **Post-review pass (2026-10-21), owner-closed:** `FeatureController::toggle` validated inline on both channels and the web docblock argued for it — now `ToggleFeatureRequest`, shared; the email-change token was read with `$request->query()` on both channels — now `VerifyEmailChangeRequest` (`nullable`, so a missing token stays the documented 400); the three E4 middleware 403s and the docs 401/403 now build through `ApiError`, and `ThrottleJsonResponseTest` asserts `meta` on the **limiter's own** answer, the path that bypassed the handler. 429 is documented as a rule for every throttled operation rather than 50 repeated attributes. Full suite and lint measured after the fixes — see § Test Status. **Post-close document audit (2026-10-24):** the generated document now publishes the contract envelope (`ApiErrorResponseExtension`) and each route's `can:`/`feature:` (`AuthorizationDescriptionExtension`), the scheme is `bearerAuth`, operations are tagged by module, and three endpoints that had no API test are covered. No route, status or success shape changed — see `phase-12-api-v1.md` § *Post-close audit*. |
| 13 | Security hardening | **DONE (owner-closed 2026-10-27)** — five groups, two of them construction and three audit-and-close, across `feature/phase-13-security-hardening`. **A (headers/CSP)** pinned the existing header set from both sides: the CSP nonce matches the document, `'unsafe-inline'` is absent from script-src, HSTS survives redirect and JSON responses, and the `{!! !!}` allowlist is exactly the three users-index sort icons plus the two vendor Pulse emitters. **B (CSRF)** found a live ordering defect: the middleware was *appended* to the `web` group, so `auth` ran ahead of it, and the 419 branch did not match the exception the framework actually throws. `ValidateCsrfToken` now replaces `PreventRequestForgery` in the same group and the 419 page is reachable; `CsrfProtectionTest` pins 419 per verb, the JSON envelope, and the empty `except` list. **C (CORS)** built `config/cors.php` (it did not exist): default-deny, `api/*` only, credentials derived from the origin list rather than a second env key (D-6), refusal pinned against the middleware with two configured origins; `CorsPolicyTest` 8/41. **D (input safety)** changed no production code — the sinks and whitelists already held (only `orderByRaw` is a constant, no `$guarded = []`, every index allowlists `sort`/`direction`), so `InputSanitizationTest` 6/31 fills the gaps the other suites left: index/show escaping of a stored hostile name, mass-assignment refusal on profile and admin update, web sort refusal, undeclared query key absent from the query log. **E (disclosure/limits)** is verification: the HTML 500 branch now proves no exception class, message, source path or stack trace reaches a browser (`SecurityAuditTest`), and `CompositeRateLimitKeyTest` 4/20 pins what each throttle bucket is keyed on — including the first 429 probes for `reset-password` and `email-verification`. Group E2's brief claimed one email from two IPs shares a bucket; the code keys on identifier + IP, so a second address is a second budget and `login-ip` is the bucket that sees a spray — measured, documented in `rate-limiting.md`, and left as shipped. Open findings (reported, not fixed): `RolesChangedNotification` interpolates newline-permitting role names into Markdown body lines. (The action-level login lock's 429 now answers `RATE_LIMITED` + `retry_after_seconds`, `X-Request-ID` is accepted on CORS preflight, `CORS_ALLOWED_ORIGINS=*` is honoured only uncredentialed, and the leftover `echo "DEBUG Request …"` lines are gone — all fixed 2026-10-27.) Full suite 2241/8034, Pint clean. Detail: `docs/planning/phase-13-security-hardening.md`. |
| 14 | Storage/backup/retention | PLANNED |
| 15 | Comprehensive testing | PLANNED |
| 16 | Documentation verification | PLANNED |
| 17 | Full regression / final review | PLANNED |

## Current Task

**Nothing in development; one branch awaits merge.** The phase table above is the authority
for what is open: rows 0–2, 4–5 and 7–11 DONE (Phase 10 Audit Trail closed 2026-10-13, Phase 11
Monitoring/Observability closed 2026-10-16), row 12 is DONE — capability gap audit, the
five-group breakdown, and the post-review fixes, closed 2026-10-21; rows 3 and 6 still IN PROGRESS, row 13 DONE, rows 14–17 PLANNED.
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
- ADR-022: Vantage queue monitor, gated like every other module
- ADR-023: the ungated health endpoint gets a ceiling and a cached check set
- ADR-024: the flag reaches the writes, and the permission follows the flag
  (Phase 11 security pass)

## Test Status

**2241 passed / 8034 assertions** (2026-10-27, `feature/phase-13-security-hardening` — measured by Phase 13 Group E3 after all five groups landed; baseline 2208/7805 on 2026-10-26 after Group A). `vendor/bin/pint --test` passes across the whole tree. PHPUnit 12, `test_` prefix, SQLite
`:memory:`, 5 intentionally assertion-free benchmark cases. This is the LIVE figure; the counts inside the phase rows above are dated gate measurements, deliberately left at what each gate actually saw.

The suite is grouped by domain (13 folders under `tests/Feature/`, nothing at the root) and governed by
one architectural gate that was added after the grouping exposed how far the convention had drifted:
**no inline FQCN anywhere** — enforced by `tests/Arch/NoInlineFqnTest.php`, which token-scans all 326
PHP files in `app/` and `tests/` (one data set per file, so a violation names its own file). See `docs/base/architecture/naming-conventions.md`.

| Area | File | Tests |
|---|---|---|
| Notifications — module | `NotificationUiRenderTest` 19 · `NotificationAccessTest` 14 · `NotificationSettingsTest` 20 · `NotificationInboxTest` 26 | 79 |
| Notifications — rules | `NotificationCopyTest` 5 × 27 cases · `AccountStateNotificationTest` 8 · `AdministrativeEventDispatchTest` 10 | 23 |
| Notifications — measurement & attack | `NotificationBenchmarkTest` 8 · `NotificationPentestTest` 11 | 19 |
| Phase 8 spillover | `SettingsPentestTest` 15 (incl. the SMTP-credential disclosure probe) | 15 |

Phase 15 ("comprehensive testing") is still PLANNED — the suite is large, not yet deliberately
exhaustive per surface.

## Documentation Status

| Area | Status |
|------|--------|
| Architecture | Complete |
| Dependencies | Complete |
| Security | Complete |
| API | Complete |
| Data | Complete |
| Infrastructure | Complete |
| Features | Complete |
| Testing | Complete |
| Operations | Complete |
| UI | Complete |
| Planning | Complete |

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