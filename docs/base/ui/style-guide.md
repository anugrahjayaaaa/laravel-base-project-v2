# V2 UI Style Guide

> **Reference**: Laravel Base Project **v1** is the visual reference/inspiration for this guide. V2 is its own implementation with its own architecture, folder structure, and Blade partials. Do not copy v1 code directly; adapt its visual qualities to the V2 AdminLTE 4.9.1 + Bootstrap 5.3 foundation.

## 1. Overall Visual Direction

- **Simple, modern, clean, professional.** Avoid ornamental decoration.
- **Neutral color palette.** Surfaces are light by default (`#ffffff` / `#f5f6f8`). Dark mode shifts to `#0f1115` / `#171a21`.
- **Primary accent**: `--lbp-primary: #6366f1` (indigo-500).
- **Typography**: Instrument Sans (system fallback), 14px body, 1.55 line-height.
- **Corner radius**: Cards at `12px` (`--lbp-radius`), buttons and small elements at `8px` (`--lbp-radius-sm`).
- **Density**: Comfortable — admin-appropriate spacing, not cramped. Laptop/desktop-first; mobile secondary.

## 2. Layout Density & Spacing

- Use Bootstrap's `container-fluid` inside `.app-content` for full-width content areas.
- Card body padding: default Bootstrap (`1rem`). Page container padding: `py-3` (`.75rem` top/bottom).
- Grid gaps use `g-4` for page-level sections, `g-3` for form rows.

## 3. Typography Hierarchy

| Element | Style |
|---|---|
| Page title (`.page-title`) | 600 weight, 1.35rem |
| Card title (`.card-title`) | 600 weight, 1rem |
| Page description (`.page-description`) | muted, 0.85rem |
| Table header | uppercase, 0.7rem, letter-spacing 0.05em, 600 weight, muted |
| Body text | 14px, 1.55 line-height |

## 4. Header (`.app-header`)

- AdminLTE `.app-header.navbar` with `.bg-body` background.
- **Height**: 3.5rem (`--lbp-app-chrome-height`) — shared CSS variable with `.sidebar-brand` so header and sidebar brand area align exactly.
- Contains only: sidebar toggle, notification icon, theme toggle, user dropdown. No page title or search.
- Sidebar toggle button: chevron icon, `data-lte-toggle="sidebar"`, visible on desktop only (`d-none d-md-inline-flex`).
- Icons: `fas` (solid) for all interactive elements, `far` for notification bell. Consistent 1x sizing.
- Right elements use `ms-auto` for user dropdown to push to far right.
- Neutralize AdminLTE's fixed 2.5rem nav-link height — use `height: auto; display: flex; align-items: center`.
- User dropdown: uses Bootstrap 5 `dropdown-menu dropdown-menu-end` with Profile / Settings / Logout.

## 5. Sidebar (`.app-sidebar`)

- Width: 250px on desktop (via `--lte-sidebar-width`). Collapsible via AdminLTE `sidebar-mini` body class + `sidebar-collapse` toggle.
- Logo/brand section at top with bottom border — uses AdminLTE `.sidebar-brand` class. Contains a simple icon (cube) + `brand-text`. Collapses to icon-only in mini mode.
- Navigation: `.nav.sidebar-menu.flex-column` with `.nav-link .nav-item` items. Use `data-lte-toggle="treeview"` for nested menus.
- **Active state**: `color-mix(in srgb, var(--lbp-primary) 16%, transparent)` background, primary color text, 600 weight.
- **Group headers**: `.nav-header` — uppercase, 0.7rem, letter-spacing 0.08em, muted, opacity 0.8, padding `1rem .75rem .25rem`.
- Hover: surface-2 background, text color shift.
- Collapse behavior: AdminLTE toggles `.sidebar-collapse` on body. Expanded desktop shows full labels; collapsed shows icon-only with labels hidden. Hover-expand is built into AdminLTE's `.sidebar-mini.sidebar-collapse` CSS.
- Navigation links use `Route::has()` guard before generating route URLs — no RouteNotFoundException on missing routes.

## 6. Content / Page Structure

```
.app-main > .app-content > .container-fluid
  .content-header (page title + description + search)
  .row (content cards / tables)
```

- **Content header** (`.content-header`): flexbox row with page title, description, and search on the right (`ms-auto`).
- Page title: `.page-title` (600 weight, 1.35rem).
- Page description: `.page-description` (muted, 0.85rem).
- Search: `.feature-search` form with `.input-group` — placed in content header, NOT in the app header.
- Content wrapped in `.card.border-0.shadow-sm` for elevated sections.

