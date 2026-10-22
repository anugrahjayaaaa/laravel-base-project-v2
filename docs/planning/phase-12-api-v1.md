# Phase 12 — API V1

> Date: 2026-10-20 | Status: **DONE** (owner-closed 2026-10-21) | Branch: `feature/phase-12-api-v1`
> Part I is the capability audit of 2026-10-13, kept as written because it is the
> record of what the route-table comparison found and `ChannelParityTest`
> references it. Part II is the execution breakdown that closes the remaining
> gaps and puts API documentation under the same standard as the code.
> Dependency chain: **A → B → C → D → E**. A cannot be skipped: every later
> group's definition of done includes a documentation step that A makes possible.

---

# Part I — Capability gap audit (2026-10-13)

> Raised 2026-10-13 by comparing the registered route tables rather than by
> reading the feature docs, after Phase 10's export work surfaced that the audit
> trail existed on one channel only.

## Headline

**Every WRITE to the audit trail went through the shared Actions from day one.
The READ side existed on one channel.** So the gap was transport and
serialization, not logic — and that is the reassuring part of this document. The
unreassuring part is that it went unnoticed through a whole phase, which says the
check that would have caught it is manual and has to be deliberate.

This is ADR-001 and ADR-002 ("all core capabilities have a clean API boundary",
"the boundary must remain usable if the UI is replaced") failing silently. Neither
is a rule you can hold an implementation to on review; they are only visible
when you diff the two route lists.

## What the audit found

Method: `php artisan route:list`, grouped by **capability** rather than by URI.
Path shape is not a gap — `POST /login` and `POST /api/v1/auth/login` are one
capability with two addresses, and reading the URI as the answer produces a
false-positive on almost every auth route.

Result at the time: 31 capability pairs matched, and these did not.

| # | Gap | Severity | Status |
|---|-----|----------|--------|
| 1 | Audit trail — no API at all | 🔴 | **Closed** |
| 2 | Notification inbox — no API at all | 🔴 | **Closed** |
| 3 | `roles.show` — API only | 🟢 | Documented, not a gap |
| 4 | `api.v1.auth.unlock` — closure shim | 🟡 | **Closed** — removed in P12-C1 |
| 5 | Verb mismatches on four capabilities | — | **Was not a gap** |

### 1. Audit trail — CLOSED

Browser-only: `GET /activity-logs`, `GET /activity-logs/{activity}`,
`POST /activity-logs/exports`, `GET /activity-logs/exports/{export}/download`.

Now `App\Http\Controllers\Api\V1\Audit\AuditController` with
`ActivityResource` and `AuditExportResource`, under `feature:activity_logs` and
the same `audit.view` / `audit.export` split the web routes carry. The export
routes reuse the **same `audit-export` limiter**, so arriving over HTTP is not a
way to get a larger allowance than a browser gets.

Three decisions worth keeping:

- **`POST` returns 202 and the row**, not a bare acknowledgement. The file does
  not exist yet, and the client needs the id to poll and the filters back to tell
  five exports apart without keeping its own state.
- **The resource never emits `path`.** A disk path in an API response is a
  private-storage location handed to a second consumer, and one that keeps
  working in that consumer's head after the sweep unlinks the file. A client
  downloads through the route, as the web viewer's button does.
- **`{activity}` is constrained to a number.** `/activity-logs/exports` is one
  segment shorter than the download route, so an unconstrained parameter
  swallows it depending on registration order — a literal path losing to a
  parameter, which reads as an endpoint that is absent rather than one that is
  shadowed. Applied to the web twin for the same reason.

### 2. Notification inbox — CLOSED

The inversion this found is the memorable part: the API could read a user's
notification **settings** — which channels are switched on — and could not read
a single notification. The preference without the thing the preference is about.

`App\Http\Controllers\Api\V1\Notification\NotificationInboxController`, ungated
on both channels and for the same reason: a notification belongs to the person
it was sent to, "whose inbox is this" is answered by the token, and there is no
permission name to check. `NotificationInboxAction` is shared, so marking read
invalidates the cached count and cached list exactly as the web path does.

**`SafeUrl` was extracted for this.** The same-origin check on
`notification.data['url']` was a private method on `NotificationInboxAction`,
guarding the web view. The API channel put the same question in a second place,
and a second copy of a security check is the copy that gets forgotten when one of
them is edited. Both call `App\Support\SafeUrl::actionFor()` now. The API
pentest probes it with `javascript:`, `//evil.example`, an absolute URL and a
slashless relative path.

### 3. `roles.show` — API only, and that is correct

The web has no role detail page because `roles.edit` **is** the detail view: it
loads the same `permissions` relation and the same `loadCount('users')` as the
API's `show`. Adding `routes.show` would be a second page rendering a second
copy of the same facts. Recorded here so the next audit does not file it again
as an open gap.

### 4. `api.v1.auth.unlock` — CLOSED, the shim is removed

**Closed in P12-C1.** The shim forwarded `request()->route('user')` on a route
with no `{user}`, so URL generation threw and the endpoint answered 500 to every
caller — measured with a probe test before the fix. It also did not do what its
comment claimed: Scramble listed it as a 200 with no body, so "absent from the
generated docs" was never true.

The compatibility target never shipped, and the id it needed is in the current
address (`POST /api/v1/users/{user}/unlock`), so the redirect was removed rather
than repaired. `tests/Feature/Api/V1/Auth/UnlockShimTest` pins the absence so it
cannot return without a decision about where the id comes from.

### 5. Verb mismatches — not a gap, and worth not "fixing"

Four capabilities use `POST` on the web and `PUT` on the API: settings, profile
password change, notification settings, notification channels.

This looks like drift and is not. **A browser form can only send GET or POST.**
`password-change-form.blade.php` posts with `@method('PUT')`, which is Laravel's
method spoofing and is working. So the web verb is dictated by the platform and
the API verb by REST; aligning them would mean either breaking the JSON API or
misrepresenting what a form can do. The Actions are already shared, so the
behaviour cannot diverge — only the spelling does, and the spelling is correct on
both sides.

Flagged here because it is the kind of thing an audit finds and an eager
implementer "fixes" into a bug.

## Follow-ups from the audit

| # | Item | Status (2026-10-20) |
|---|------|---------------------|
| P12-1 | A test that diffs the two route tables by capability | **DONE** — `tests/Feature/Api/ChannelParityTest.php`. A route missing from the map fails the test; a one-sided capability must carry `why` |
| P12-2 | The other three rate limiters (`user-state-actions`, `bulk-action`, `send-test-mail`) are still literals | **DONE** — already read through `SystemSetting::getInt()` in `AppServiceProvider` (lines ~292–322) and are seeded; this table was stale |
| P12-3 | Audit the API for **response** parity, not route parity | **DONE** — `tests/Feature/Api/ResponseParityTest.php` (P12-D7) |
| P12-4 | `Api\V1\NotificationController.php` and the new `Notification/` sub-namespace coexist | **DONE** — folded into `Notification/` by P12-C6 |

