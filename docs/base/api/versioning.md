# API Versioning

## Strategy

Versioned APIs:
```
/api/v1/...
/api/v2/...  (future)
```

## Code Structure

```
Controllers/Api/V1/
Controllers/Api/V2/
Requests/Api/V1/
Requests/Api/V2/
Resources/Api/V1/
Resources/Api/V2/
```

## Rules

- V1 must remain stable when V2 is introduced.
- Application/domain logic may be shared when behavior is identical.
- When behavior changes substantially, create a separate Action/Service implementation.
- Do not blindly duplicate the entire application for every API version.
- Version relevant application code as needed.

## Versioning Methods

1. **URL Versioning** (primary): `/api/v1/...`
2. **Header Versioning** (optional): `Accept: application/vnd.laravel.v1+json`

URL versioning is the recommended approach for clarity and cacheability.

## Deprecation Policy

- V1 endpoints remain active until explicitly deprecated.
- Deprecation announced at least 2 minor releases before removal.
- Deprecation warnings included in response headers:
  ```
  X-API-Deprecated: version=1.0, sunset=YYYY-MM-DD
  ```

## ADR References

- ADR-009: API versioning