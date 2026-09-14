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
| Status badge | `success`/`warning`/`danger`/`info` |

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
| Overlay | `overlay` |
| Dialog background | `surface` |
| Border | `border` |

### Navigation

| Element | Token |
|---------|-------|
| Sidebar background | `surface` |
| Nav item active | `primary` |
| Nav item hover | `surface-alt` |
| Breadcrumb separator | `text-muted` |

### Pagination

| Element | Token |
|---------|-------|
| Active page | `primary` |
| Hover page | `surface-alt` |
| Border | `border` |

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

## Related

- [UI Architecture](./ui-architecture.md)
- [UI Authorization Rule](./ui-authorization.md)