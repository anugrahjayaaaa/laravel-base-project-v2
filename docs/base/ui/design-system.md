# Design System

> Semantic design tokens only. No brand-specific color values are prescribed
> here — the visual identity is deployment-specific. The UI can be re-skinned
> without changing component structure.

## Design Tokens

Use semantic tokens (not raw hex values) in all UI components. This allows
the UI to migrate from Blade/AdminLTE to Vue/React without restyling every
component.

### Color Tokens

| Token | Purpose |
|-------|---------|
| `primary` | Primary brand/action color |
| `secondary` | Secondary action color |
| `success` | Success/state-positive color |
| `warning` | Warning/caution color |
| `danger` | Error/destructive color |
| `info` | Informational color |
| `surface` | Background surface |
| `surface-alt` | Alternate background (cards, etc.) |
| `text` | Primary text color |
| `text-muted` | Secondary/de-emphasized text |
| `border` | Border/divider color |
| `overlay` | Modal/overlay background |

### Typography Tokens

| Token | Purpose |
|-------|---------|
| `font-family-base` | Base font family |
| `font-size-base` | Base font size |
| `font-size-sm` | Small text |
| `font-size-lg` | Large text |
| `font-weight-normal` | Normal weight |
| `font-weight-medium` | Medium weight |
| `font-weight-bold` | Bold weight |

### Spacing Tokens

| Token | Purpose |
|-------|---------|
| `spacing-xs` | Extra small gap |
| `spacing-sm` | Small gap |
| `spacing-md` | Medium gap (standard) |
| `spacing-lg` | Large gap |
| `spacing-xl` | Extra large gap |

### Border Tokens

| Token | Purpose |
|-------|---------|
| `border-radius-sm` | Small border radius |
| `border-radius-md` | Medium border radius (standard) |
| `border-radius-lg` | Large border radius |
| `border-width-thin` | Thin border |
| `border-width-thick` | Thick border |

## Components

### Buttons

| Variant | Token |
|---------|-------|
| Primary | `primary` background, white text |
| Secondary | `secondary` background, white text |
| Success | `success` background, white text |
| Warning | `warning` background, dark text |
| Danger | `danger` background, white text |
| Outline | transparent background, token-colored border + text |
| Ghost | transparent background, token-colored text (on hover) |

### Forms

| Element | Token |
|---------|-------|
| Input border | `border` |
| Input focus | `primary` |
| Input error | `danger` |
| Label | `text` |
| Help text | `text-muted` |
| Error message | `danger` |

### Tables

| Element | Token |
|---------|-------|
| Header background | `surface-alt` |
| Row border | `border` |
| Row hover | `surface-alt` |
|| Status badge | `success`/`warning`/`danger`/`info` |

### Table Conventions

Shared table conventions:
- Columns sortable where meaningful (consistent UI pattern)
- Bulk selection supported where applicable
- Bulk actions exposed consistently
- Pagination follows shared convention
- Search/filter controls follow shared UI convention

### Table Actions Column

Action buttons in tables:
- Wrapper: `d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap`
- Button size: `btn-sm` or `px-2 py-1` for compact fit
- Wrap to vertical stack on mobile (`flex-wrap`), horizontal on desktop (`flex-md-nowrap`)

### Badges

| Variant | Token |
|---------|-------|
| Success | `success` |
| Warning | `warning` |
| Danger | `danger` |
| Info | `info` |
| Neutral | `text-muted` |

### Alerts

| Variant | Token |
|---------|-------|
| Success | `success` surface, `success` icon |
| Warning | `warning` surface, `warning` icon |
| Danger | `danger` surface, `danger` icon |
| Info | `info` surface, `info` icon |

### Cards

| Element | Token |
|---------|-------|
| Background | `surface` |
| Border | `border` |
| Header background | `surface-alt` |

### Modals

| Element | Token |
|---------|-------|
|| Overlay | `overlay` |
|| Dialog background | `surface` |
|| Border | `border` |
|| Dialog header | `surface-alt` |

### Confirmation Modal Convention

One reusable confirmation-modal component with configurable variants
(danger/warning/info). Actions requiring confirmation: soft delete, permanent
delete, restore, lock, unlock, activate/deactivate, feature flag changes,
resend password, resend verification email, and other sensitive/destructive
actions. Do not create separate modal implementations per feature.

See [UI Architecture](./ui-architecture.md) § Confirmation Modal.

### Navigation

| Element | Token |
|---------|-------|
| Sidebar background | `surface` |
| Nav item active | `primary` |
| Nav item hover | `surface-alt` |
| Breadcrumb separator | `text-muted` |