## The lesson, stated once

Phase 9 shipped notifications on both channels. Phase 10 shipped the audit trail
on one. Both passes read as complete, and the tracker recorded them as such.

A capability is not delivered by the UI that shows it. The audit that would have
caught this is comparing `route:list` against itself — so it has to be a test,
or it has to be a person remembering to look. **P12-1 is the one that matters**,
and it now exists.

---

# Part II — Implementation breakdown

## Scope

Three jobs, in this order:

1. **Make the API documentation real.** Scramble is installed and `/docs/api`
   already answers, but the interface is ungated outside `local`, the package is
   dev-only, and the document says nothing about authentication. Group A.
2. **Close the contract gaps** the audit could not see: the two documented error
   codes the handler never emits, the one API endpoint that does not exist, the
   routes that carry no `can:`, and a response shape that only holds outside
   `APP_DEBUG`. Groups B–D.
3. **Prove it.** Feature tests per module, plus a documentation-drift test that
   fails when a route is undocumented. Group E.

Not in scope: v2, header versioning, generated SDKs, Postman collections,
annotating the web routes.

## What already exists (measured 2026-10-20, before writing any task)

This phase is mostly an **audit-and-close** list, not greenfield. The brief
assumed a project with no API. The measured state:

| Capability | Evidence | State |
|---|---|---|
| Scramble package | `dedoc/scramble ^0.13` in `require-dev` (now `require`, ADR-025) | Installed |
| Scramble config | `config/scramble.php` published, `api_path = api` | Present, untuned |
| Docs routes | `docs/api`, `docs/api.json`, `_scramble/dev-tools/*` in `route:list` | Live |
| Docs access control | `RestrictedDocsAccess` in `scramble.middleware`; `Gate::define('viewApiDocs')` | **Missing** → 403 everywhere outside `local` |
| Base JSON response | `App\Http\Controllers\Controller::respond()` with `meta.request_id` / `meta.timestamp` | Present |
| Resource transformers | `Resources/Api/V1/{User,Role,Permission,Notification,Audit,HealthCheck}` | Present |
| 422 contract | `BaseFormRequest` + `FormatsApiErrors` → `{message, errors, code, meta}` | Present + tested |
| 400/401/403/404/409/429/500 | `bootstrap/app.php` `renderable()` | Present, **inside `!config('app.debug')`** |
| API v1 routes | `routes/api.php` — auth, profile, sessions, settings, users, roles, permissions, notifications, inbox, audit, features, health | ~70 routes, shipped |
| Feature-flag enforcement | `EnsureFeatureIsEnabled` → **403**, no superadmin bypass | Shipped, tested |
| Route parity gate | `ChannelParityTest` | Shipped |
| Audit `source = api` | `Auditable::auditContext()` (`$request->is('api/*')`) | Shipped |
| Rate limiters | `user-state-actions`, `bulk-action`, `send-test-mail`, `audit-export`, `health`, auth set | No `api` limiter |
| API feature tests | `tests/Feature/Api/V1/{Auth,User,Notification,Audit}` + `ProfileTest`, `UserCrudApiTest`, `ValidationErrorContractTest` | Partial — no Role, Permission, Settings, Feature |

Consequence for the plan: **do not re-build what is listed as Present.** Every
task below says what it touches and what it verifies; a task whose subject
already exists is a *verification + annotation* task, and is worded that way.

## Decisions that override the brief

Six points in the brief disagree with shipped behaviour or with
`docs/base/`. Each is recorded here rather than silently followed, per the
project rule that docs win and conflicts get written down.

| # | Brief says | Reality / decision | Why |
|---|---|---|---|
| **D-1** | Feature flag returns **404** on a disabled module | **403, and it stays 403** | Shipped in Phase 7 and pinned by `FeatureFlagMiddlewareTest` for every role including superadmin. `docs/planning/phase-7-feature-flags.md` §Overview rejects 404 explicitly: 404 claims the route does not exist, which is false, and produces "this page 404s intermittently" support tickets. Changing it now would break the flag tests and contradict `docs/base/` and `AGENTS.md`. The breakdown enforces 403 |
| **D-2** | New `App\Http\Controllers\Api\V1\BaseApiController` | **Not created.** `App\Http\Controllers\Controller` already is that class — `respond()` is the single JSON envelope helper, every API controller extends it | A second empty class under a versioned namespace would be a second name for one thing, and the next person would have to guess which base a controller should use. If V2 needs a base, it gets created when it has behaviour to hold |
| **D-3** | Groups C and D described as endpoint implementation | **Groups C and D are audit-and-close, not greenfield** | `routes/api.php`, the Resources and the `can:` gates are already there. Writing "implement user endpoints" would produce a plan that re-does shipped work; a task here verifies, annotates, and closes only what is actually missing |
| **D-4** | Docs reachable in production behind a gate | **Scramble stays dev-only.** Under `composer install --no-dev` the routes do not exist at all, so a production gate would protect nothing — **SUPERSEDED by ADR-025 (2026-10-21): promoted to `require`, and the document is exported at deploy because `scramble:cache` measured no benefit** | `docs/base/dependencies/overview.md` §Scramble records "documentation-only, no runtime dependency in production". If production docs are wanted, that is a dependency change: promote to `require` **plus** a changelog entry and an ADR, per the project rule for package changes. Group A carries that as an explicit decision task, not a default |
| **D-5** | `throttle:api` on the API | **No `api` limiter exists, and adding one must not loosen anything** | The protected group already runs `throttle:user-state-actions` at 15/min per user — tighter than a typical 60/min `api` limiter. Adding `api` beside it is safe only because both apply and the tightest wins; adding it *instead* would be a 4× loosening on every authenticated endpoint. Group D states the ladder before touching it |
| **D-6** | Group E starts testing from zero | **Four API test folders already exist** | New tests go beside them in the same shape (`tests/Feature/Api/V1/{Domain}`), not in a parallel tree |

## The mandatory documentation step

**This is the standard every task in Groups B, C and D inherits.** An endpoint is
not done when it returns the right JSON; it is done when the generated document
describes that JSON. Scramble reads the code, so this is not a separate
annotation pass bolted on afterwards — it is the reason the code has to say what
it returns.

For **every** endpoint created or changed:

