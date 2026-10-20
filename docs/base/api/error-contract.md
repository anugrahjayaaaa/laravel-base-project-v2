# API Error Contract

## Shape

Every error answered on `api/*` uses one body:

```json
{
  "message": "Human-readable error message",
  "code": "MACHINE_ERROR_CODE",
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-10-20T00:00:00+00:00"
  }
}
```

`errors` is added on 422 only. `meta` is always present — the same block a
successful response carries, so an error and the call beside it can be
correlated from the body alone rather than from a header a client may not log.

Built in `App\Support\ApiError`, which the exception handler
(`bootstrap/app.php`), `Controller::respondError()` and `FormatsApiErrors` (the
422 a form request throws) all call. Before that class existed each wrote the
`meta` block by hand, which is how an error could arrive without one while the
422 next to it had one.

## HTTP status codes

| Code | Meaning | Emitted by |
|------|---------|-----------|
| 200 | OK | `Controller::respond()` |
| 201 | Created | `respond(..., 201)` on user/role create and register |
| 400 | Bad Request | verification token missing/unsigned (`INVALID_TOKEN`) |
| 401 | Unauthenticated | `AuthenticationException` (`UNAUTHENTICATED`) |
| 403 | Forbidden | authorization failure, feature-gate refusal, bad signature (`FORBIDDEN`, `INVALID_SIGNATURE`) |
| 404 | Not Found | unknown route or missing model (`NOT_FOUND`) |
| 409 | Conflict | `LastSuperadminException` (`LAST_SUPERADMIN`) |
| 419 | CSRF mismatch | `TokenMismatchException` (`CSRF_TOKEN_MISMATCH`) |
| 422 | Validation failed | `ValidationException` (`VALIDATION_ERROR`) |
| 429 | Rate limited | `ThrottleRequestsException` (`RATE_LIMITED`), and the auth lockout path |
| 500 | Server error | the handler's fallback (`SERVER_ERROR`) |
| 502 | Bad gateway | send-test-mail only, when the mail transport refuses the message — success-envelope shape, see the known gap |
| 503 | Service unavailable | `HealthCheckController` when a probe fails — **not** an error envelope, see below |

There is no generic **`CONFLICT`** code: `LAST_SUPERADMIN` is the only 409, and a
documented code with no emitter is a promise the API does not keep. Add the row
when the emitter lands, not before.

**503 is the exception to this document.** The health probe answers 503 with its
own body — `{status, timestamp, checks}` from `HealthCheckResource`, unwrapped,
because a monitor reads the top-level `status` — and it is the only place a 503
is produced. A failing probe is not an error about the request; the request was
fine.

## Error codes

| Code | HTTP | When |
|------|------|------|
| VALIDATION_ERROR | 422 | Form request or cross-field rule failed; `errors` carries the field map |
| UNAUTHENTICATED | 401 | No or invalid token |
| UNVERIFIED_EMAIL | 403 | Credentials correct, address not verified; adds `email` + `verified: false` |
| FORBIDDEN | 403 | Authenticated, not permitted |
| HTTP_ERROR | 4xx | Any other `abort(4xx)` — the status is the framework's, the code is shared |
| NOT_FOUND | 404 | Route or bound model does not exist |
| LAST_SUPERADMIN | 409 | The operation would leave the system with no superadmin |
| RESET_TOKEN_INVALID | 400 | Password reset token expired, used, or unknown |
| CSRF_TOKEN_MISMATCH | 419 | CSRF token missing or stale |
| INVALID_SIGNATURE | 403 | Signed URL presented with a bad signature |
| INVALID_TOKEN | 400 | Verification URL arrived without a token at all |
| RATE_LIMITED | 429 | A named limiter refused the request |
| SERVER_ERROR | 500 | Unhandled exception |

## Order matters: specific before generic