## 7. Footer (`.app-footer`)

- Fixed height (3.5rem / `--lbp-app-chrome-height`), `.border-top`, minimal text.
- Left: copyright. Right: Settings link (if route exists).

## 8. Button Hierarchy

| Type | Class |
|---|---|
| Primary action | `.btn.btn-primary` |
| Secondary (outline) | `.btn.btn-outline-secondary` |
| Danger | `.btn.btn-danger` |
| Warning | `.btn.btn-warning` |

- All buttons: `border-radius: 8px` (`--lbp-radius-sm`), `font-weight: 500`.
- Focus ring: `2px solid var(--lbp-primary)` on `:focus-visible`.

## 9. Form Controls

- Standard Bootstrap 5 form controls.
- Input height: default (not AdminLTE's fixed overrides).
- Labels: standard Bootstrap `.form-label`.

## 10. Search / Filter

- Search uses a `<form>` with `.input-group`.
- Placement: `.content-header .feature-search` — always in the page content header area, not in the app header.
- Icon prefix inside `.input-group-text`.

## 11. Tables

- `.table` with transparent background, muted text.
- Cell padding: `.65rem 1rem` (slightly tighter than Bootstrap default).
- Header: uppercase, 0.7rem, letter-spacing 0.05em, muted, border-bottom.
- Hover rows: `var(--lbp-surface-2)` background.
- Sortable headers use the `sortable-th` Blade component.

## 12. Status / Badges

Use `bg-*-subtle` classes (soft semantic backgrounds):

| Status | Class |
|---|---|
| Primary | `.bg-primary-subtle` |
| Success | `.bg-success-subtle` |
| Danger | `.bg-danger-subtle` |
| Secondary (muted) | `.bg-secondary-subtle` |

All use `color-mix(in srgb, var(--lbp-*) 16%, transparent)` for soft tint.

## 13. Confirmation Modal

- Single reusable modal at `layouts.partials.modals.confirmation`.
- Variants: `danger` (red header), `warning` (amber), `info` (blue).
- Header background: `color-mix(in srgb, var(--lbp-*) 12%, transparent)`.
- Cancel button: `.btn.btn-secondary`. Action button: variant-colored.

## 14. Alerts / Feedback

- Use Bootstrap `.alert` with `.bg-*-subtle` backgrounds.
- `border-0` for clean appearance.
- Auto-dismiss supported via `.btn-close` + `data-bs-dismiss="alert"`.

## 15. Empty / Loading / Error States

- Shared Blade components under `resources/views/components/ui/`:
  - `empty-state` — icon + message, centered in card.
  - `loading-state` — spinner + "Loading..." text.
  - `error-state` — icon + error message, muted color.
- All use `min-height: 200px` for consistent spacing.

## 16. Dark / Light Theme Behavior

- **Default**: system preference (`prefers-color-scheme: dark`).
- **Override**: user clicks theme toggle → saves to `localStorage.theme`.
- **Persistence**: `localStorage` survives page refreshes.
- **Initial render**: inline `<script>` in `<head>` reads `localStorage` or `matchMedia` and sets `data-bs-theme` before CSS loads — no flash of wrong theme.
- **Toggle**: icon swaps (`fa-moon` in light mode, `fa-sun` in dark mode). Updates `data-bs-theme`, persists to `localStorage`. Icon-only — no text labels.
- AdminLTE 4 + Bootstrap 5.3 native `[data-bs-theme="dark"]` support handles the rest.

## 17. Responsive Behavior

- Desktop: sidebar visible, header horizontal, content in `.container-fluid`.
- Mobile (md breakpoint): header icons collapse (some hidden via `d-none d-md-inline-flex`), sidebar becomes overlay.
- Bootstrap grid breakpoints: sm (640px), md (768px), lg (1024px), xl (1280px).

## 18. General Consistency Rules

- All shared layout elements live under `resources/views/layouts/` — no duplication.
- Partials: `resources/views/layouts/partials/` — named `header`, `sidebar`, `footer` (no `app-*` prefix).
- Modals: `resources/views/layouts/partials/modals/`.
- UI components: `resources/views/components/ui/`.
- Blade component attributes use `$attributes->merge()` with sensible defaults.
- All views use `@error` + `$message` for validation feedback.
- No i18n in views — text is static.