| # | Requirement | Where it lives |
|---|---|---|
| 1 | Route is inside the `api/v1` prefix and named `api.v1.*` | `routes/api.php` |
| 2 | Method signature has a return type (`JsonResponse`, `AnonymousResourceCollection`, `Resource`, …) | controller |
| 3 | `@return` array shape on every `Resource::toArray()` — conditional keys declared, not inferred | `Resources/Api/V1/**` |
| 4 | Every query parameter documented: name, type, required, default, allowlisted values | FormRequest + `@queryParam` / inferred from `$request->validated()` |
| 5 | Request body from the FormRequest rules — no endpoint may validate inline | FormRequest |
| 6 | Every status code the endpoint can return is present, with the envelope from `docs/base/api/error-contract.md`: **200, 201, 400, 401, 403, 404, 409, 422, 429, 500**. The subset is decided by reachability: 401 on `auth:sanctum`, 403 on `can:`, 422 when the action takes a FormRequest, 500 from the handler fallback. Public auth, health and input-less reads cannot carry all four — see P12-E2's measured note | controller docblock |
| 7 | Authorization requirement stated (`can:*`, `feature:*`) | route + `@authenticated` / description |
| 8 | `Authorization: Bearer` shown for every `auth:sanctum` route | `config/scramble.php` `security_strategy` (P12-D5) |
| 9 | `GET /docs/api.json` contains the route, and a test asserts it | `ApiDocumentationTest` (Group E) |

**Definition of done, restated:** a task that adds or edits an endpoint and leaves
item 9 failing is `IN_PROGRESS`, not `REVIEW`.

Two mechanical helpers this phase adds so the standard is not remembered by hand:

- `php artisan scramble:analyze` — surfaces routes Scramble could not infer.
- `ApiDocumentationTest` — asserts every `api.v1.*` route appears in the spec.
  A new undocumented endpoint then fails CI on the commit that adds it, which is
  the same trick `ChannelParityTest` uses for channels.

---

## Group A — UI & API documentation interface (Scramble)

> Depends on: nothing. Blocks: every other group (the documentation step needs a
> working document and a way to regenerate it).