`NotFoundHttpException` and `ThrottleRequestsException` both extend
`HttpException`, so a generic `HttpException && status between 400 and 499`
branch placed above them swallows both — and it did. Every route-level 404
answered `HTTP_ERROR`, every framework-level 429 answered `HTTP_ERROR`, and both
codes were documented while nothing could emit them. The handler now checks the
specific exception classes first and lets the generic branch catch only what is
left. `ApiErrorEnvelopeTest` pins `NOT_FOUND` on an unknown route so it cannot
regress silently.

## `APP_DEBUG` does not change the shape

The handler used to sit inside `! config('app.debug')`, so the contract only
existed when debug was off: a deployment that turned debug on answered in
Laravel's default shape, and the tests passed because `.env.testing` sets
`APP_DEBUG=false` — the branch a production deployment may not use.

Now the envelope is emitted unconditionally, and debug **adds** one key:

```json
{ "message": "...", "code": "NOT_FOUND", "meta": {...},
  "debug": { "exception": "Symfony\\...\\NotFoundHttpException", "message": "..." } }
```

Traces stay in the log (`report()` still runs); a client that branches on `code`
works in every environment. `ApiErrorEnvelopeTest` asserts the key set is
identical with debug on and off, minus `debug`.

## Known shape gap: refusals produced by a controller

A handful of endpoints answer a refusal through `respond($message, 4xx|502)`,
which is the *success* envelope with an error status: the message lands in
`data.message` and there is no `code`. The sites, all measured:

| Endpoint | Status | Why it is success-shaped |
|----------|--------|--------------------------|
| `POST /auth/login` (locked/inactive) | 403 | pinned by `LoginControllerTest`, which reads `data.message` |
| `POST /auth/login` (lockout) | 429 | additionally carries `retry_after_seconds` beside the message |
| `POST /auth/register` (registration off) | 404 | same 404 the web route gives |
| `POST /auth/email/resend` (mail disabled / already verified / throttled) | 403 / 422 / 429 | the resend action's own refusals |
| `GET /email/verify-change/{user}/{token?}` | 400 | verification-link refusal (missing or expired token) |
| `POST /notifications/test-mail` (relay down) | 502 | the sentence the web page shows, reused |

They are pinned that way by tests, so changing them is a client-visible decision
per endpoint rather than a sweep. Group C (P12-C1, P12-C6) owns it: each
endpoint's code vocabulary is decided while its documentation is written.

Everything raised as an exception already follows this document; the gap is only
in controllers that return a refusal directly.

## 422: how it gets this shape

`App\Http\Requests\BaseFormRequest` extends Laravel's `FormRequest` and uses
`App\Concerns\FormatsApiErrors`, which overrides `failedValidation()`. **Every
concrete Request extends that base**, so no endpoint opts in and none can be
left behind:

```
app/Http/Requests/
├── BaseFormRequest.php        the contract
└── V1/{Domain}/…              extends the base
```

For a web request the same override redirects back with errors and old input
instead of returning JSON — the branch is on `request()->expectsJson()`.

This shape was documented here long before the code produced it.
`FormatsApiErrors` was applied per-Request and only the four Auth requests
carried it: the other seventeen returned Laravel's default `{message, errors}`,
so the same validation failure produced two different bodies depending on the
endpoint, with no test covering the difference. Moving the contract to the base
class removed the possibility rather than fixing the seventeen.

Enforced by:

- `tests/Arch/RequestVersioningTest` — every Request extends the base
- `tests/Feature/Api/V1/ValidationErrorContractTest` — the body above, on the
  User and Role endpoints
- `tests/Feature/CrossCutting/ApiErrorEnvelopeTest` — `meta` present, debug adds
  a key without changing the shape

## Security Considerations

Never expose in error responses:

- Stack traces (except under `APP_DEBUG`, where `debug.exception` names the
  class — a deliberate developer affordance, not a deployment default)
- File paths
- Database queries
- Environment variables
- Secret keys or credentials
- Internal IP addresses

Log full errors server-side; return generic messages to clients.
