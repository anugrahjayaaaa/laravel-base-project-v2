# API Documentation

## Approach

Use an OpenAPI-compatible API documentation approach.

Do not build a custom documentation system unnecessarily.

## Documentation Includes

Every documented endpoint must include:
- Authentication requirements
- Endpoint path
- HTTP method
- URL
- Headers
- Query parameters
- Request body schema
- Validation rules
- Authorization requirements
- Response schema
- Error response format
- Pagination
- Filtering
- Sorting
- Examples

## Tools

Recommended documentation generators:
- **Scribe** — auto-generates OpenAPI docs from Laravel annotations
- **Swagger/OpenAPI** — standard specification
- **Redoc** — OpenAPI-powered API docs UI

## Authentication Documentation

Document how to authenticate:
- Bearer token (Sanctum): `Authorization: Bearer <token>`
- Session cookie (Web): CSRF token + cookie-based auth
- Token expiration and refresh flow

## Live Examples

Each endpoint should have:
- Request example (curl, HTTP)
- Success response example
- Error response examples
- Query/filter/sort examples

## Versioning

Documentation is versioned alongside the API:
- `/api/docs/v1/` — V1 documentation
- `/api/docs/v2/` — V2 documentation (future)