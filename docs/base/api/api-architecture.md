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
Requests/Api/V1/
Requests/Api/V2/
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
  "errors": {
    "email": ["The email field is required."]
  },
  "code": "VALIDATION_ERROR"
}
```

HTTP semantics:
- 401: Unauthenticated
- 403: Authenticated but forbidden
- 404: Resource/feature intentionally unavailable where appropriate
- Don't expose: stack traces, secrets, internal credentials, infrastructure details.

## API Documentation

Use Scramble for API documentation generation. Scramble auto-generates
documentation from Laravel routes, Form Requests, and API Resources —
annotation-free.

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