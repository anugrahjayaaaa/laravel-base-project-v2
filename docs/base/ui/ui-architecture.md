# UI Architecture

## Core Principle

The UI is a **client** of the application/API. Business logic must NOT live
in Blade views, Vue components, React components, or any UI layer.

## Layering

```
UI (Blade / Vue / React / Mobile)
  ↓
API (Controllers / Resources)
  ↓
Application (Actions / Services)
  ↓
Domain (Models / Events)
```

### Responsibilities

| Layer | Responsibility |
|-------|----------------|
| UI (Blade/Vue/React/Mobile) | Presentation only — render data, capture user input, route calls to the API |
| API (Controller/Resource) | HTTP orchestration + serialization |
| Application (Action/Service) | Business/application logic |
| Domain (Model/Event) | Persistence + domain behavior |

## Key Rules

1. **Business logic does not live in Blade/views.** Any logic beyond simple
   presentation (conditional rendering based on permission, formatting) must
   be in the Action/Service layer and exposed via the API.
2. **Authorization is enforced server-side.** The backend (Policy/Gate) is
   the security boundary. UI permission checks exist for UX/visibility only.
   See [UI Authorization Rule](./ui-authorization.md).
3. **API/backend remains the security boundary.** UI hiding is NOT security.
4. **UI is replaceable.** The Admin UI may use Blade + AdminLTE + Bootstrap.
   It can later be replaced by Vue, React, or a mobile app without rewriting
   the business/domain logic — only the UI layer changes.
5. **The same API serves all clients.** Web (Blade), Vue SPA, React SPA,
   and mobile apps all consume the same versioned API (`/api/v1/...`).

## Admin UI (Default)

The default admin UI uses:
- Blade templates
- AdminLTE 4 + Bootstrap 5.3
- PHP-based rendering (server-side)

This is the default because it requires no JavaScript build pipeline and
works for administrative dashboards. It does NOT preclude a Vue/React
frontend later.

## Design System

A shared design-system contract ensures UI consistency regardless of the
rendering technology. See [design-system.md](./design-system.md).

The design system uses semantic tokens (not brand-specific color values) so
the UI can be re-skinned without changing component structure.

## ADR References

- ADR-001: API-first architecture
- ADR-002: UI-independent core

## Related

- [Design System](./design-system.md)
- [UI Authorization Rule](./ui-authorization.md)
- [Application Components](../architecture/application-components.md)