# API Documentation

## Approach

Use Scramble for API documentation generation. Scramble auto-generates
documentation from Laravel routes, Form Requests and API Resources —
annotation-free.

Do not build a custom documentation system unnecessarily.

## Where it lives

| Surface | URL | What it is |
|---|---|---|
| UI | `/docs/api` | Stoplight Elements view over the generated document |
| Document | `/docs/api.json` | The OpenAPI 3 document itself |
| Export | `php artisan scramble:export` | Writes `api.json` to the export path in `config/scramble.php` |

The document covers the routes under `/api` — this project's versioned surface,
`/api/v1/*`. Web routes are not documented: they are Blade pages, not a contract.

## Availability and access

Scramble is a **dev dependency** (`require-dev`). Under
`composer install --no-dev` the package is absent and the two routes do not
exist at all — there is no documentation surface in that deployment, rather than
a documentation surface nobody can open. This is the DEP-007 decision;
promoting it to `require` is a dependency change and needs a changelog entry and
an ADR first.

Where the package is installed, one gate decides who may read the docs, and the
operator chooses which side of it applies:

| `api_docs_public` (system setting) | Who can open `/docs/api` |
|---|---|
| **off** (default, seeded) | A signed-in user holding `api_docs.view`, or a superadmin via `Gate::before` |
| **on** | Anyone who can reach the URL — no session, no permission |

The switch lives on `/settings` (card *API Documentation*) and is a normal
`SystemSetting` row, so it can be changed without a deploy and is audited like
every other setting. Off is the default because the document names every
endpoint, field and status code — the map an attacker would otherwise have to
build. Treat `api_docs.view` as a disclosure permission.

The guard is `App\Http\Middleware\RestrictApiDocsAccess`, not Scramble's
`RestrictedDocsAccess`. The vendor middleware is
`environment('local') || Gate::allows('viewApiDocs')`; its first half is a bypass
that would ignore `api_docs_public` on a local install, so `config/scramble.php`
swaps in the app's own middleware. The authorization answer itself is the
`viewApiDocs` gate, defined in `AuthServiceProvider::configureApiDocsGate()`:

```php
api_docs_public  ||  $user?->can('api_docs.view')
```

Nullable user on purpose — the docs route carries no `auth` middleware, and in
public mode a guest has to pass.

Refusals: a guest on `/docs/api` is redirected to the login page (there is a form
to fill in); a guest on `/docs/api.json` gets `401` in the JSON envelope, because
a machine-readable spec has no login form to be redirected to; a signed-in user
without the permission gets `403`.

The sidebar entry *API Documentation* reads the same gate, so the link and the
route cannot disagree: public mode shows it to everyone, private mode to
permission holders only. With `--no-dev` the route does not exist and the entry
drops (the menu composer checks `Route::has`).

## What the document must contain

Every documented endpoint must include:

- Authentication requirements and the `Authorization: Bearer` shape
- Endpoint path and HTTP method
- Headers
- Query parameters, with types and allowlisted values
- Request body schema and validation rules
- Authorization requirement (`can:*`, `feature:*`)
- Response schema
- Error response format
- Pagination, filtering and sorting where the endpoint offers them
- Examples

Status codes are part of the contract, not decoration. An endpoint states every
code it can return — **200, 201, 400, 401, 403, 404, 409, 422, 429, 500** — but
the subset is decided by reachability, not by a blanket rule:

| Code | Documented when |
|---|---|
| 401 | The route carries `auth:sanctum` |
| 403 | The route carries a `can:` gate (or a FormRequest that authorizes) |
| 422 | The action takes a FormRequest (or validates input inline) |
| 429 | Any route behind a `throttle:` limiter — which is every route in the authenticated group, via `throttle:user-state-actions` |
| 500 | Always reachable through the handler fallback; covered per status in `ApiErrorEnvelopeTest` |

429 is the one status not repeated per operation. Annotating fifty routes with
the same refusal would pad the document without telling a client anything it
cannot read once: the body is always `RATE_LIMITED`, and the wait arrives in the
`Retry-After` header (the auth lockout path additionally returns
`retry_after_seconds` in the body). Only limiters that are endpoint-specific —
login, resend-verification, the audit export pair — carry their own annotation.
`ApiDocumentationTest` therefore does not require a 429 on every throttled
route, and `docs/base/api/error-contract.md` is the authority for the body.

The public auth routes, the health probe and input-less reads cannot answer all
four, and `ApiDocumentationTest` asserts only what each route can actually
return. The envelope for every documented error is
`docs/base/api/error-contract.md`.

## Authentication

The API authenticates with a Sanctum bearer token:

```
Authorization: Bearer <token>
```

Tokens are issued by `POST /api/v1/auth/login` and revoked by
`POST /api/v1/auth/logout` (this device) and `POST /api/v1/auth/logout-all`.

**Bearer scheme:** `config/scramble.php` enables
`MiddlewareAuthSecurityStrategy`, which reads the route middleware rather than a
hand-kept list. Every route behind `auth` / `auth:*` inherits the bearer scheme
published in `components.securitySchemes`, so `/docs/api` renders the Authorize
box; a route without it is marked `security: []` (public) instead of asking for
a token it ignores. `ApiDocsUiRenderTest` pins both halves.

## Versioning

The API is URL-versioned: `/api/v1/...` (ADR-009, `versioning.md`).

The version string in the published document comes from `API_VERSION`, which
defaults to `v1`. It describes the whole `/api` surface, so a future `/api/v2`
is a second document (`Scramble::registerApi()`), not a second URL on this one.

## Verifying the document

```bash
php artisan scramble:analyze          # routes Scramble could not infer
php artisan scramble:export           # writes api.json from config
php artisan test --filter=ApiDocumentationTest
php artisan test --filter=ApiDocsUiRenderTest
```

`ApiDocsUiRenderTest` pins the gate: an admin opens the UI and the document, a
plain user and a guest are refused, and the published version is `v1`.

`ApiDocumentationTest` is the drift gate. It reads `/docs/api.json` and asserts:

- every route named `api.v1.*` appears as a path + method (Scramble strips the
  documented `api` prefix, so `api/v1/users` is `/v1/users`);
- every `auth:sanctum` operation documents 401;
- every `can:` operation documents 403;
- every operation taking a FormRequest documents 422;
- the bearer scheme and global `security` are declared;
- no operation documents a status outside the contract table.

It catches a route that exists but is **absent** from the document. A route
deleted from `routes/api.php` cannot turn it red: the document is generated per
request, so it stops listing the route too. A checked-in spec would be the only
way to gate the other direction, and that is a second source of truth this
project rejected (see § Approach).
