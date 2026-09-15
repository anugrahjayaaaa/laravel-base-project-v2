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

AdminLTE is the **initial/default presentation template** — it is
**replaceable**. The Base Project architecture must remain UI-independent
(ADR-002, ADR-019). AdminLTE is not a dependency of business logic, services,
models, policies, or authorization.

This is the default because it requires no JavaScript build pipeline and
works for administrative dashboards. It does NOT preclude a Vue/React
frontend later.

### Application Shell

Laravel Base Project v2 is a reusable full-stack foundation. Each applicable
feature must provide three layers: Backend Web, Backend API, and AdminLTE UI.
The API must remain independently usable — another project can use the
backend/API without the provided AdminLTE UI.

The AdminLTE UI layer provides a shared application shell that must NOT be
duplicated per feature. The shell consists of:

**Header:**
- Application/feature search
- Notification icon
- User/profile dropdown (profile details, logout)
- Dark/light theme toggle (defaults to system preference, manual override)

**Sidebar:**
- Application logo/icon linking to dashboard
- Grouped navigation menus with logical grouping
- Active menu state and expand/collapse behavior
- Hide/collapse capability and responsive mobile behavior

**Main content:**
- Consistent page layout with feature-specific content
- Responsive behavior
- Consistent page title/breadcrumb/action area where appropriate

**Footer:**
- Shared reusable footer partial/component

### UI Component Consistency

Shared UI conventions defined in [Design System](./design-system.md). Common
elements include: buttons (variants), forms, inputs, select/dropdown,
date/date-range inputs, search bars, tables, pagination, badges/status
indicators, alerts/notifications, empty states, loading states, error states,
dropdown menus, action menus, and confirmation modals.

Do not invent separate visual patterns for individual features when a shared
component/convention already exists.

### Confirmation Modal

One reusable confirmation-modal component/partial with configurable variants
(danger/warning/info) instead of separate modal implementations per feature.

Actions that require confirmation:
- Soft delete / permanent delete / restore
- Lock / unlock / activate / deactivate
- Feature flag changes
- Resend password / resend verification email
- Other sensitive/destructive actions

### Three-Layer Feature Standard

For applicable features:

```
Feature
├── Backend Web          (web routes + Blade views)
├── Backend API          (API routes + Resources)
├── AdminLTE UI          (Blade layouts + partials + components)
├── Authorization        (Policies + Form Request authorize)
├── Audit/observability  (audit records + application logs)
├── Tests                (Feature + Unit)
└── Documentation        (behavior spec)
```

API and UI are intentionally separated so consumers can use the API with
another frontend technology, or use the provided AdminLTE UI and routing.
A feature is not complete if only the API or only the UI exists (unless
explicitly API-only or infrastructure-only).

### Soft Delete / Trash / Permanent Delete

Expected lifecycle: Active record → Soft Delete → Deleted/Trash → Restore OR
Permanent Delete. Permanent delete is only available from the deleted state;
never a direct action from the active state. Destructive actions require
confirmation. Applicable actions should support bulk operations (bulk soft
delete, bulk restore, bulk permanent delete).

See [Soft Delete Strategy](../data/soft-delete.md) for the full convention.

### Feature Flags

Phase 1 includes a feature-flag package foundation (installation +
configuration + initial reusable UI conventions). See
[Feature Availability](./feature-flags.md).

### AdminLTE Installation Strategy

AdminLTE must be obtained from the official release ZIP and vendored into
`public/vendor/adminlte/` — **not** installed via npm. See
[AdminLTE Setup](./ui-adminlte-setup.md) for the full strategy (UI-001).

## Design System

A shared design-system contract ensures UI consistency regardless of the
rendering technology. See [design-system.md](./design-system.md).

The design system uses semantic tokens (not brand-specific color values) so
the UI can be re-skinned without changing component structure.

## ADR References

- ADR-001: API-first architecture
- ADR-002: UI-independent core
- ADR-019: AdminLTE as replaceable UI template

## Related

- [Design System](./design-system.md)
- [UI Authorization Rule](./ui-authorization.md)
- [Application Components](../architecture/application-components.md)