### Pagination

|| Element | Token |
||---------|-------|
|| Active page | `primary` |
|| Hover page | `surface-alt` |
|| Border | `border` |

### Navigation Tabs (Status Filter)

Shared nav-pills conventions for index page status filters:
- Container: `nav nav-pills flex-nowrap overflow-auto` — horizontal scroll on mobile
- Link passive: `nav-link text-secondary fw-medium px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent`
- Link active: `nav-link active fw-semibold text-primary px-3 py-2 d-flex align-items-center gap-2 border-0 border-bottom border-primary border-2 bg-transparent`
- Counter badge passive: `badge rounded-pill bg-secondary-subtle text-secondary`
- Counter badge active: `badge rounded-pill bg-primary text-white`

## Consistency Rules

- Use the same token set across all UI implementations (Blade, Vue, React).
- Do not hardcode hex values in components — always use semantic tokens.
- When migrating UI technology, only the token-to-CSS mapping changes;
  component structure and markup stay the same.

## States

| Component State | Visual Treatment |
|-----------------|------------------|
| Default | Standard token styling |
| Hover | Slight background/lightness shift (`surface-alt` or opacity) |
| Active/focus | `primary` or `border` focus ring |
| Disabled | Reduced opacity (typically 50%) |
| Error/Invalid | `danger` border + error message |
| Loading | Spinner or skeleton (using `surface-alt` placeholder) |
| Empty | Centered message, muted text |
| Success | `success` color + icon |

## Responsive Behavior

- Mobile: sidebar hidden with open button; content full-width.
- Tablet: sidebar collapsible.
- Desktop: sidebar persistent.
- Breakpoints are defined by the CSS framework (Bootstrap 5.3 grid).

## Accessibility Basics

- All interactive elements must have accessible labels.
- Color is not the only indicator of state (use text/icons too).
- Focus states must be visible.
- Form inputs must have associated labels.
- Error messages must be programmatically associated with their field.

## ADR References

- ADR-002: UI-independent core
- ADR-001: API-first architecture

## Page Skeleton Templates

> Concrete HTML skeletons for all Admin pages. Every new view must match
> one of these exactly — no ad-hoc layout drift.

### 1. Content Header & Breadcrumbs

```html
<div class="content-header mb-3">
    <div class="d-flex justify-content-between align-items-start w-100">
        <div>
            <h1 class="page-title fw-bold">Page Title</h1>
            <p class="page-description text-muted fs-7 mb-0">
                <a href="..." class="text-muted text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back to Module List</a>
            </p>
        </div>
        <ol class="breadcrumb float-sm-end mb-0">
            <li class="breadcrumb-item"><a href="...">Module</a></li>
            <li class="breadcrumb-item active">Page</li>
        </ol>
    </div>
</div>
```

### 2. Index Page

```html
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-pills gap-2 p-0 m-0">...</ul>
        <a href="..." class="btn btn-primary d-inline-flex align-items-center gap-2">
            <i class="bi bi-plus-lg"></i> Create
        </a>
    </div>
    <div class="card-body p-4">
        <!-- filters, table, pagination -->
    </div>
</div>
```

### 3. Create & Edit Form

```html
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <h5 class="card-title mb-0 fw-semibold">Section Title</h5>
    </div>
    <form method="POST" action="...">
        @csrf
        <div class="card-body p-4">...</div>
        <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
            <a href="..." class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-x-circle"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-check-lg"></i> Save
            </button>
        </div>
    </form>
</div>
```

### 4. Modal (#confirmModal)

```html
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold"><i class="bi bi-exclamation-triangle me-2"></i>Title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">Message</div>
            <div class="modal-footer bg-body-tertiary border-top py-2 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmAction">Confirm</button>
            </div>
        </div>
    </div>
</div>
```

### 5. Form Inputs & Validation

```html
<div class="mb-3">
    <label for="field" class="form-label">Label</label>
    <input type="text" name="field" id="field"
        class="form-control form-control-sm @error('field') is-invalid @enderror"
        value="{{ old('field') }}">
    @error('field')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

### Forbidden Classes

| Class | Replace With |
|---|---|
| `bg-white` | `bg-body-tertiary` / `bg-transparent` |
| `bg-light` | `bg-body-tertiary` |
| Standalone Back button | Back link inside content-header description |
| `card-body` without `p-4` | Always `card-body.p-4` |

## Related

- [UI Architecture](./ui-architecture.md)
- [UI Authorization Rule](./ui-authorization.md)