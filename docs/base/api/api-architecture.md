# API Architecture

## Versioned APIs

```
/api/v1/...
/api/v2/...  (future)
```

Version relevant application code:
```
Controllers/Api/V1/
Controllers/Api/V2/
Controllers/Web/V1/
Requests/V1/            # shared by Web and Api — no channel segment
Requests/V2/
Resources/Api/V1/
Resources/Api/V2/
```

- V1 must remain stable when V2 is introduced.
- Application/domain logic may be shared when behavior is identical.
- When behavior changes substantially, create a separate Action/Service implementation.
- Do not blindly duplicate the entire application for every API version.

## Rule: Version the API Contract First; Version Application Logic Only When Behavior Diverges

```
Version the API contract first; version application logic only when behavior
actually diverges.
```

If V1 and V2 share the same business behavior:

```
V1 Controller → shared application service/action
V2 Controller → same shared application service/action
```

If business behavior genuinely differs:

```
V1 Controller → V1-specific application behavior
V2 Controller → new application service/action (where justified)
```

V1 behavior must remain stable when V2 is introduced. Existing V1 clients
must not break.

## Token abilities

`POST /api/v1/auth/login` mints the token with `createToken('auth-token')` and no
ability list, so every token is `['*']`. **That is the decision, not an
oversight.**

A Sanctum token here is a logged-in session in another transport. What the
caller may do is the user's RBAC, evaluated on every request by `can:` on the
route and `authorize()` on the FormRequest — one authority, re-read each time.
Abilities would be a second copy of that authority, written at login and stale
the moment somebody's role changes; `tokenCan()` checks would also have to be
restated on every route, which is the duplication the `can:` rule exists to
prevent. Sanctum's ability feature pays for itself when tokens are delegated to
a third party with a deliberately narrower scope. This API has no such token.

Revisit only if machine or delegated tokens with a subset of a user's authority
are introduced. `SessionResource` publishes `abilities` so a client can see what
it holds; today it is always `["*"]`.

## API Resources

Laravel API Resources are the serialization contract.
- Resources must NOT contain business logic.
- Resources are responsible for: serialization/presentation only.

## API Response Conventions

API response conventions must be consistent and easy for FE/mobile developers to understand.

### Responsibility Chain

| Abstraction | Responsibility |
|-------------|---------------|
| FormRequest | Validation |
| Controller | HTTP orchestration |
| Action/Service | Application/business logic |
| Policy | Authorization |
| Resource | Serialization |

### Error Handling

```json
{
  "message": "The given data was invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "email": ["The email field is required."]
  },
  "meta": { "request_id": "uuid", "timestamp": "2026-10-20T00:00:00+00:00" }
}
```

`meta` is always present and `errors` only on 422 — the full shape, the code
vocabulary and the emitters live in `error-contract.md`. Do not hand-build this
body: `Controller::respondError()` and the exception handler both call
`App\Support\ApiError`.

HTTP semantics:
- 401: Unauthenticated
- 403: Authenticated but forbidden
- 404: Resource does not exist. A feature switched off returns **403**, not 404 — the route exists, the module is unavailable. See [Feature Availability](../features/feature-flags.md)
- Don't expose: stack traces, secrets, internal credentials, infrastructure details.

### Why this shape, not Laravel's default