Status: **DONE** — implemented and committed 2026-10-20 on
`feature/p12-a-api-docs`, split into seven commits by layer (authorization,
Scramble config, settings, sidebar, tests, docs). `--filter=ApiDocsUiRenderTest`:
10 passed / 31 assertions (9 docs tests + the arch scan, which matches the same
filter); `tests/Feature/Settings` + `FeatureFlag` green, `Notification` 261
passed, `Role` + `PermissionFeatureMapTest` 266 passed after the catalogue grew
by one, `tests/Arch` 433 passed, full suite **2091 passed / 7314 assertions**
(406s).

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P12-A1 | **Confirm package placement.** Verify `dedoc/scramble` in `require-dev` is deliberate, and record the consequence: with `--no-dev` the `/docs/api` routes do not exist. If docs must be reachable in staging or production, promote to `require` + changelog + ADR-025 (per D-4). Output: one paragraph in `docs/base/api/documentation.md` saying where docs exist and where they do not | P0 | — | DONE — dev-only kept, consequence written up in `documentation.md` §Availability. **FOLLOWED BY ADR-025 (2026-10-21):** promoted to `require` and the spec is exported at deploy, since the setting already promised a production surface |
| P12-A2 | **Tune `config/scramble.php`.** `info.version` defaults to `0.0.1` while the API is v1 — drive it from `API_VERSION=v1` (`.env.example` included). Set `info.description`, `ui.title` (`Laravel Base Project API`), and `renderer` (`elements`). Leave `export_path = api.json` | P0 | — | DONE — version `env('API_VERSION', 'v1')`, title follows `APP_NAME`, description names the bearer token and the audit rule |
| P12-A3 | **Gate `viewApiDocs`.** `RestrictedDocsAccess` calls `Gate::allows('viewApiDocs')` outside `local`, and no such gate exists — so today `/docs/api` is 403 for everyone in staging. Define it against a real permission: add `api_docs.view` to `PermissionCatalog`, seed it (superadmin inherits via `Gate::before`; `admin` gets it from the catalogue), and define the gate in `AuthServiceProvider` beside `configureSuperAdmin()` | P0 | — | DONE — `api_docs.view` in `PermissionCatalog` (listed in `UNMAPPED_RESOURCES` — no flag owns a document about every module), `AuthServiceProvider::configureApiDocsGate()` takes `?User` so a guest does not 500. The gate also reads `api_docs_public`, so the route and the sidebar consult one answer |
| P12-A4 | **`ApiDocsUiRenderTest`.** `/docs/api` renders 200 for a holder of `api_docs.view`; 403 for an authenticated non-holder; `/docs/api.json` returns 200 with a parseable body carrying `openapi`, `info.version`, and every `api.v1.*` path. Refusal shape follows A7: a guest gets the login page on the UI and `401` on the document | P0 | P12-A3 | DONE — `tests/Feature/Api/ApiDocsUiRenderTest.php`, 9 tests; the non-holder case asserts `app()->environment() !== 'local'` first |
| P12-A5 | **Try-it credentials policy.** `ui.renderers.elements.tryItCredentialsPolicy` is `include`. A docs page behind a permission should not instruct the browser to send cookies to a host chosen in the UI. Set `same-origin`, and keep `dev_tools.enabled` tied to `APP_DEBUG` (already is) | P1 | P12-A2 | DONE — `same-origin` |
| P12-A6 | **Reconcile `docs/base/api/documentation.md`.** It advertises `/api/docs/v1/`; Scramble serves `/docs/api` + `/docs/api.json`. Rewrite the versioning note, the auth note (bearer token lives in Group D), and the dev-only paragraph from A1. Code is the truth; the doc follows it | P0 | P12-A2 | DONE — rewritten; the bearer `securitySchemes` gap is called out as P12-D5 rather than described as done |
| P12-A7 | **Operator-controlled visibility.** Requested by the owner 2026-10-20 after A1..A6: a docs page that is always private is not usable for a demo, and one that is always public is a disclosure. Add a `api_docs_public` system setting (off by default, seeded, on `/settings`, validated and whitelisted so it actually persists), put it inside the `viewApiDocs` gate rather than in the middleware so the route and the sidebar ask one question, replace `RestrictedDocsAccess` in `config/scramble.php` with `RestrictApiDocsAccess` (the vendor middleware's `environment('local')` half would override the setting), and add the *API Documentation* sidebar entry gated on the same ability | P0 | P12-A3 | DONE — guest → login redirect on the UI, `401` on `/docs/api.json`, `403` for a signed-in non-holder; menu follows public mode |

**Accepted deviations in this group:**

- The UI is Scramble's bundled Stoplight Elements view. No custom Blade page —
  a hand-built docs screen would be a second renderer for one document.
- The OpenAPI document is generated per request (or from `scramble:cache` in
  deployment). No checked-in `api.json`: a committed spec is a second source of
  truth that drifts the first time someone forgets to export.

**Gate A:** `/docs/api` opens for a permitted user, refuses everyone else, and
`GET /docs/api.json` lists every registered `api.v1.*` route.

**Measured (2026-10-20):** the gate is real — admin 200, plain user 403, guest
403, superadmin 200 through `Gate::before`; the document publishes
`info.version = v1`, its `paths` include `/v1/users`, and since P12-D5 it names
the bearer `securitySchemes` entry. The missing half — a test that fails when an
endpoint is undocumented — was P12-E2 and now ships as `ApiDocumentationTest`.

---

## Group B — Core API infrastructure, base responses & resources

> Depends on: A (the schema needs somewhere to live). Blocks: C, D.
> Most of this exists; the tasks are gap-closing, not construction.

Status: **DONE**.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P12-B1 | **Base controller — keep one.** `App\Http\Controllers\Controller::respond()` is the base (D-2). No `Api/V1/BaseApiController` is created. Verify every API controller that returns a bare array uses `respond()` or a Resource, and record the decision in `docs/base/api/response-contract.md` so the next reader does not add a second base | P0 | — | DONE |
| P12-B2 | **Resource schema pass.** Every `Resources/Api/V1/**::toArray()` gets an explicit `@return array{…}` shape covering conditional keys (e.g. `UserResource` omits relations when not loaded). Add the three missing transformers if a controller still returns an ad-hoc array: `SessionResource`, `SystemSettingResource`, `FeatureResource`. No new abstraction over `JsonResource` — it already does the job | P0 | P12-A4 | DONE |
| P12-B3 | **Error envelope — close the documented-but-unreachable codes.** `docs/base/api/error-contract.md` lists `CONFLICT` (409) and `SERVICE_UNAVAILABLE` (503); the handler emits `LAST_SUPERADMIN` (409) and never 503. It also emits `HTTP_ERROR` for every generic 4xx. Reconcile doc against code, and add `meta.request_id` / `meta.timestamp` to handler-generated errors so an error and its neighbouring success share one shape (422 already does; the `renderable()` block does not) | P0 | — | DONE |
| P12-B4 | **The `APP_DEBUG` hole.** The entire API error envelope is inside `! config('app.debug')`. In debug the API answers with Laravel's default HTML/JSON, so the contract is untested by default and a debug-enabled deployment leaks the framework shape. Decide explicitly: keep the branch (and make every contract test set `app.debug = false`), or emit the envelope unconditionally and let debug only add detail. Record the choice in `error-contract.md` | P0 | P12-B3 | DONE |
| P12-B5 | **Pagination contract.** `AnonymousResourceCollection` wrapping is default; confirm the pagination envelope (`data`, `meta`, `links`) is what `docs/base/api/response-contract.md` claims, and that `withQueryString()` survives on every index. | P1 | P12-B2 | DONE |
| P12-B6 | **Documentation step** — every resource above carries its schema, and `scramble:analyze` reports no uninferable route in the `v1` surface | P0 | P12-B1..B5 | DONE |

**Gate B:** `GET /docs/api.json` shows typed schemas for every resource, every
documented status code has a reachable emitter, and no API controller returns a
bare `array`.

### Measured (2026-10-20)

**B1 — one base controller, four duplicates found.** Twenty-two API controllers
were read. `UserStateController` carried a private `success()` that hand-built
`data.message` + `meta` byte for byte identical to `respond()`; it is gone.
`UserController::store`, `PasswordChangeController`, `PasswordResetController`
and `PasswordForgotController` each hand-built the same envelope and now call
`respond()`. The decision and its reasoning live in `response-contract.md` §
*One base controller*.

**B2 — eight resources now declare their shape.** The seven existing resources
carried `@return array<string, mixed>`, which documents nothing; each now names
every key and marks conditional keys optional (`users_count?: int`,
`download_url?: string`, `action?: array{...}`). `SessionResource` was the one
real gap: `SessionController` mapped tokens to arrays inline, which is
serialisation living in a controller. The other two named in the task are **not
created**, deliberately: `SystemSettingController` answers with
`SystemSetting::getAll()`, a key→value map, and `FeatureController` answers with
an action result; a transformer over a map or over scalars would be a passthrough
with nothing to own. Both shapes are documented instead (`response-contract.md` §
*Responses that are not envelopes*). Regression test:
`tests/Feature/Api/V1/SessionApiTest` pins the token payload and the two keys
that must never appear (`token`, `hash`).

**B3 — the reconciliation found a live defect, not just a stale table.** Two
documented codes were unreachable: `NotFoundHttpException` and
`ThrottleRequestsException` both extend `HttpException`, and the blanket
`HttpException && 4xx` branch sat above them — so every route-level 404 and every
framework-level 429 answered `HTTP_ERROR`. Specific branches now come first.
`meta` is on every handler error, and on the two errors emitted ahead of the
`api/*` block (the `LAST_SUPERADMIN` 409 and the CSRF 419), through
`App\Support\ApiError` — one definition, also used by `Controller::respond()`
and by `FormatsApiErrors` for the 422 a form request throws, so the three
paths cannot drift.
Doc side: `CONFLICT` removed (nothing emits it; `LAST_SUPERADMIN` is the only
409), and 503 re-attributed to the health probe, which answers with its own
unwrapped `{status, timestamp, checks}` body rather than the error envelope.

**B4 — unconditional, and debug only adds a key.** The branch is removed. The
contract is now the same in every environment; `app.debug` adds
`debug.exception` / `debug.message` and nothing else. Traces stay in the log
(`report()` still runs). Rationale: a body whose keys depend on an env var is
not a contract — a client cannot branch on a payload a deploy can rewrite.
`tests/Feature/CrossCutting/ApiErrorEnvelopeTest` asserts the key sets are
identical with debug off and on (minus `debug`).

**B5 — the doc was wrong, not the code.** There is no `Link` header and no
top-level `meta.pagination`. A collection is `data.<plural>` as a plain array
with `data.pagination = {current_page, last_page, per_page, total}` beside it;
the resources are not nested paginator objects, so there is one place to read a
page count. Defaults are 10 (users, roles, audit) and 30 (inbox). The inbox is
the one list with no pagination block — it returns `unread_count` beside the
rows, and a polling client uses `unread-count` + `latest_id` instead.
`withQueryString()` has nothing to append: paging links are not emitted at all.
Contract rewritten to the measured shapes, including the real query-parameter
names (`search`/`sort`/`direction`/`per_page`, not `filter[field]`).

**B6 — `scramble:analyze`: 2 warnings → 1, and the health schema was a lie.**
`UserResource` was uninferable (no `@property-read`); fixed, and the generated
component now names every field. `HealthCheckResource` still warns: it wraps an
array, not a model, so there is no model type to infer and naming a fictional one
would be worse than the warning. The exported document caught something the
warning did not: `/v1/health` had `schema: {type: string}`, because the controller
returns a prepared `JsonResponse` the generator cannot follow. Both statuses are
now declared on the action with `#[Response]` (200 and 503, the same unwrapped
`{status, timestamp, checks}` shape), so the route is typed and the probe's
failure mode is documented. Remaining warning is resource-level inference only,
not an uninferable route.

**Accepted deviations:** no `SystemSettingResource`, no
`FeatureResource` (reason above). Controller refusals that answered through
`respond($message, 4xx)` — the auth family — carried `data.message` and no
`code` at the time; **P12-C1 migrated six** to `respondError()` with a code
each, and the conformance audit later the same day found two more (verify-email,
admin resend) that moved with the rest — see the changelog entry of 2026-10-20.
`error-contract.md` § *Controller refusals* records the vocabulary.


---

## Group C — API V1 routes & controller implementation

> Depends on: A, B. Blocks: D, E.

Status: **DONE**.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P12-C1 | **Auth** — verify `login`, `logout`, `logout-all`, `register`, password forgot/reset/change, `email/resend`, `verify/{id}/{hash}` against the web twin; annotate. **Suspected defect to confirm first:** `POST /api/v1/auth/unlock` is `fn () => to_route('api.v1.users.unlock', ['user' => request()->route('user')])` — that route has no `{user}`, so the parameter resolves `null` and URL generation should throw. Write the failing test before deciding the fix; do not fix blind | P0 | P12-B6 | DONE |
| P12-C2 | **Users** — verify `index/show/store/update/destroy/restore/force-delete`, four state toggles, email-change pair, `resend-verification`, `bulk-action`. Annotate query parameters (`UserQueryRequest` allowlist) and the 409 from `LastSuperadminException` | P0 | P12-B6 | DONE |
| P12-C3 | **Roles & permissions** — verify CRUD + restore/force + bulk, and `permissions.index`. Annotate the guard-scoped uniqueness 422 and the system-role refusals | P0 | P12-B6 | DONE |
| P12-C4 | **Settings** — verify `GET/PUT /settings`; annotate the partial-key semantics (`update` replaces only submitted keys) and the `mail_password` filtering (P9-X1: secrets never leave the endpoint) | P0 | P12-B6 | DONE |
| P12-C5 | **`GET /api/v1/features` — the one absent endpoint.** `ChannelParityTest` declares `feature list` as a one-sided capability with a `why`. Either implement the read under `feature`-free routing + `features.view` (the list must not be gated on the flag it reports), or keep the declared gap. Implementing it means updating `ChannelParityTest` in the same commit — the map and the routes are one unit | P1 | P12-B2 | DONE |
| P12-C6 | **Notifications** — verify the settings pair, channels, test-mail, and the four inbox routes; annotate the inbox scoping ("the token answers whose inbox"). Fold `Api\V1\NotificationController` into the `Notification/` sub-namespace (P12-4) — a move, not a rewrite | P2 | P12-B6 | DONE |
| P12-C7 | **Audit trail** — already shipped; annotate the export lifecycle (202 on create, the poll, the download, the retry) and the `whereNumber` constraint that keeps `/exports` out of `{activity}` | P1 | P12-B6 | DONE |
| P12-C8 | **Action-First mutations.** Confirm every API mutation goes through the same Action as web and that the `DB::transaction` is inside the Action, not the controller. No controller may open a transaction around an Action that already owns one | P0 | P12-C1..C7 | DONE |

**Gate C:** every capability in `ChannelParityTest` is either on both channels or
carries a `why`; `scramble:analyze` is clean for `v1`; `/docs/api.json` documents
every route added or touched.

### Measured (2026-10-20)

**C1 — the suspected defect was real, and worse than "should throw".** A probe
test answered `500 SERVER_ERROR` on `POST /api/v1/auth/unlock`: the closure read
`request()->route('user')` on a route with no `{user}`, so URL generation threw
every time. Two things beside it were also false: the route comment claimed
Scramble cannot see a closure (the document listed the route as a 200 with no
body), and a redirect is not a compat layer when the id it needs lives in the
address it is redirecting to. Since v1 never shipped, the shim was **removed**
rather than repaired; `tests/Feature/Api/V1/Auth/UnlockShimTest` asserts the 404
envelope and the absent route name, so re-adding one starts with a decision about
where the id comes from. `ChannelParityTest`'s `user unlock` capability now maps
one route on each channel.

The refusal vocabulary (the gap `error-contract.md` deferred to this group) is
closed: six call sites moved from `respond($msg, 4xx)` to `respondError()` in
this group, and the conformance audit closed the last two (verify-email, admin
resend); `respond()` now throws on a 4xx, so `data.message` never appears in an
error body again. Login: 401 `UNAUTHENTICATED`, 403
`FORBIDDEN`, 429 `RATE_LIMITED` + `retry_after_seconds`. Register: 404
`NOT_FOUND` (the camouflage message stays; the code does not lie too). Resend:
403 `FORBIDDEN`, 422 `EMAIL_ALREADY_VERIFIED`, 429 `RATE_LIMITED`, 500
`SERVER_ERROR` — carried as `error_code` on the action's own payload, so the
controller does not translate a status number. Verify-change: 400 `INVALID_TOKEN`
(missing and expired share it). Test-mail: 502 `BAD_GATEWAY`. The three resend
codes are new rows in the contract; each has an emitter.

**C2 — the query allowlist was already in the document; the 409 was not.**
Scramble infers `search`/`sort`/`direction`/`per_page` from `UserQueryRequest`,
so no per-parameter annotation was needed. `#[Response(409, …)]` now sits on
`DELETE /users/{user}`, `POST /users/{user}/deactivate` and
`POST /users/bulk-action` — the three reachable emitters of
`LastSuperadminException` at the time. Two more were reachable only after a
pentest finding closed them: `PUT /users/{user}` (its `status` column used to be
a second writer of `is_active`, skipping the guard) and `POST /users/{user}/lock`
(no guard at all), both now annotated and listed in
`docs/base/security/authorization.md`. Force-delete deliberately carries none: it only
accepts an already-trashed row and `UserForceDeleteAction` documents why it has no
guard. One behaviour worth writing down because the status cannot express it:
`BulkActionProcessor` owns one transaction around the loop, so a 409 aborts and
rolls back the whole batch — nothing is applied, and that is now what the
endpoint's docblock says.

**C3 — one controller was outside the validation rule.** `permissions.index`
read `$request->query()` directly while every sibling used a FormRequest, so its
allowlist was invisible to the document and an unknown `sort` fell back silently
instead of answering 422. It now takes the shared `PermissionQueryRequest`, and
the conformance pass added the `in:` rule over `PermissionIndexAction::SORTABLE`
(the web index refuses an unknown column too); the generated operation gained the
four parameters and a 422. Role uniqueness and
system-role refusals were already 422 through `RoleUpdateAction`'s
`ValidationException` — nothing to add, and no 409 exists on that route
(`RoleAssignAction`, the only other `LastSuperadminException` emitter, is not
reachable from the API surface).

**C4 — documented at the endpoint, because the shape is unusual on purpose.**
`GET /settings` answers a flat key→value map (no list, no transformer — B2's
decision) and `mail_password` is **absent** rather than null, via
`SystemSetting::getAll()`'s `SECRET_KEYS`. `PUT /settings` is partial: omitted
keys survive, and only the web form reads "missing" as "reset". Both statements
now sit on the methods they describe.

**C5 — the absent read is now shipped, and the gap analysis is why it is shaped
the way it is.** First pass kept the gap: `FeatureIndexAction` is a *view* model
(builds `toggle_url` from the **web** route name, adds a viewer-scoped
`manageable`, carries per-flag `permissions`), so returning it whole would hand a
JSON client a link it cannot POST to (session + CSRF). Decided to close it
anyway, because "may toggle but cannot enumerate" is a real asymmetry — the only
way to learn a flag's state was to toggle it, i.e. a mutation plus an audit row
used as a read.

The endpoint reuses the resolution rather than duplicating it: `GET
/api/v1/features`, `can:features.view`, no `feature:*` middleware (same rule as
the two write routes — the management surface must survive every flag), serving
`FeatureIndexAction`'s rows **projected** to `slug`, `label`, `group`,
`description`, `enabled`, `pending`. The projection is the contract:
`FeatureFlagApiTest` pins the six keys so the page model cannot leak back in.
`pending` travels because a flag whose module has no routes yet is not
"disabled" — the distinction the web page already made.

No new resolver class and no `FeatureResource`: the action already owns the
single `activeMap()` read and the 30s snapshot, and a transformer over an action
result would be a passthrough (B2's rule). State coherence is pinned rather than
assumed: `test_the_catalogue_reflects_a_state_change_made_through_the_api`
toggles through the API and then reads, so a writer that stopped flushing
`SNAPSHOT_KEY` fails instead of serving a 30-second-old answer. `features.view`
alone can read (200) and cannot toggle (403); a viewer holding everything *but*
`features.view` is refused — the sibling-gate trap E1 describes.

`ChannelParityTest`'s `feature list` is now two-sided; the `why` is gone.

**C6 — the namespace move was already done; the scoping was already documented.**
Both notification controllers live in `Api\V1\Notification\` and the class
docblock already argues why the inbox carries no permission ("whose inbox is
this" is answered by the token). The only change here is the test-mail 502
joining the error envelope.

**C7 — the export lifecycle's statuses were half-documented.** Create and retry
were typed 202 and the download 200, but the refusals were missing. Download now
declares 404 (no such export **or** file gone) and 410 (retention window closed —
permanent, hence a different code). Retry declares 404 and 409, where the 409
covers three refusals a client cannot separate from the status (not failed,
budget spent, row changed under the claim) and the body carries which. The
`whereNumber('activity')` constraint that keeps `/exports` out of `{activity}` is
load-bearing and was already explained beside the route.

**C8 — verified structurally, not by reading.** Zero `DB::transaction`,
`beginTransaction`, or Eloquent mutation calls (`create`/`update`/`delete`/
`save`/`restore`/`forceDelete`) in `app/Http/Controllers/Api/V1`. All 45 Action
classes imported by an API controller are also imported by a web controller,
i.e. every API mutation runs the same Action as its web twin; the transaction
lives inside the Action. No new test — this is a shape the two greps can re-check
in seconds, and E1's per-module tests will exercise the paths.

**Not done here, on purpose.** Per-route 429 documentation belongs to the D4
ladder (which limiter refuses, at what rate, keyed by what), and the
401/403/422/500-per-operation assertion is E2's. C documented what it added or
touched: the removed route, the new `/v1/features` read, the three 409s, the
four audit export codes, the permission query allowlist, and the
settings/semantics docblocks. `Gate C` itself is met: `ChannelParityTest` is
green with no remaining one-sided capability in the feature module,
`scramble:analyze` reports its one known `HealthCheckResource` warning
(documented since B6), and the regenerated document contains no
`/v1/auth/unlock` and does contain `/v1/features`.

---

## Group D — Security, rate limiting, RBAC & audit trail

> Depends on: C. Blocks: E.

Status: **DONE**.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P12-D1 | **Sanctum abilities.** `AuthLoginCompletedAction` calls `createToken('auth-token')` with no abilities, so every token is `['*']`. Decide once: keep `*` and document why (a first-party token has the user's whole authority), or define a named ability set in one place and apply `abilities:` per route. A decision with no code change is an acceptable outcome, but it must be written down — `docs/base/api/api-architecture.md` | P0 | P12-C1 | DONE — keep `*`; § *Token abilities* in `api-architecture.md` states the reasoning and the condition that would reverse it. No code change |
| P12-D2 | **`can:` coverage sweep.** Close the routes that carry no route-level gate: `users.request-email-change`, `users.cancel-email-change`, `email.verify-change`, `features.bulk-action`, `users.bulk-action`, `roles.bulk-action`. The bulk three are authorised by `AuthorizesBulkAction` inside the FormRequest — verify it, and add a route-level gate only where the permission is fixed rather than action-dependent (adding `can:` to a bulk route would pin one permission for six actions) | P0 | P12-C2..C7 | DONE — one route closed on both channels (`/features/bulk-action`, `can:features.manage`); the rest are verified exceptions and now each has a row in `api-architecture.md` § *Deliberate exceptions* |
| P12-D3 | **Feature-flag enforcement — 403, per D-1.** Verify every module route carries `feature:*` on both channels, and that `EnsureFeatureIsEnabled` never consults the actor. Extend `FeatureFlagMiddlewareTest` only if a route is added in C | P0 | P12-C5 | DONE — route-table sweep: every module route shows `EnsureFeatureIsEnabled:<slug>`; the actor-blind handler was read and already pinned by `FeatureFlagMiddlewareTest`. C’s `GET /features` is deliberately flag-free and required no test change |
| P12-D4 | **Rate-limiting ladder, documented then measured.** Produce the table: which limiter guards which route, at what rate, keyed by what (user id vs IP). There is no `api` limiter; the protected group uses `user-state-actions` at 15/min. Adding `throttle:api` is allowed only if it is **not looser** than what it accompanies, and the docblock must say which one actually refuses. P12-2 is already closed (the three numbers read `SystemSetting`); the ladder doc is what remains | P0 | P12-D2 | DONE — § *API ladder* in `docs/base/security/rate-limiting.md`; the stale Roadmap bullet that claimed authenticated API routes had no per-endpoint limits is corrected, and the one endpoint with no ceiling (`POST /auth/password/change`) is named |
| P12-D5 | **Bearer security scheme in the document.** `security_strategy` is `null`, so `/docs/api.json` publishes no `securitySchemes` and no route is marked `security: []` or bearer. Enable `MiddlewareAuthSecurityStrategy` for `auth:sanctum` (per the commented block in `config/scramble.php`) and assert the scheme exists | P0 | P12-A4 | DONE — one line in `config/scramble.php`; the vendor strategy reads `auth:*` off the routes, so `/docs/api` renders the Authorize box and the public routes (login, register, health, verify link) are marked `security: []` instead of asking for a token they ignore. Pinned in `ApiDocsUiRenderTest` |
| P12-D6 | **Audit trail HTTP context.** `Auditable::auditContext()` already resolves `source = api` from `$request->is('api/*')`. Prove it end to end: an API mutation writes one row inside the transaction with `source=api`, the real `ip`, `user_agent`, and `request_id`; the same Action over web writes `source=web`. No controller-level `audit()` | P0 | P12-C8 | DONE — the existing cross-channel audit test now also asserts `request_id` matches the response’s `X-Request-ID` on both channels; `grep 'audit(' app/Http/Controllers/Api/V1` prints nothing |
| P12-D7 | **Response parity (P12-3).** For each capability on both channels, assert the same facts come back — the route-parity test cannot see this. Start with users and roles; declare the differing-by-design fields rather than silently normalising them | P1 | P12-C8 | DONE — `tests/Feature/Api/ResponseParityTest.php`: same rows/order for the same query, every web-rendered fact present in the API row, and an exact declared list of API-only keys |

**Gate D:** a zero-permission token gets 403 (not 404) on every admin route, the
flag tests still pass for superadmin, and the audit row for an API mutation says
`api` with a real request id.

### Measured (2026-10-20)

**D1 — the decision is the deliverable, and it is "keep `*`".** A token minted by
`/auth/login` is a logged-in session in another transport; authority is the
user's RBAC re-read on every request by `can:` / `authorize()`. A named ability
set would copy that authority into the token at login and go stale when a role
changes, and `tokenCan()` would have to be restated on every route — the
duplication the route-gate rule exists to remove. `SessionResource` already
publishes `abilities`, so a client can see what it holds (today `["*"]`). The
reversal condition is written down: delegated or machine tokens with a subset of
a user's authority.

**D2 — one real gap, five verified exceptions.** The route sweep found
`/features/bulk-action` with no `can:` on either channel, while the comment
beside it said the permission was action-dependent. It is not: both flag actions
are `features.manage`, so unlike users/roles this route can pin the permission.
It now carries `can:features.manage` on web and API, and `BulkFeatureRequest`
keeps its own `authorize()` as the payload-level check. The other five routes
are correct as they are, and each is now one row in
`api-architecture.md` § *Deliberate exceptions*: `request-email-change`
(FormRequest — "own account **or** `users.update`"), `cancel-email-change`
(`abort_unless` for the same OR), `verify-change` (`signed` + token; there is no
sign-in to authorize), and the users/roles bulk routes
(`AuthorizesBulkAction` maps action → permission).

**D3 — verified, nothing to change.** Every route inside a flagged module shows
`EnsureFeatureIsEnabled:<slug>` in `route:list`. `EnsureFeatureIsEnabled` reads
only `FeatureCatalog::isActive()` and never `$request->user()`, so the kill
switch stays actor-blind; `FeatureFlagMiddlewareTest` already pins 403 for
superadmin and for a `features.manage` holder. C added exactly one route without
a flag — `GET /api/v1/features` — and that is deliberate: the surface that
re-enables a module cannot live behind the module it re-enables.

**D4 — the ladder existed as limits, not as a route map.** The limiter table in
`rate-limiting.md` said what each limiter costs; it did not say which route meets
which, or which one refuses when two apply. § *API ladder* now does, with the
ceiling that actually wins (the tighter of the two). No `api` limiter was added:
at 60/min beside `user-state-actions` it would never be the one to refuse, and
in place of it, a 4× loosening. The Roadmap bullet claiming authenticated API
routes had no per-endpoint limits was stale and is corrected. One gap is named
rather than hidden: `POST /auth/password/change` sits outside the protected group
on purpose (an expired password must be recoverable) and therefore has no
limiter; `current_password` is still verified before any work, so the guess space
is the same password that `/auth/login` throttles.

**D6 — the context test existed; the correlation id was the missing half.**
`SystemSettingUpdateTest` already proved `source=web` and `source=api` for one
Action over two channels, plus `ip` and `user_agent`. It now also asserts each
row's `request_id` equals the `X-Request-ID` the same response carried, which is
the property an operator actually uses: one id links a body to a log to an audit
row. `grep 'audit(' app/Http/Controllers/Api/V1` returns nothing — the writes are
in the Actions, inside their transactions.

**D7 — parity is declared, not inferred.** `ResponseParityTest` covers users and
roles (the two capabilities every client touches first):
the same `search` returns the same ids in the same order on both channels, which
is what proves the controllers still share one Action; every fact the table
renders is asserted present in the API row (`created_at` compared as the date
prefix — one fact at two precisions); and the resource keys not on the table are
an exact declared list (`username`, `email_verified_at`,
`must_change_password` for users; `permissions` and the three timestamps for
roles). A field added or dropped on one channel fails until the list is edited.
Not extended to every capability: the pattern is established, and E1's
per-module tests cover the remaining modules' shapes.

---

## Group E — Automated tests & documentation verification

> Depends on: A–D.

Status: **DONE** — implemented 2026-10-20, owner-closed 2026-10-21 on `feature/phase-12-api-v1`.

| ID | Task | Pri | Depends On | Status |
|----|------|-----|-----------|--------|
| P12-E1 | **Per-module feature tests** under `tests/Feature/Api/V1/`: `Role/RoleApiTest`, `Permission/PermissionApiTest`, `Settings/SettingsApiTest`, `Feature/FeatureApiTest`. Each covers the happy path, the 422 shape, and the 403 for a sibling permission (not an empty one — a route written `can('roles.view')` where `roles.update` was meant passes every zero-permission test) | P0 | P12-D2 | DONE — four files, 16 tests. The sibling case runs in both directions where the module has two permissions (roles view/create, settings view/manage, features view/manage); the permission catalogue holds the whole catalogue minus `permissions.view`. Deep behaviour stays in the existing domain files (`RbacApiTest`, `SystemSettingUpdateTest`, `FeatureFlagApiTest`); these files own the gate |
| P12-E2 | **`ApiDocumentationTest`** — the drift gate. Reads `/docs/api.json` and asserts: every route named `api.v1.*` appears as a path+method; every documented operation carries its 401/403/422/500 responses; the bearer scheme is declared. This is what makes the documentation step enforceable instead of aspirational | P0 | P12-D5 | DONE — `tests/Feature/Api/ApiDocumentationTest.php`, 6 tests: route→path+method, 401 on `auth:sanctum`, 403 on `can:`, 422 on FormRequest actions, bearer scheme + global `security`, and no status outside the contract. The "all four on every operation" wording was corrected to reachability (see Measured) |
| P12-E3 | **`ApiDocsUiRenderTest`** — from A4, kept here as the regression owner once the gate lands | P0 | P12-A3 | DONE — shipped with A4; unchanged. It remains the access/UI owner (gate, public mode, sidebar); E2 owns the document content |
| P12-E4 | **Error-contract coverage** — one test per emitted code (400, 401, 403, 404, 409, 422, 429, 500), asserting the envelope and `meta.request_id`. Includes the `app.debug = false` branch from B4 | P0 | P12-B3, B4 | DONE — a data-provider matrix in `tests/Feature/CrossCutting/ApiErrorEnvelopeTest.php` raises each handler-mapped exception from a throw-away route and asserts status, code, `meta.request_id`, `errors` only on 422, and no `debug` with `APP_DEBUG=false`. Endpoint-level reachability stays with the existing tests (`LastSuperadminGuardTest` 409, `ThrottleJsonResponseTest` 429) |
| P12-E5 | **Full verification** — `php artisan test` green (baseline 2078 passed / 7265 assertions), `vendor/bin/pint --test` clean on every touched file, `php artisan scramble:analyze` clean, `GET /docs/api.json` parses. Docs reconciled: this file, `task-tracker.md`, `progress.md`, `docs/base/api/{documentation,error-contract,response-contract}.md` | P0 | P12-E1..E4 | DONE — full suite and lint measured below; `scramble:analyze` reports only the known `HealthCheckResource` warning |

**Gate E:** the suite is green, and a route that exists under `/api/v1` but is
absent from `/docs/api.json` turns `ApiDocumentationTest` red.

The earlier wording — "deleting a route turns the test red" — cannot hold: the
document is generated per request, so a deleted route disappears from both
sides. The gate protects the direction that can actually drift, an added route
Scramble could not infer. A checked-in spec would gate deletions too, and that
is the second source of truth Group A rejected.

**Measured (2026-10-20):** 30 new tests (E1 16, E2 6, E4 8), all green alongside
the existing domain files; full suite **2148 passed / 7574 assertions** (360s),
`vendor/bin/pint --test` clean, `php artisan scramble:analyze` only the known
`HealthCheckResource` warning. The response-code rule was measured against the
generated document before it was written: 401/403/422 are inferred from the
middleware and FormRequest, 500 only where annotated, so a blanket
four-codes-per-operation assertion would have been false for health, the public
auth routes and input-less reads.

E4 did find one real deviation while covering 403: three middleware refusals
(`ACCOUNT_DISABLED`, `ACCOUNT_LOCKED_INACTIVITY`, `PASSWORD_CHANGE_REQUIRED`)
answered without the `meta` block. Recorded at the time and fixed in the
post-review pass below.

## Post-review fixes (2026-10-21)

The owner-declared close of Groups B–E came with an audit of the four groups.
Finding → fix:

| Finding | Fix |
|---|---|
| `FeatureController::toggle` validated inline on **both** channels, and the web docblock argued for that | `App\Http\Requests\V1\Feature\ToggleFeatureRequest`, used by both controllers; the docblock now says why it exists |
| The email-change token was read with `$request->query()` on both channels | `App\Http\Requests\V1\User\VerifyEmailChangeRequest`; the rule is `nullable` so a missing token stays the documented 400, not a 422 |
| Three middleware 403s and the docs 401/403 answered `{message, code}` with no `meta` | all five now build through `ApiError::make()` |
| The auth lockout 429 (`LoginThrottle`) bypassed `ApiError` | both JSON branches build through it; `ThrottleJsonResponseTest` asserts `meta` on the limiter's own answer |
| 429 was annotated on 3 of ~50 throttled operations | recorded as a documentation rule instead of 50 identical attributes — `documentation.md` § status table says every throttled route can answer it, and the generated description states the shape and `Retry-After` |
| `ChannelParityTest` reported **56 capabilities / 8 one-sided / 0 undeclared** | unchanged — the two size numbers describe different maps (capabilities vs. route pairs), not drift |
| `GET /v1/health` was invisible to `ApiDocumentationTest` (unnamed route) | the test now asserts its 200/503 pair explicitly |
| Docs drift: wrong unread-count path, three-vs-four unpaginated lists, verify-change exception reason, stale tracker statuses | reconciled in `error-contract.md`, `response-contract.md`, `api-architecture.md`, `task-tracker.md`, `progress.md`, `qa-tracker.md`, `feature-tracker.md` |

**Measured after the fixes (2026-10-21):** full suite **2157 passed / 7628 assertions** (358s), `vendor/bin/pint --test` clean, `scramble:analyze` only the known `HealthCheckResource` warning. The generated document holds 47 paths, `/v1/health` now visible with 200+503, and 429 on the three operations where the limiter is endpoint-specific.

---

## Execution rules

- **Never commit or push.** This document is the deliverable; the branch and
  commits are the owner's call.
- One task at a time, in group order. `PLANNED → IN_PROGRESS → REVIEW → DONE`,
  never skipping `REVIEW`.
- A task that changes an endpoint is not `DONE` until `GET /docs/api.json`
  describes the change and `ApiDocumentationTest` passes.
- Every new permission (`api_docs.view`) lands with the route that checks it, in
  the same commit. A `can()` against an unseeded permission is a 403 for
  everyone, superadmin included.
- No new Composer package. Scramble is installed; promoting it from
  `require-dev` to `require` is the only dependency decision in this phase and
  it needs the changelog + ADR first (D-4). **Done as ADR-025 (2026-10-21).**
- Tests: PHPUnit 12, `test_*` prefix, SQLite `:memory:`. No inline FQCN.
- When the code and `docs/base/` disagree, the doc wins — change the code, or
  file an ADR in `docs/planning/decisions.md`. Do not follow the brief over the
  doc without recording it here (D-1..D-6 are the current instances).

## Verification

```bash
php artisan route:list --path=api          # the surface, by route
php artisan scramble:analyze               # routes Scramble cannot infer
php artisan scramble:export                # writes api.json from config
php artisan test --filter=ApiDocumentationTest
php artisan test                           # full suite
vendor/bin/pint --test
```

## Task index

| Group | Tasks | Gate |
|---|---|---|
| A — Docs interface | P12-A1..A7 | `/docs/api` gated, public/private switch, spec live |
| B — Infrastructure | P12-B1..B6 | typed schemas, one error envelope |
| C — Routes | P12-C1..C8 | parity map complete, every route documented |
| D — Security | P12-D1..D7 | 403/403/429, audit context, bearer scheme |
| E — Tests | P12-E1..E5 | drift gate red when docs are stale |
