# API Error Contract

## HTTP Status Codes

| Code | Meaning | When |
|------|---------|------|
| 200 | OK | Successful read |
| 201 | Created | Resource created |
| 204 | No Content | Resource deleted (no body) |
| 400 | Bad Request | Malformed request, invalid params |
| 401 | Unauthenticated | No/invalid auth credentials |
| 403 | Forbidden | Authenticated but not authorized |
| 404 | Not Found | Resource doesn't exist |
| 409 | Conflict | Business rule violation |
| 422 | Validation Error | Failed form validation |
| 429 | Too Many Requests | Rate limited |
| 500 | Server Error | Internal server error |
| 503 | Service Unavailable | Maintenance/temporary |

## Error Response Format

```json
{
  "message": "Human-readable error message",
  "code": "MACHINE_ERROR_CODE",
  "errors": {
    "field": ["Error detail 1", "Error detail 2"]
  },
  "meta": {
    "request_id": "uuid",
    "timestamp": "2024-01-01T00:00:00Z"
  }
}
```

## How a 422 gets this shape

`App\Http\Requests\BaseFormRequest` extends Laravel's `FormRequest` and applies
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

This shape was documented here long before the code produced it. `FormatsApiErrors`
was applied per-Request, and only the four Auth requests carried it: the other
seventeen returned Laravel's default `{message, errors}`, so the same validation
failure produced two different bodies depending on the endpoint, with no test
covering the difference. Moving the contract to the base class removed the
possibility rather than fixing the seventeen.

Enforced by:

- `tests/Arch/RequestVersioningTest` — every Request extends the base
- `tests/Feature/Api/V1/ValidationErrorContractTest` — the body above, on the
  User and Role endpoints

When adding a non-validation error path, return the same envelope: `code` plus
`meta.request_id` and `meta.timestamp`, matching `Controller::respond()`.

## Standard Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| VALIDATION_ERROR | 422 | Form validation failed |
| UNAUTHENTICATED | 401 | Authentication required |
| FORBIDDEN | 403 | Insufficient permissions |
| NOT_FOUND | 404 | Resource not found |
| CONFLICT | 409 | Business rule violation |
| RATE_LIMITED | 429 | Too many requests |
| SERVER_ERROR | 500 | Internal server error |
| SERVICE_UNAVAILABLE | 503 | Service temporarily unavailable |

## Security Considerations

Never expose in error responses:
- Stack traces
- File paths
- Database queries
- Environment variables
- Secret keys or credentials
- Internal IP addresses
- Debug information

Log full errors server-side. Return generic messages to clients.