The framework's skeleton gives `{"data": …}` for a resource, `{"data", "links",
"meta"}` for a paginated collection, and `{"message", "errors"}` for a 422. This
project deliberately differs on three counts: `meta` on **every** response (so
the correlation id is in the body, not only in a header a client may not log), a
machine `code` on **every** error, and pagination inside `data` so a JSON client
is not second-class to one reading `Link` headers.

The cost is stated once: a developer who knows Laravel will guess the framework
shape and be wrong, because none of this is discoverable from the framework
itself. That is why this file and `response-contract.md` exist, and why both are
normative rather than descriptive.

Do **not** "modernise" any of it to the framework default as a cleanup. It is a
contract change for every client and every test — a version, not a refactor
(`versioning.md`).

## API Conventions — the checklist

`api/*` is built from the same eight parts on every endpoint. This list is
normative: an endpoint that skips a line is a defect, and a whole-project audit
starts here rather than from `route:list`.

| # | Part | Where | Rule |
|---|------|-------|------|
| 1 | Route | `routes/api.php` | Inside `Route::prefix('v1')`, named `api.v1.<resource>.<action>`. `feature:<module>` for a gated module, `can:<module>.<ability>` for the act. Authorization lives on the route, never in a controller constructor. |
| 2 | FormRequest | `app/Http/Requests/V1/<Module>/` | `authorize()` when the permission depends on the payload, `rules()` for the allowlist. Controller reads `validated()` only — no `$request->validate()`, no `$request->query()`. |
| 3 | Controller | `app/Http/Controllers/Api/V1/<Module>/` | Extends `App\Http\Controllers\Controller`. Orchestration only: no query building, no DB writes, no audit row. |
| 4 | Action | `app/Actions/V1/<Module>/` | Business logic, and the audit row inside the same transaction. Shared with the web channel — an API-only Action is either a parity gap or a duplicated rule. |
| 5 | Resource | `app/Http/Resources/Api/V1/<Module>/` | Serialization only, with an explicit `@return array{...}` so the generated schema is the shape the code produces. |
| 6 | Response | `Controller::respond()` / `respondError()` | Envelope for success, error body for failure. Never `response()->json()`, never a 4xx status through `respond()`. |
| 7 | Documentation | Scramble | Inference covers routes, FormRequests and Resources. Add `#[Response(status:, type:)]` only where it cannot reach: a hand-built array, or a status produced by `abort()` inside an Action. Every documented status needs an emitter in code. |
| 8 | Test | `tests/Feature/...` | Success shape, refusal from the *sibling* permission (not only from no permission at all), 422, and the invariant the endpoint exists for. |

### Shapes — one source each

Do not restate these in an endpoint docblock; link instead.

- Success `{data, meta}`, named resource keys, `data.pagination` for a paginated
  list → `response-contract.md`
- Error `{message, code, meta}`; `code` is the machine contract →
  `error-contract.md`
- Additive keys are non-breaking, removals are a new version → `versioning.md`

### Deliberate exceptions

An audit that flags these is wrong, not thorough. Each is a decision, with the
reason recorded beside the route.

| Endpoint / shape | Exception | Why |
|---|---|---|
| `GET /api/v1/health` | bare body; controller does not extend the base | the caller is a monitor that cannot log in, and the one meaningful key is `status` |
| `GET` / `PUT` `/api/v1/settings` | `data` is the key→value map, no wrapper | a form re-renders from the response |
| `/notifications/inbox*`, `/profile`, `/sessions`, `/auth/password/change` | no `can:` | self-scoped — "whose record is this" is answered by the token |
| `/users/{user}/request-email-change` | no `can:`; `EmailChangeRequest::authorize()` | the rule is "own account **or** `users.update`", the same OR `cancel-email-change` needs; a single `can:` would lock a user out of changing their own email |
| `/users/{user}/cancel-email-change` | `abort_unless` in the controller | the rule is "own account **or** `users.update`"; `can:` cannot express OR |
| `GET /email/verify-change/{user}` | `signed` + the token check in the Action | the link is the credential: the signature proves it was issued for this change and the token proves it belongs to this pending row. The route sits in the authenticated group, so the holder must be signed in, but no permission applies — requiring one would make the emailed link unusable for the user it was sent to |
| `*/bulk-action` (users, roles) | no `can:` on the route | the permission depends on the requested action; the FormRequest decides |
| `/features/bulk-action` | `can:features.manage` **and** `BulkFeatureRequest::authorize()` | every flag action is the same permission, so unlike users/roles it can be pinned on the route; the FormRequest repeats it for the payload |
| `GET /api/v1/activity-logs/{activity}` | `whereNumber` | `/activity-logs/exports` is one segment shorter and would otherwise be swallowed by `{activity}` |
| `GET /api/v1/features` | list without a `pagination` block | a fixed catalogue of config, not a page of records |

Lists that do not paginate, and why: `response-contract.md` § Collection.

## Auditing conformance

Run this before declaring an API change done. Each row must match its Expected
column — the greps print nothing but the items listed under *Known
non-conformance*, and the one review row is a reading, not a check.

| Check | Command | Expected |
|---|---|---|
| No inline validation | `grep -rn '\$request->validate(' app/Http/Controllers` | no output |
| No raw query reads | `grep -rn '\$request->query(' app/Http/Controllers` | no output; a query parameter belongs in a FormRequest so it is validated and documented |
| No 4xx through the success helper | `grep -rnE 'respond\(.*, ?[45][0-9][0-9]' app/Http/Controllers/Api/V1` | no output; a non-literal status cannot slip through either — `respond()` throws on a 4xx |
| No hand-built JSON | `grep -rn 'response()->json' app/Http/Controllers/Api/V1` | no output |
| No inline FQCN | `vendor/bin/phpunit tests/Arch/NoInlineFqnTest.php` | pass |
| Every capability on both channels | `php artisan test --filter ChannelParityTest` | pass |
| Every documented status has an emitter | review the `#[Response]` set against the code's `abort()` / exception paths — no command proves it yet (E2 adds the per-operation assertions) | every annotated status is reachable |
| No unresolvable schema | `php artisan scramble:analyze` | only the known `HealthCheckResource` warning |
| The spec regenerates | `php artisan scramble:export` | contains the new path |

### Known non-conformance

Real, open, and recorded here so an audit does not rediscover them as new
findings:

- None currently open. (The `pagination` block used to be written out four
  times; `Controller::paginationFor()` is now the one copy, and the inbox uses
  it too.)

## API Documentation

Use Scramble for API documentation generation. Scramble auto-generates
documentation from Laravel routes, Form Requests, and API Resources, so most
endpoints need no annotation at all; `#[Response]` appears only where inference
cannot reach (see item 7 above).

Documentation must include:
- Authentication
- Endpoint
- Method
- URL
- Headers
- Query parameters
- Request body
- Validation rules
- Authorization requirements
- Response schema
- Error response format
- Pagination
- Filtering
- Sorting
- Examples

Do not build a custom documentation system unnecessarily. Use established tools (Scramble).