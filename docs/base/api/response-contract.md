# API Response Contract

## Envelope

Every successful response on `api/*` is built by `Controller::respond()` and
carries:

```json
{
  "data": { "message": "Human-readable confirmation" },
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-10-20T00:00:00+00:00"
  }
}
```

`respond($message)` with no `$data` puts the message in `data.message`.
`respond('', 200, $data)` puts `$data` under `data` — the empty message is
dropped rather than sent as an empty string, which is why list endpoints have no
`data.message`.

The `meta` block comes from `App\Support\ApiError::meta()`, the same method the
exception handler uses, so a success and the failure beside it carry the same
correlation keys. See `error-contract.md`.

## One base controller

There is exactly one `App\Http\Controllers\Controller`, and every API controller
extends it. No `Api/V1/BaseApiController`.

Why: a second base class would hold `respond()`/`respondError()` and nothing
else, and two controllers that answer the same way would still not share a byte
of behaviour — the split would be by *namespace*, not by responsibility. The
moment a helper is only reachable from half the API is the moment the other half
re-implements it. That already happened once: `UserStateController` carried a
private `success()` that hand-built the envelope, byte for byte identical to
`respond()`. It is gone.

The base controller holds no audit helper either. Audit rows are written by the
action performing the mutation (`Auditable::audit()`), never by the channel that
triggered it.

## Single resource

```json
{
  "data": {
    "user": { "id": 1, "name": "Example", "email": "…", "is_active": true }
  },
  "meta": { "request_id": "…", "timestamp": "…" }
}
```

The resource is **named** (`user`, `role`, `permission`, `activity`, `export`)
rather than anonymous. A client reads `data.user.id`; if the key vanished in a
future version the missing key is an error the client can see, where a bare
`data.id` would silently become a different resource's id.

Every resource class carries an explicit `@return array{...}` shape, so the
schema in `/docs/api` is the shape the code actually produces — optional keys
(`whenCounted`) are marked `?`, not left as `mixed`.

## Collection

```json
{
  "data": {
    "users": [ { "id": 1 }, { "id": 2 } ],
    "pagination": {
      "current_page": 1,
      "last_page": 10,
      "per_page": 10,
      "total": 100
    }
  },
  "meta": { "request_id": "…", "timestamp": "…" }
}
```

Facts a client can rely on:

- The pagination block lives in `data.pagination` and has exactly four keys:
  `current_page`, `last_page`, `per_page`, `total`. No `from`/`to` — a client
  that needs them can compute them from `per_page` and `current_page`.
- **No `Link` header.** Paging is in the body, so a client that inspects only
  JSON is not second-class.
- `page` is the framework default; `per_page` is validated per endpoint with a
  floor of 5 and a ceiling of 100. Defaults: 10 for users, roles and audit,
  30 for the notification inbox.
- Collections are **not** nested paginator objects. The resources are plain
  arrays and the totals sit beside them, so there is one place to read a page
  count, not two disagreeing ones.
- The notification inbox is the one list without a `pagination` block: it
  returns the page's rows plus `unread_count`, and a client that needs to know
  whether anything changed polls `GET /api/v1/notifications/unread-count`
  (`unread_count` + `latest_id`) rather than walking pages.

## Responses that are not envelopes

Two endpoints deliberately do not use the envelope, because they are not
resources:

- **Health** (`GET /api/v1/health`) — `{status, timestamp, checks}` at the top
  level, unwrapped, 200 or 503. A monitor reads `status`, and an envelope would
  put the one meaningful key one level deeper for no gain.
- **System settings** — `data` is the key→value map itself
  (`SystemSetting::getAll()`), not a `settings` wrapper. Both the read and the
  update answer with the full map after the write, so a form can re-render from
  the response instead of a follow-up GET.

## Query parameters

Names are per endpoint, validated by a `FormRequest` — never `filter[field]`
arrays, and never unvalidated `$request->query()` reads.

| Endpoint | Parameters |
|----------|-----------|
| `GET /api/v1/users` | `search`, `status`, `sort`, `direction`, `per_page` |
| `GET /api/v1/roles` | `search`, `sort`, `direction`, `per_page`, `trashed` |
| `GET /api/v1/audit` | `search`, `event`, `source`, `causer_id`, `subject_type`, `date_from`, `date_to`, `sort`, `direction`, `per_page` |

Sorting is always two keys: `sort` (a whitelisted column) plus `direction`
(`asc`|`desc`). An unknown `sort` is a 422, not a silent fallback — a client that
asks for an unsupported order should be told, since a silently different order
looks like missing data.

## Errors

See `error-contract.md`. `Controller::respondError()` builds the documented
error body; use it instead of `respond($message, 4xx)`, which produces the
success envelope with an error status and no `code`.

## Versioning

See `versioning.md`. Additive keys are non-breaking; removing or renaming a key,
or changing its type, is a new version.
