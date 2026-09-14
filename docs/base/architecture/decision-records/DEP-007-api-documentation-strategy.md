# DEP-007: API Documentation Strategy

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: API V1 (Phase 12 `API-003`)

## Context

The Base Project requires API documentation that:

- Is generated from the actual code (routes, Form Requests, API Resources) to
  minimize drift
- Produces an OpenAPI-compatible specification (output format, not a driving
  concern)
- Provides an interactive UI for frontend/mobile developers
- Documents authentication (Sanctum bearer tokens), error responses,
  pagination, filtering, sorting
- Is versioned alongside the API

## Decision

Use `dedoc/scramble` for API documentation generation.

## Alternatives Considered

- **Swagger/Passport-docs self-hosted**: Requires manual OpenAPI spec
  maintenance — drifts from code. Rejected.
- **Redoc (static)**: A UI renderer only — no generation capability. Not
  sufficient alone.
- **Custom documentation system**: Rejected per documentation.md: "Do not
  build a custom documentation system unnecessarily."
- **`openapi-php` / manual annotations**: Would require manual annotation of
  every endpoint — high maintenance and drift risk. Rejected.

## Why This Decision

- Scramble extracts documentation directly from Laravel routes, Form
  Requests (validation rules, examples), and API Resources — minimizing
  drift between code and docs.
- Scramble natively supports Sanctum bearer-token authentication documentation.
- Scramble produces an interactive documentation UI out of the box.
- Scramble is the documented recommendation in `docs/base/api/documentation.md`.
- Scramble's generated spec may use OpenAPI terminology — OpenAPI is the
  output/format, not a competing documentation package.

## Consequences

- Scramble is a **dev dependency** — not loaded in production.
- Documentation regenerated via `php artisan scramble:docs` — must be run
  after route/Resource/Request changes.
- Documentation available at `/api/docs/v1` (versioned).
- Form Requests serve as the single source of truth for validation rules —
  Scramble reads from them, so documentation stays DRY.

## Security Implications

- Scramble docs should be gated behind `auth:sanctum` or restricted to
  non-production environments — the docs expose API structure.
- Example responses must not contain real secrets or tokens.

## Maintenance Implications

- Scramble version must track Laravel/PHP compatibility.
- Re-run `scramble:docs` before releases.
- Scramble is annotation-free — most docs come from code analysis.

## Reversal / Replacement

- Scramble is documentation-only (dev dependency). Removing it has no
  production runtime impact.
- Replacing with another generator: swap the dev dependency, run the
  replacement's generation command, update the doc-publishing pipeline.
  No application code changes required.
