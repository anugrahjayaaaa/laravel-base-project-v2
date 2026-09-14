# ADR-007: API Documentation Strategy

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: API V1 (Phase 12 `API-003`)

## Context

The Base Project requires API documentation that:
- Is generated from the actual code (routes, Form Requests, API Resources) to
  minimize drift
- Supports OpenAPI specification
- Provides an interactive UI for frontend/mobile developers
- Documents authentication (Sanctum bearer tokens), error responses,
  pagination, filtering, sorting
- Is versioned alongside the API

## Decision

Use `knuckleswtf/scribe` for API documentation generation.

## Alternatives Considered

- **`dedoc/scramble`**: Annotation-free, generates OpenAPI from routes and
  Form Requests. A strong alternative. The Base Project's
  `docs/base/api/documentation.md` §Tools explicitly lists Scribe as the
  **primary recommended** tool ("auto-generates OpenAPI docs from Laravel
  annotations"), and Scribe was selected for its broader adoption, mature
  ecosystem, and deeper control over generated documentation.
- **Swagger/Passport-docs self-hosted**: Requires manual OpenAPI spec
  maintenance — drifts from code. Rejected.
- **Redoc (static)**: A UI renderer only — no generation capability. Not
  sufficient alone; Scribe can generate the spec Redoc renders.
- **Custom documentation system**: Rejected per documentation.md: "Do not
  build a custom documentation system unnecessarily."

## Why This Decision

- Scribe extracts documentation directly from Laravel routes, Form
  Requests (validation rules, examples), and API Resources — minimizing
  drift between code and docs.
- Scribe natively supports Sanctum bearer-token authentication documentation.
- Scribe produces an interactive Swagger UI out of the box.
- Scribe is the documented recommendation in `docs/base/api/documentation.md`.

## Consequences

- Scribe is a **dev dependency** — not loaded in production.
- Documentation regenerated via `php artisan scribe:generate` — must be run
  after route/Resource/Request changes.
- Documentation available at `/api/docs/v1` (versioned).
- Form Requests serve as the single source of truth for validation rules —
  Scribe reads from them, so documentation stays DRY.

## Security Implications

- Scribe docs should be gated behind `auth:sanctum` or restricted to
  non-production environments — the docs expose API structure.
- Example responses must not contain real secrets or tokens.

## Maintenance Implications

- Scribe version must track Laravel/PHP compatibility.
- Re-run `scribe:generate` before releases.
- Scribe annotations are optional — most docs come from code analysis.

## Reversal / Replacement

- Scribe is documentation-only (dev dependency). Removing it has no
  production runtime impact.
- Replacing with Scramble: swap the dev dependency, run Scramble's
  generation command, update the doc-publishing pipeline. No application
  code changes required.
