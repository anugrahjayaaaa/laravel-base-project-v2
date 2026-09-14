# API Documentation

## Approach

Use Scramble for API documentation generation. Scramble auto-generates
documentation from Laravel routes, Form Requests, and API Resources —
annotation-free.

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

Recommended documentation tool:
- **Scramble** (`dedoc/scramble`) — annotation-free, auto-generates API
  documentation from Laravel routes, Form Requests, and API Resources

## Authentication Documentation

Document how to authenticate:
- Bearer token (Sanctum): `Authorization: Bearer ***
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